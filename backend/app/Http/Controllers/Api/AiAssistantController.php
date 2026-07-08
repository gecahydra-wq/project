<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiConversation;
use App\Services\GeminiService;
use Illuminate\Http\Request;

class AiAssistantController extends Controller
{
    public function ask(Request $request, GeminiService $gemini)
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'exists:users,id'],
            'language' => ['required', 'in:English,Tagalog,Bisaya'],
            'question' => ['required', 'string'],
        ]);

        $answer = $gemini->answer($data['question'], $data['language']);

        $conversation = AiConversation::create([
            'user_id' => $request->user()?->id ?? $data['user_id'] ?? null,
            'language' => $data['language'],
            'message' => $data['question'],
            'response' => $answer,
        ]);

        return response()->json($conversation, 201);
    }
}
