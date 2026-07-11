<?php

namespace App\Support;

use Illuminate\Support\Carbon;

class AnalyticsPeriod
{
    public const PERIODS = ['daily', 'weekly', 'monthly', 'yearly'];

    /**
     * Resolve a requested period into a concrete date range + bucket unit.
     * Unknown/missing periods fall back to 'monthly' rather than erroring, since
     * this only ever drives a read-only analytics view.
     */
    public static function resolve(?string $period): array
    {
        $period = in_array($period, self::PERIODS, true) ? $period : 'monthly';
        $now = Carbon::now();

        [$start, $end, $unit] = match ($period) {
            'daily' => [$now->copy()->subDays(13)->startOfDay(), $now->copy()->endOfDay(), 'day'],
            'weekly' => [$now->copy()->subWeeks(11)->startOfWeek(), $now->copy()->endOfWeek(), 'week'],
            'yearly' => [$now->copy()->subYears(4)->startOfYear(), $now->copy()->endOfYear(), 'year'],
            default => [$now->copy()->subMonths(11)->startOfMonth(), $now->copy()->endOfMonth(), 'month'],
        };

        return ['period' => $period, 'start' => $start, 'end' => $end, 'unit' => $unit];
    }

    protected static function bucketKey(Carbon $date, string $unit): string
    {
        return match ($unit) {
            'day' => $date->format('Y-m-d'),
            'week' => $date->format('o-\WW'),
            'year' => $date->format('Y'),
            default => $date->format('Y-m'),
        };
    }

    protected static function bucketLabel(Carbon $date, string $unit): string
    {
        return match ($unit) {
            'day' => $date->format('M j'),
            'week' => 'Wk '.$date->format('W'),
            'year' => $date->format('Y'),
            default => $date->format('M Y'),
        };
    }

    protected static function step(Carbon $date, string $unit): Carbon
    {
        return match ($unit) {
            'day' => $date->addDay(),
            'week' => $date->addWeek(),
            'year' => $date->addYear(),
            default => $date->addMonth(),
        };
    }

    /**
     * Zero-fills a chronological bucket series across [$start, $end] and folds
     * $rows (each exposing $dateField and $amountField) into a count + amount
     * per bucket, so a period with no activity still renders as a flat line
     * instead of a gap.
     */
    public static function bucketize(Carbon $start, Carbon $end, string $unit, iterable $rows, string $dateField = 'created_at', string $amountField = 'total_amount'): array
    {
        $buckets = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $key = self::bucketKey($cursor, $unit);
            $buckets[$key] ??= ['key' => $key, 'label' => self::bucketLabel($cursor, $unit), 'count' => 0, 'amount' => 0.0];
            $cursor = self::step($cursor, $unit);
        }

        foreach ($rows as $row) {
            $key = self::bucketKey(Carbon::parse($row->{$dateField}), $unit);
            if (! isset($buckets[$key])) {
                continue;
            }
            $buckets[$key]['count']++;
            $buckets[$key]['amount'] += (float) $row->{$amountField};
        }

        return array_values(array_map(fn ($bucket) => [
            'key' => $bucket['key'],
            'label' => $bucket['label'],
            'count' => $bucket['count'],
            'amount' => round($bucket['amount'], 2),
        ], $buckets));
    }
}
