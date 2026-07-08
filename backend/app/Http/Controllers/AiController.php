<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\GeminiService;

class AiController extends Controller
{
    public function ask(Request $request, GeminiService $gemini)
    {
        $validated = $request->validate([
            'prompt' => 'required|string|max:1000'
        ]);

        $answer = $gemini->askQuestion($validated['prompt']);

        return response()->json([
            'answer' => $answer
        ]);
    }
}
