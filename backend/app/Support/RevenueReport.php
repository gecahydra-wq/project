<?php

namespace App\Support;

use App\Models\LguWithdrawalRequest;
use App\Models\Settlement;
use App\Models\WithdrawalRequest;
use Illuminate\Support\Carbon;

/**
 * Revenue figures for LGU and Super Admin reporting (see
 * App\Support\CommissionCalculator and LguController::approveEarnings).
 *
 * Seller Share and LGU Share both realize at LGU-approval/settlement time --
 * the instant a Settlement row is created, that money is "earned" for the
 * seller (Pending -> Available Balance) and for the LGU (informational
 * revenue), and so does the Platform Share (4% since 2026-10-09).
 *
 * Platform Revenue therefore has two sources, added together everywhere:
 *  - settlements.platform_share, dated by settled_at (the current model);
 *  - platform_fee on PAID seller withdrawals, dated by paid_at -- the 6%
 *    payout fee charged before 2026-10-09. New withdrawals carry a 0 fee, so
 *    this only ever adds the old income back in.
 */
class RevenueReport
{
    /**
     * LGU dashboard cards -- scoped to one municipality. Never includes
     * gross_amount; an LGU only ever sees their own municipality's cut,
     * never the platform's or another municipality's. available_balance and
     * total_withdrawn come from App\Support\LguWallet -- the same wallet
     * math the LGU Wallet page and AI Assistant use -- so this card set and
     * the wallet page can never disagree.
     */
    public static function municipalityCards(int $municipalityId): array
    {
        $base = Settlement::where('municipality_id', $municipalityId)->notReversed();

        $today = (float) (clone $base)->whereDate('settled_at', now()->toDateString())->sum('lgu_share');
        $monthly = (float) (clone $base)->whereBetween('settled_at', [now()->startOfMonth(), now()->endOfMonth()])->sum('lgu_share');
        $total = (float) (clone $base)->sum('lgu_share');
        $orders = (clone $base)->count();

        return [
            'today_revenue' => round($today, 2),
            'monthly_revenue' => round($monthly, 2),
            'total_revenue' => round($total, 2),
            'total_completed_orders' => $orders,
            'average_revenue_per_order' => $orders > 0 ? round($total / $orders, 2) : 0,
            'available_balance' => LguWallet::availableBalance($municipalityId),
            'total_withdrawn' => LguWallet::withdrawnAmount($municipalityId),
        ];
    }

    /**
     * Super Admin dashboard cards -- platform-wide. Platform Revenue is the
     * Platform Share of settlements plus the old payout fees (see class docblock).
     * gross_marketplace_revenue is a different concept entirely -- the full
     * buyer payment total across every settled order -- and stays
     * settlement-based since it's not the platform's own earned income.
     */
    public static function platformCards(): array
    {
        $today = self::realizedPlatformRevenueTotal(now()->startOfDay(), now()->endOfDay());
        $monthly = self::realizedPlatformRevenueTotal(now()->startOfMonth(), now()->endOfMonth());
        $total = (float) Settlement::notReversed()->sum('platform_share')
            + (float) WithdrawalRequest::where('status', 'paid')->sum('platform_fee');

        $gross = (float) Settlement::notReversed()->sum('gross_amount');
        $orders = Settlement::notReversed()->count();

        return [
            'today_platform_revenue' => round($today, 2),
            'monthly_platform_revenue' => round($monthly, 2),
            'total_platform_revenue' => round($total, 2),
            'gross_marketplace_revenue' => round($gross, 2),
            'total_settled_orders' => $orders,
            'average_platform_revenue_per_order' => $orders > 0 ? round($total / $orders, 2) : 0,
        ];
    }

    /**
     * The platform's own money, for the Platform Earnings tab: the cards above,
     * plus a per-settled-order ledger of the platform share so every peso of
     * platform revenue can be traced back to the order that produced it.
     *
     * A refunded order is listed as reversed rather than dropped, because a
     * total that quietly shrinks is harder to explain than one that says why it
     * moved -- and the reversal is a decision, not a correction.
     *
     * Read-only by design. The platform share is derived from settlements; this
     * app holds no platform balance and issues no platform withdrawals, so
     * there is nothing on this page to pay out.
     */
    public static function platformRevenueLedger(int $limit = 200): array
    {
        $ledger = Settlement::with(['order', 'sellerProfile', 'municipality', 'reversedBy'])
            ->orderByDesc('settled_at')
            ->limit($limit)
            ->get()
            ->map(fn (Settlement $settlement) => [
                'id' => $settlement->id,
                'order_number' => $settlement->order?->order_number,
                'seller_profile_id' => $settlement->seller_profile_id,
                'hatchery_name' => $settlement->sellerProfile?->hatchery_name,
                'municipality' => $settlement->municipality?->name,
                'gross_amount' => (float) $settlement->gross_amount,
                'platform_share' => (float) $settlement->platform_share,
                'platform_percent' => (float) $settlement->platform_percent,
                'settled_at' => $settlement->settled_at,
                'reversed' => $settlement->isReversed(),
                'reversed_at' => $settlement->reversed_at,
                'reversed_by' => $settlement->reversedBy?->name,
                'reversal_reason' => $settlement->reversal_reason,
            ])
            ->all();

        $reversed = fn () => Settlement::whereNotNull('reversed_at');

        return array_merge(self::platformCards(), [
            'total_payout_fees' => round((float) WithdrawalRequest::where('status', 'paid')->sum('platform_fee'), 2),
            'reversed_settlements' => $reversed()->count(),
            'reversed_platform_share' => round((float) $reversed()->sum('platform_share'), 2),
            'ledger' => $ledger,
        ]);
    }

    /**
     * Executive Dashboard snapshot -- the extra "at a glance" figures the
     * Super Admin overview needs beyond platformCards(): today's activity and
     * the platform's current top performers. Kept separate from
     * platformCards() so its other callers (AI resolver, report exporter,
     * PlatformController) don't pay for these additional aggregate queries.
     * Gross figures are settlement-based (buyer-paid value recognized at
     * settlement), consistent with gross_marketplace_revenue in platformCards().
     */
    public static function executiveSnapshot(): array
    {
        $today = now()->toDateString();

        $topMunicipality = Settlement::notReversed()->join('municipalities', 'settlements.municipality_id', '=', 'municipalities.id')
            ->selectRaw('municipalities.name as name, sum(settlements.gross_amount) as amount, count(*) as orders')
            ->groupBy('municipalities.name')
            ->orderByDesc('amount')
            ->first();

        $topSeller = Settlement::notReversed()->join('seller_profiles', 'settlements.seller_profile_id', '=', 'seller_profiles.id')
            ->selectRaw('seller_profiles.hatchery_name as name, sum(settlements.gross_amount) as amount, count(*) as orders')
            ->groupBy('seller_profiles.hatchery_name')
            ->orderByDesc('amount')
            ->first();

        $topSpecies = Settlement::notReversed()->join('orders', 'settlements.order_id', '=', 'orders.id')
            ->join('listings', 'orders.listing_id', '=', 'listings.id')
            ->selectRaw('listings.species as name, sum(settlements.gross_amount) as amount, count(*) as orders')
            ->groupBy('listings.species')
            ->orderByDesc('amount')
            ->first();

        return [
            'todays_orders' => \App\Models\Order::whereDate('created_at', $today)->count(),
            'todays_gross_revenue' => round((float) Settlement::notReversed()->whereDate('settled_at', $today)->sum('gross_amount'), 2),
            'monthly_gross_revenue' => round((float) Settlement::notReversed()->whereBetween('settled_at', [now()->startOfMonth(), now()->endOfMonth()])->sum('gross_amount'), 2),
            'top_municipality' => $topMunicipality ? ['name' => $topMunicipality->name, 'amount' => round((float) $topMunicipality->amount, 2), 'orders' => (int) $topMunicipality->orders] : null,
            'top_seller' => $topSeller ? ['name' => $topSeller->name, 'amount' => round((float) $topSeller->amount, 2), 'orders' => (int) $topSeller->orders] : null,
            'top_species' => $topSpecies ? ['name' => $topSpecies->name, 'amount' => round((float) $topSpecies->amount, 2), 'orders' => (int) $topSpecies->orders] : null,
        ];
    }

    /**
     * Realized Platform Revenue, for an arbitrary date range -- the building
     * block for the time-series chart and the AI Assistant's platform
     * revenue answers, so cards/charts/AI can never disagree.
     */
    public static function realizedPlatformRevenueTotal(Carbon $start, Carbon $end): float
    {
        $shares = (float) Settlement::notReversed()->whereBetween('settled_at', [$start, $end])->sum('platform_share');
        $fees = (float) WithdrawalRequest::where('status', 'paid')
            ->whereBetween('paid_at', [$start, $end])
            ->sum('platform_fee');

        return round($shares + $fees, 2);
    }

    /**
     * Realized Platform Revenue Over Time -- settlement Platform Shares by
     * settled_at plus old payout fees by paid_at, bucket by bucket.
     */
    public static function platformRevenueOverTime(Carbon $start, Carbon $end, string $unit): array
    {
        $shares = Settlement::notReversed()->whereBetween('settled_at', [$start, $end])
            ->get(['settled_at', 'platform_share'])
            ->map(fn ($row) => (object) ['at' => $row->settled_at, 'amount' => (float) $row->platform_share]);
        $fees = WithdrawalRequest::where('status', 'paid')
            ->whereBetween('paid_at', [$start, $end])
            ->get(['paid_at', 'platform_fee'])
            ->map(fn ($row) => (object) ['at' => $row->paid_at, 'amount' => (float) $row->platform_fee]);

        return AnalyticsPeriod::bucketize($start, $end, $unit, $shares->concat($fees), 'at', 'amount');
    }

    /**
     * LGU Withdrawal Trends -- bucketized by paid_at on paid LGU withdrawal
     * requests, for the LGU Analytics page. Distinct from lgu_revenue_over_time
     * (which buckets by settlement date, i.e. when the revenue was earned):
     * this instead shows when the municipality actually cashed it out.
     */
    public static function lguWithdrawalTrends(int $municipalityId, Carbon $start, Carbon $end, string $unit): array
    {
        $rows = LguWithdrawalRequest::where('municipality_id', $municipalityId)
            ->where('status', 'paid')
            ->whereBetween('paid_at', [$start, $end])
            ->get(['paid_at', 'amount']);

        return AnalyticsPeriod::bucketize($start, $end, $unit, $rows, 'paid_at', 'amount');
    }

    /**
     * Realized Platform Revenue by municipality -- exact, not an
     * approximation: every settlement and every withdrawal belongs to exactly
     * one seller, who belongs to exactly one municipality. "total" counts the
     * settled orders plus the old fee-bearing payouts behind the amount.
     */
    public static function platformRevenueByMunicipality(Carbon $start, Carbon $end)
    {
        $shares = Settlement::notReversed()->whereBetween('settlements.settled_at', [$start, $end])
            ->where('settlements.platform_share', '>', 0)
            ->join('municipalities', 'settlements.municipality_id', '=', 'municipalities.id')
            ->selectRaw('municipalities.name as municipality, sum(settlements.platform_share) as amount, count(*) as total')
            ->groupBy('municipalities.name')
            ->get();

        $fees = WithdrawalRequest::where('withdrawal_requests.status', 'paid')
            ->whereBetween('withdrawal_requests.paid_at', [$start, $end])
            ->where('withdrawal_requests.platform_fee', '>', 0)
            ->join('seller_profiles', 'withdrawal_requests.seller_profile_id', '=', 'seller_profiles.id')
            ->join('municipalities', 'seller_profiles.municipality_id', '=', 'municipalities.id')
            ->selectRaw('municipalities.name as municipality, sum(withdrawal_requests.platform_fee) as amount, count(*) as total')
            ->groupBy('municipalities.name')
            ->get();

        return $shares->toBase()->concat($fees->toBase())
            ->groupBy('municipality')
            ->map(fn ($rows, $municipality) => (object) [
                'municipality' => $municipality,
                'amount' => round($rows->sum(fn ($row) => (float) $row->amount), 2),
                'total' => $rows->sum(fn ($row) => (int) $row->total),
            ])
            ->sortByDesc('amount')
            ->values();
    }

    /**
     * Realized Platform Revenue by fish species -- an old payout fee draws
     * from a seller's pooled balance, not one order, so species can't be
     * attributed exactly the way municipality can. (The 4% Platform Share is
     * proportional to gross anyway, so for current orders the result below
     * is exact.) Instead this distributes the realized total
     * proportionally across species using each species' share of settled
     * gross order value in range -- a standard weighted-allocation approach
     * that always sums back to exactly the same realized total shown on the
     * dashboard cards, so the breakdown never implies more revenue exists
     * than has actually been realized.
     */
    public static function platformRevenueBySpecies(Carbon $start, Carbon $end): array
    {
        $settledBySpecies = Settlement::notReversed()->whereBetween('settled_at', [$start, $end])
            ->join('orders', 'settlements.order_id', '=', 'orders.id')
            ->join('listings', 'orders.listing_id', '=', 'listings.id')
            ->selectRaw('listings.species as species, sum(settlements.gross_amount) as settled_gross, count(*) as total')
            ->groupBy('listings.species')
            ->get();

        $settledTotal = (float) $settledBySpecies->sum('settled_gross');
        $realizedTotal = self::realizedPlatformRevenueTotal($start, $end);
        $ratio = $settledTotal > 0 ? $realizedTotal / $settledTotal : 0;

        return $settledBySpecies
            ->map(fn ($row) => [
                'species' => $row->species,
                'amount' => round((float) $row->settled_gross * $ratio, 2),
                'total' => $row->total,
            ])
            ->sortByDesc('amount')
            ->values()
            ->all();
    }

    /**
     * Bucketized revenue-over-time series for the LGU analytics graph, using
     * whichever settlement column represents the asking role's share
     * ('lgu_share' for LGU Revenue Over Time, 'gross_amount' for Super
     * Admin's Gross Marketplace Revenue chart). Platform Revenue Over Time
     * uses platformRevenueOverTime() above instead, since it isn't
     * settlement-dated.
     */
    public static function bucketize(Carbon $start, Carbon $end, string $unit, iterable $rows, string $amountField): array
    {
        return AnalyticsPeriod::bucketize($start, $end, $unit, $rows, 'settled_at', $amountField);
    }
}
