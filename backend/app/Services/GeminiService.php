<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    public function answer(string $prompt, string $language = 'English'): string
    {
        $apiKey = env('GEMINI_API_KEY');

        if (empty($apiKey)) {
            return $this->getFallbackResponse($prompt, $language);
        }

        try {
            $model = env('GEMINI_MODEL', 'gemini-2.0-flash');
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

            $languageInstruction = '';
            if ($language === 'Tagalog') {
                $languageInstruction = 'Sagutin ito sa Tagalog: ';
            } elseif ($language === 'Bisaya') {
                $languageInstruction = 'Sagutin ito sa Bisaya/Cebuano: ';
            }
            
            $enhancedPrompt = $languageInstruction . $prompt;

            $response = Http::timeout(30)->post($url, [
                'contents' => [
                    ['parts' => [['text' => $enhancedPrompt]]]
                ]
            ]);

            if ($response->successful()) {
                $result = $response->json('candidates.0.content.parts.0.text') ?? 'No response generated.';
                Log::info("Gemini API success");
                return $result;
            }

            Log::warning('Gemini API failed: ' . $response->status());
            return $this->getFallbackResponse($prompt, $language);
        } catch (\Exception $e) {
            Log::error('Gemini API error: ' . $e->getMessage());
            return $this->getFallbackResponse($prompt, $language);
        }
    }

    private function getFallbackResponse(string $prompt, string $language = 'English'): string
    {
        $promptLower = strtolower($prompt);
        
        if (str_contains($promptLower, 'beginner') || str_contains($promptLower, 'good species')) {
            if ($language === 'Tagalog') {
                return "Para sa mga baguhan, ang Tilapia ay perpekto. Matibay ito, tumatagal sa masamang kondisyon ng tubig, mabilis lumaki (6 buwan harvest), at may mataas na demand sa market.";
            } elseif ($language === 'Bisaya') {
                return "Para sa mga baguhan, ang Tilapia ay perpekto. Matibay ito, tumatagal sa masamang kondisyon ng tubig, mabilis lumaki (6 buwan harvest), at may mataas na demand sa market.";
            }
            return "For beginners, Tilapia is perfect. It's hardy, tolerates poor water, grows fast (6 months harvest), and has high market demand. Start with 1-2 inch fingerlings.";
        }

        if (str_contains($promptLower, 'fingerling') || str_contains($promptLower, 'fry')) {
            if ($language === 'Tagalog') {
                return "Ang fingerlings ay mga batang isda, 1-2 pulgada. Kailangan nila ng mataas na protein (35-40%), temperatura 25-30°C, at mababang stocking density.";
            } elseif ($language === 'Bisaya') {
                return "Ang fingerlings ay mga batan nga isda, 1-2 pulgada. Kinahanglan nila ng taas na protein (35-40%), temperatura 25-30°C, at mababang stocking density.";
            }
            return "Fingerlings are young fish, 1-2 inches. They need high-protein diet (35-40%), temperature 25-30°C, and low stocking density.";
        }

        if (str_contains($promptLower, 'water') || str_contains($promptLower, 'quality')) {
            if ($language === 'Tagalog') {
                return "Bantayan ang tubig: Oxygen (6-8 mg/L), pH (6.5-7.5), Ammonia (<0.05), Temperatura (25-30°C). Baguhin ang 20-30% weekly. Poor quality causes disease.";
            } elseif ($language === 'Bisaya') {
                return "Bantayan ang tubig: Oxygen (6-8 mg/L), pH (6.5-7.5), Ammonia (<0.05), Temperatura (25-30°C). Baguhin ang 20-30% weekly. Poor quality causes disease.";
            }
            return "Monitor water: Oxygen (6-8 mg/L), pH (6.5-7.5), Ammonia (<0.05 mg/L), Temperature (25-30°C). Change 20-30% weekly.";
        }

        if ($language === 'Tagalog') {
            return "Handa akong tumulong sa farming! Magtanong tungkol sa species, water quality, feeding, diseases, o ROI. Ang Gemini API ay offline ngayon, pero may offline mode support.";
        } elseif ($language === 'Bisaya') {
            return "Handa akong tumulong sa farming! Magtanong tungkol sa species, water quality, feeding, diseases, o ROI. Ang Gemini API ay offline ngayon, pero may offline mode support.";
        }
        return "🌾 I'm ready to help with aquaculture! Ask about species, water quality, feeding, diseases, or ROI. Gemini API is offline but fallback mode is active.";
    }
}