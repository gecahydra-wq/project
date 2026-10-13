<?php

namespace App\Support;

class DurationLabel
{
    public static function fromMinutes(int $minutes): string
    {
        $remaining = max(1, $minutes);
        $parts = [];

        foreach ([1440 => 'day', 60 => 'hour', 1 => 'minute'] as $unitMinutes => $unit) {
            $count = intdiv($remaining, $unitMinutes);
            if ($count > 0) {
                $parts[] = $count.' '.$unit.($count === 1 ? '' : 's');
                $remaining %= $unitMinutes;
            }
        }

        return implode(' ', $parts);
    }
}
