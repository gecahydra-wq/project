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
 * revenue). The Platform earns nothing at settlement time at all -- its
 * revenue is a payout fee charged on withdrawals (see
 * CommissionCalculator::withdrawalFee()), frozen onto each WithdrawalRequest
 * as platform_fee when the seller requests it, and only realized once the
 * Super Admin actually marks that withdrawal Paid. A settled-but-unwithdrawn
 * order contributes nothing to Platform Revenue.
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
        $base = Settlement::where('municipality_id', $municipalityId);

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
     * sum of platform_fee on PAID withdrawals only (see class docblock).
     * gross_marketplace_revenue is a different concept entirely -- the full
     * buyer payment total across every settled order -- and stays
     * settlement-based since it's not the platform's own earned income.
     */
    public static function platformCards(): array
    {
        $paid = WithdrawalRequest::where('status', 'paid');

        $today = (float) (clone $paid)->whereDate('paid_at', now()->toDateString())->sum('platform_fee');
        $monthly = (float) (clone $paid)->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])->sum('platform_fee');
        $total = (float) (clone $paid)->sum('platform_fee');

        $gross = (float) Settlement::sum('gross_amount');
        $orders = Settlement::count();

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

        $topMunicipality = Settlement::join('municipalities', 'settlements.municipality_id', '=', 'municipalities.id')
            ->selectRaw('municipalities.name as name, sum(settlements.gross_amount) as amount, count(*) as orders')
            ->groupBy('municipalities.name')
            ->orderByDesc('amount')
            ->first();

        $topSeller = Settlement::join('seller_profiles', 'settlements.seller_profile_id', '=', 'seller_profiles.id')
            ->selectRaw('seller_profiles.hatchery_name as name, sum(settlements.gross_amount) as amount, count(*) as orders')
            ->groupBy('seller_profiles.hatchery_name')
            ->orderByDesc('amount')
            ->first();

        $topSpecies = Settlement::join('orders', 'settlements.order_id', '=', 'orders.id')
            ->join('listings', 'orders.listing_id', '=', 'listings.id')
            ->selectRaw('listings.species as name, sum(settlements.gross_amount) as amount, count(*) as orders')
            ->groupBy('listings.species')
            ->orderByDesc('amount')
            ->first();

        return [
            'todays_orders' => \App\Models\Order::whereDate('created_at', $today)->count(),
            'todays_gross_revenue' => round((float) Settlement::whereDate('settled_at', $today)->sum('gross_amount'), 2),
            'monthly_gross_revenue' => round((float) Settlement::whereBetween('settled_at', [now()->startOfMonth(), now()->endOfMonth()])->sum('gross_amount'), 2),
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
        return round((float) WithdrawalRequest::where('status', 'paid')
            ->whereBetween('paid_at', [$start, $end])
            ->sum('platform_fee'), 2);
    }

    /**
     * Realized Platform Revenue Over Time -- bucketized by paid_at on paid
     * withdrawals' platform_fee, NOT by settled_at on settlements. This is a
     * deliberately different time axis from LGU Revenue Over Time, which
     * still buckets by settlement date.
     */
    public static function platformRevenueOverTime(Carbon $start, Carbon $end, string $unit): array
    {
        $rows = WithdrawalRequest::where('status', 'paid')
            ->whereBetween('paid_at', [$start, $end])
            ->get(['paid_at', 'platform_fee']);

        return AnalyticsPeriod::bucketize($start, $end, $unit, $rows, 'paid_at', 'platform_fee');
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
     * approximation, since every withdrawal belongs to exactly one seller
     * who belongs to exactly one municipality.
     */
    public static function platformRevenueByMunicipality(Carbon $start, Carbon $end)
    {
        return WithdrawalRequest::where('withdrawal_requests.status', 'paid')
            ->whereBetween('withdrawal_requests.paid_at', [$start, $end])
            ->join('seller_profiles', 'withdrawal_requests.seller_profile_id', '=', 'seller_profiles.id')
            ->join('municipalities', 'seller_profiles.municipality_id', '=', 'municipalities.id')
            ->selectRaw('municipalities.name as municipality, sum(withdrawal_requests.platform_fee) as amount, count(*) as total')
            ->groupBy('municipalities.name')
            ->orderByDesc('amount')
            ->get();
    }

    /**
     * Realized Platform Revenue by fish species -- a withdrawal draws from a
     * seller's pooled balance across every one of their settled orders, not
     * from one specific order, so species can't be attributed exactly the
     * way municipality can. Instead this distributes the realized total
     * proportionally across species using each species' share of settled
     * gross order value in range -- a standard weighted-allocation approach
     * that always sums back to exactly the same realized total shown on the
     * dashboard cards, so the breakdown never implies more revenue exists
     * than has actually been realized.
     */
    public static function platformRevenueBySpecies(Carbon $start, Carbon $end): array
    {
        $settledBySpecies = Settlement::whereBetween('settled_at', [$start, $end])
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
