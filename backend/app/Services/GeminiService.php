<?php

namespace App\Services;

use App\Models\User;
use App\Support\AiDataQueryResolver;
use App\Support\AiIntentClassifier;
use App\Support\AiLanguageDetector;
use App\Support\AiRecommendationEngine;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    /**
     * Set during answer() so the caller (AiAssistantController) can persist
     * the resolved language/data-subject alongside the conversation row
     * without changing answer()'s string return type -- every existing
     * caller/test that treats the return value as a plain string keeps
     * working unmodified.
     */
    private ?string $lastLanguage = null;

    private ?string $lastSubject = null;

    /**
     * Aggregate-only telemetry for the AI Usage Dashboard, set during answer()
     * exactly like $lastLanguage/$lastSubject above so no existing caller has
     * to change. $lastCategory is the resolved intent bucket; $lastWasFallback
     * is true whenever the answer was served by the app's own logic (scripted
     * fallback, greeting, or off-topic refusal) rather than a live Gemini
     * generation. No message content is ever retained here.
     */
    private ?string $lastCategory = null;

    private bool $lastWasFallback = false;

    /**
     * $user and $history are optional and only ever populated by the real
     * controller -- every pre-existing 2-arg call site (all current tests)
     * leaves $user null and falls straight through to the scripted
     * classifier/knowledge-base flow using the 'buyer' default role,
     * byte-for-byte unchanged.
     *
     * Resolution order: (1) live database facts scoped to the user's role
     * (AiDataQueryResolver), (2) ranked, scored recommendations scoped to
     * the user's role (AiRecommendationEngine), (3) the app's own scripted
     * knowledge base for a recognized topic (AiIntentClassifier), (4) a
     * greeting, or (5) a polite off-topic refusal. Cases 1-3 all ground
     * Gemini with a context object built from real data or real app
     * knowledge via answerWithContext() -- Gemini is only ever asked to
     * phrase that context naturally, never to invent AbaiMarket facts or
     * ask clarifying questions about how the system works.
     */
    public function answer(string $prompt, string $language = 'English', ?User $user = null, array $history = [], ?string $preferredLanguage = null): string
    {
        $this->lastLanguage = $language;
        $this->lastSubject = null;
        $this->lastCategory = null;
        $this->lastWasFallback = false;
        $role = $user->role ?? 'buyer';

        if ($user) {
            // An explicit choice from the language picker wins over sniffing
            // the message. Auto-detection stays the default (and the only
            // behaviour for callers that pass nothing), but it can only guess
            // from the words used -- so a Cebuano speaker asking a
            // one-word/English-loanword question would otherwise get English
            // back. See AiLanguageDetector.
            $language = $preferredLanguage ?? AiLanguageDetector::detect($prompt);
            $this->lastLanguage = $language;

            $previousSubject = collect($history)->last()?->data_subject;

            $dataResult = AiDataQueryResolver::resolve($prompt, $user, $previousSubject);
            if ($dataResult !== null) {
                $this->lastSubject = $dataResult['subject'];
                $this->lastCategory = 'Data Query';

                return $this->answerWithContext($prompt, $dataResult, $language, $history);
            }

            $recommendationResult = AiRecommendationEngine::resolve($prompt, $user, $previousSubject);
            if ($recommendationResult !== null) {
                $this->lastSubject = $recommendationResult['subject'];
                $this->lastCategory = 'Recommendation';

                return $this->answerWithContext($prompt, $recommendationResult, $language, $history);
            }
        }

        $intent = AiIntentClassifier::classify($prompt);

        // Off-topic messages are refused before ever reaching the model, so a
        // trivia/politics/sports/programming/homework question can never get
        // a fabricated answer even if the live API is reachable.
        if ($intent['category'] === 'Unknown') {
            $this->lastCategory = 'Off-topic';
            $this->lastWasFallback = true;

            return AiIntentClassifier::offTopicResponse($language);
        }

        if ($intent['category'] === 'Greeting') {
            $this->lastCategory = 'Greeting';
            $this->lastWasFallback = true;

            return AiIntentClassifier::greetingResponse($language, $role);
        }

        $this->lastCategory = $intent['category'];

        // Fish-farming questions are the one category where the app has no
        // authoritative answer to protect. Nothing about AbaiMarket is at
        // stake in "why are my fingerlings gasping at the surface?", so
        // confining Gemini to a two-sentence scripted blurb only made the
        // assistant useless to the farmers it exists for. These get real
        // aquaculture guidance instead; every other category stays strictly
        // grounded in app knowledge or live data below.
        if ($intent['category'] === 'Fish Care') {
            return $this->answerAsFarmingAdvisor($prompt, $intent['topic'], $role, $language, $history);
        }

        $topicResult = [
            'subject' => null,
            'context' => AiIntentClassifier::topicContext($intent['topic'], $role),
            'fallback' => AiIntentClassifier::topicFallback($intent['topic'], $role),
        ];

        return $this->answerWithContext($prompt, $topicResult, $language, $history);
    }

    public function lastLanguage(): ?string
    {
        return $this->lastLanguage;
    }

    public function lastSubject(): ?string
    {
        return $this->lastSubject;
    }

    public function lastCategory(): ?string
    {
        return $this->lastCategory;
    }

    public function lastWasFallback(): bool
    {
        return $this->lastWasFallback;
    }

    /**
     * Answers an aquaculture question using Gemini's own domain knowledge,
     * scoped to Philippine small-scale fish farming.
     *
     * This is the deliberate exception to the "never say anything the app
     * didn't tell you" rule that governs every other path. The distinction is
     * what is at risk of being wrong: an invented withdrawal fee or stock
     * count is a defect in AbaiMarket, while general advice on pond oxygen is
     * ordinary domain knowledge the model genuinely has. The instruction below
     * therefore opens up fish husbandry and, in the same breath, forbids the
     * model from asserting anything about AbaiMarket itself -- so the grounding
     * guarantee that matters is preserved exactly.
     *
     * $topic is the curated Fish Care topic when one matched (its text is
     * passed through as extra grounding, so answers stay consistent with what
     * the app already teaches) and null for an open question rescued by
     * AiIntentClassifier::looksLikeFarmingQuestion().
     */
    private function answerAsFarmingAdvisor(string $prompt, ?array $topic, string $role, string $language, array $history): string
    {
        $fallbackText = $topic
            ? AiIntentClassifier::topicFallback($topic, $role)
            : AiIntentClassifier::generalFishCareFallback();

        $appNote = $topic
            ? "\n\nAbaiMarket's own guidance on this topic, which your answer must stay consistent with: ".AiIntentClassifier::topicContext($topic, $role)
            : '';

        $systemInstruction = <<<TXT
        You are the AbaiMarket AI assistant, answering a fish-farming question for a small-scale fish farmer in the Philippines.

        Answer using your own aquaculture knowledge. Be practical and specific -- give numbers, ranges and concrete steps the farmer can act on today (water parameters, feeding rates, stocking densities, treatment steps), not vague encouragement.

        Rules:
        - Write in {$language}, or in whichever language the farmer's message is predominantly written in if it mixes languages. Use plain words a farmer without formal training will understand; briefly explain any technical term you use.
        - Keep it short: about 3-6 sentences, or a few short bullets. This is a chat window, not an article.
        - Assume local conditions: tropical climate, earthen ponds, cages and tanks, species such as tilapia, bangus, hito and shrimp, metric units and Philippine pesos.
        - When the farmer describes a problem, give the most likely causes first, then what to do immediately, then how to prevent it next time.
        - For a serious or spreading die-off, tell them to contact BFAR or their local fisheries technician as well -- do not let them rely on a chat answer alone.
        - Never recommend banned chemicals, and never give antibiotic dosing. Point them to a fisheries technician for anything requiring medication.
        - If you are genuinely unsure, say so plainly instead of guessing.
        - Do NOT state facts about the AbaiMarket app itself -- its fees, features, prices, stock or policies. If they ask about those, tell them to ask about it directly and you will answer from the system.{$appNote}
        TXT;

        $answer = $this->generate($prompt, $systemInstruction, $history);

        if ($answer === null) {
            $this->lastWasFallback = true;

            return $fallbackText[$language] ?? $fallbackText['English'];
        }

        return $answer;
    }

    /**
     * Answers a question that's already been grounded in a fact -- either a
     * live database result (AiDataQueryResolver), a ranked recommendation
     * (AiRecommendationEngine), or the app's own scripted knowledge for a
     * recognized topic (AiIntentClassifier). Gemini is grounded with that
     * fact via systemInstruction and told never to invent anything beyond
     * it or ask the user clarifying questions about how AbaiMarket works;
     * recent conversation turns are threaded in as multi-turn contents so
     * follow-ups ("how many are in Cordova?") read naturally. On any
     * provider failure, $context['fallback'] is used instead of a generic
     * error, since every caller of this method already has a real answer
     * ready even when Gemini is down.
     */
    private function answerWithContext(string $prompt, array $context, string $language, array $history): string
    {
        // Marks this answer as a fallback (Gemini unavailable/failed) for the
        // usage telemetry, then returns the app's own scripted answer.
        $fallback = function () use ($context, $language) {
            $this->lastWasFallback = true;

            return $context['fallback'][$language] ?? $context['fallback']['English'];
        };

        $systemInstruction = "You are the AbaiMarket AI assistant, an expert built specifically for this Fisheries Marketplace application -- not a general-purpose chatbot. Use ONLY the following application knowledge/data to answer -- never invent or estimate anything beyond it, and never ask the user a clarifying question about how AbaiMarket works (e.g. what item they mean) since this context already fully describes it. Respond fluently in {$language} (or whichever language the user's message is predominantly written in, if it mixes languages), concisely and naturally.\n\nCONTEXT: {$context['context']}";

        return $this->generate($prompt, $systemInstruction, $history) ?? $fallback();
    }

    /**
     * The single place this class talks to Gemini. Returns the generated text,
     * or null when the provider is unconfigured, failed, or returned nothing --
     * every caller already has its own real answer to fall back to, so a null
     * here is a routine outcome rather than an error to surface to the user.
     */
    private function generate(string $prompt, string $systemInstruction, array $history): ?string
    {
        $apiKey = config('services.gemini.api_key');

        if (empty($apiKey)) {
            return null;
        }

        try {
            $model = config('services.gemini.model', 'gemini-2.5-flash');
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

            $contents = [];
            foreach ($history as $turn) {
                $contents[] = ['role' => 'user', 'parts' => [['text' => $turn->message]]];
                $contents[] = ['role' => 'model', 'parts' => [['text' => $turn->response]]];
            }
            $contents[] = ['role' => 'user', 'parts' => [['text' => $prompt]]];

            $response = Http::timeout(30)->post($url, [
                'contents' => $contents,
                'systemInstruction' => ['parts' => [['text' => $systemInstruction]]],
            ]);

            if ($response->successful()) {
                return $response->json('candidates.0.content.parts.0.text') ?: null;
            }

            Log::warning('Gemini API failed: '.$response->status());

            return null;
        } catch (\Throwable $e) {
            Log::error('Gemini API error: '.$e->getMessage());

            return null;
        }
    }
}
