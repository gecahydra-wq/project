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
     * phrase that context naturally, never to invent FishMarket facts or
     * ask clarifying questions about how the system works.
     */
    public function answer(string $prompt, string $language = 'English', ?User $user = null, array $history = []): string
    {
        $this->lastLanguage = $language;
        $this->lastSubject = null;
        $this->lastCategory = null;
        $this->lastWasFallback = false;
        $role = $user->role ?? 'buyer';

        if ($user) {
            $language = AiLanguageDetector::detect($prompt);
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
     * Answers a question that's already been grounded in a fact -- either a
     * live database result (AiDataQueryResolver), a ranked recommendation
     * (AiRecommendationEngine), or the app's own scripted knowledge for a
     * recognized topic (AiIntentClassifier). Gemini is grounded with that
     * fact via systemInstruction and told never to invent anything beyond
     * it or ask the user clarifying questions about how FishMarket works;
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

        $apiKey = config('services.gemini.api_key');

        if (empty($apiKey)) {
            return $fallback();
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

            $systemInstruction = "You are the FishMarket AI assistant, an expert built specifically for this Fisheries Marketplace application -- not a general-purpose chatbot. Use ONLY the following application knowledge/data to answer -- never invent or estimate anything beyond it, and never ask the user a clarifying question about how FishMarket works (e.g. what item they mean) since this context already fully describes it. Respond fluently in {$language} (or whichever language the user's message is predominantly written in, if it mixes languages), concisely and naturally.\n\nCONTEXT: {$context['context']}";

            $response = Http::timeout(30)->post($url, [
                'contents' => $contents,
                'systemInstruction' => ['parts' => [['text' => $systemInstruction]]],
            ]);

            if ($response->successful()) {
                $result = $response->json('candidates.0.content.parts.0.text');

                return $result ?: $fallback();
            }

            Log::warning('Gemini API failed: '.$response->status());

            return $fallback();
        } catch (\Throwable $e) {
            Log::error('Gemini API error: '.$e->getMessage());

            return $fallback();
        }
    }
}
