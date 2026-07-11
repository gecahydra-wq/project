<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiConversation;
use App\Models\AiUsageEvent;
use App\Services\GeminiService;
use Illuminate\Http\Request;

class AiAssistantController extends Controller
{
    public function ask(Request $request, GeminiService $gemini)
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:2000'],
        ]);

        $user = $request->user();

        // Recent turns (oldest first) give the model multi-turn context for
        // natural follow-ups; the most recent row's data_subject lets the
        // resolver carry an implicit subject across turns (e.g. "how many
        // sellers?" then "how many are in Cordova?").
        $history = AiConversation::where('user_id', $user->id)
            ->orderByDesc('id')
            ->take(6)
            ->get()
            ->sortBy('id')
            ->values();

        $startedAt = microtime(true);
        $answer = $gemini->answer($data['question'], 'English', $user, $history->all());
        $responseTimeMs = (int) round((microtime(true) - $startedAt) * 1000);

        $conversation = AiConversation::create([
            'user_id' => $user->id,
            'language' => $gemini->lastLanguage(),
            'message' => $data['question'],
            'response' => $answer,
            'data_subject' => $gemini->lastSubject(),
        ]);

        // Aggregate-only usage telemetry for the Super Admin AI Usage Dashboard.
        // Records role/category/fallback/response-time -- never the message or
        // the answer, which already live on the AiConversation row above.
        AiUsageEvent::create([
            'user_id' => $user->id,
            'role' => $user->role,
            'category' => $gemini->lastCategory(),
            'was_fallback' => $gemini->lastWasFallback(),
            'response_time_ms' => $responseTimeMs,
        ]);

        return response()->json($conversation, 201);
    }

    /**
     * The buyer's own recent AI conversation history, oldest first, so the
     * widget can restore the chat log after a reload/re-mount instead of
     * only ever showing what happened since the widget was last opened.
     */
    public function history(Request $request)
    {
        return response()->json(
            AiConversation::where('user_id', $request->user()->id)
                ->orderByDesc('id')
                ->take(50)
                ->get()
                ->sortBy('id')
                ->values()
        );
    }
}
