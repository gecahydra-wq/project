<?php

namespace App\Support;

class AiLanguageDetector
{
    /**
     * Marker words unique enough to each language that their presence is a
     * strong signal, matched with word boundaries so short markers don't
     * false-match inside unrelated words. Checked in this order -- Bisaya
     * and Tagalog share several Austronesian function words, so a message
     * is classified by whichever list scores the most matches, not by the
     * first list checked.
     */
    private const MARKERS = [
        'Bisaya' => ['unsa', 'asa', 'pila', 'kanus-a', 'dili', 'mao', 'nimo', 'nako', 'kanako', 'kaninyo',
            'kamo', 'kami', 'sila', 'unta', 'maayo', 'kaayo', 'ngano', 'aduna', 'wala', 'karon', 'buhaton',
            'palihug', 'salamat kaayo', 'kumusta', 'musta'],
        'Tagalog' => ['ang', 'ng', 'mga', 'paano', 'magkano', 'saan', 'hindi', 'bakit', 'sino', 'ako', 'ikaw',
            'siya', 'natin', 'nila', 'gusto', 'kailangan', 'salamat', 'kumusta', 'oo', 'meron', 'wala'],
    ];

    /**
     * Score a message against each language's marker list and return the
     * best match, defaulting to English when nothing scores (or when the
     * top score is a tie), since English is the assistant's safe baseline.
     */
    public static function detect(string $message): string
    {
        $lower = strtolower($message);

        $scores = ['Bisaya' => 0, 'Tagalog' => 0];
        foreach (self::MARKERS as $language => $markers) {
            foreach ($markers as $marker) {
                if (preg_match('/\b'.preg_quote($marker, '/').'\b/i', $lower)) {
                    $scores[$language]++;
                }
            }
        }

        if ($scores['Bisaya'] === 0 && $scores['Tagalog'] === 0) {
            return 'English';
        }

        return $scores['Bisaya'] >= $scores['Tagalog'] ? 'Bisaya' : 'Tagalog';
    }
}
