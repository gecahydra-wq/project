<?php

namespace App\Support;

use App\Models\LguWithdrawalRequest;
use App\Models\Settlement;

/**
 * Wallet balance math for an LGU's own municipal revenue -- mirrors
 * App\Support\SellerWallet's architecture exactly (same method names, same
 * shared "available = lifetime total minus reserved/withdrawn" formula) so
 * the LGU Wallet page and the AI Assistant compute LGU answers by the exact
 * same rules, never a re-derived approximation.
 *
 * Every balance here is expressed in LGU Share terms only -- never Seller
 * earnings or the Platform's cut, see App\Support\CommissionCalculator and
 * LguController::approveEarnings. Scoped by municipality_id rather than a
 * single profile id, since LGU revenue belongs to the municipality, shared
 * by every LGU admin account assigned to it (see App\Models\Municipality).
 */
class LguWallet
{
    /**
     * Withdrawal requests that are not-yet-paid or already-paid both
     * permanently remove money from the available pool; only 'rejected'
     * returns it.
     */
    public static function reservedOrWithdrawn(int $municipalityId): float
    {
        return (float) LguWithdrawalRequest::where('municipality_id', $municipalityId)
            ->whereIn('status', ['pending', 'approved', 'paid'])
            ->sum('amount');
    }

    /**
     * Sum of LGU Share across every immutable settlement for this
     * municipality -- the municipality's lifetime "real" revenue pool, from
     * which Available Balance is derived. Never recomputed retroactively;
     * each settlement already carries the split that applied at approval
     * time.
     */
    public static function totalRevenue(int $municipalityId): float
    {
        return (float) Settlement::where('municipality_id', $municipalityId)->sum('lgu_share');
    }

    /**
     * Unlike a seller's Pending Balance (which projects payments not yet
     * LGU-approved), an LGU's own revenue realizes the instant THEY approve
     * an order's earnings -- there is no separate approval step waiting on
     * anyone else. So there is nothing to project here; this always returns
     * 0 and exists only for API-shape parity with SellerWallet::summary().
     */
    public static function pendingBalance(int $municipalityId): float
    {
        return 0.0;
    }

    /**
     * A running lifetime balance (all settled LGU Share ever, minus all
     * withdrawals ever requested), NOT a per-order amount.
     */
    public static function availableBalance(int $municipalityId): float
    {
        return max(0, round(self::totalRevenue($municipalityId) - self::reservedOrWithdrawn($municipalityId), 2));
    }

    /**
     * The municipality's real cumulative cash received. Unlike Seller
     * withdrawals, there is no platform fee on an LGU withdrawal -- the
     * Platform already took its cut at order settlement (Settlement.
     * platform_share), so the full requested amount is what's paid out.
     */
    public static function withdrawnAmount(int $municipalityId): float
    {
        return round((float) LguWithdrawalRequest::where('municipality_id', $municipalityId)
            ->where('status', 'paid')
            ->sum('amount'), 2);
    }

    /**
     * Money reserved by a withdrawal request that has been submitted (and
     * possibly approved) but not yet marked paid.
     */
    public static function processingAmount(int $municipalityId): float
    {
        return round((float) LguWithdrawalRequest::where('municipality_id', $municipalityId)
            ->whereIn('status', ['pending', 'approved'])
            ->sum('amount'), 2);
    }

    public static function summary(int $municipalityId): array
    {
        return [
            'available_balance' => self::availableBalance($municipalityId),
            'pending_balance' => self::pendingBalance($municipalityId),
            'processing_amount' => self::processingAmount($municipalityId),
            'total_revenue' => round(self::totalRevenue($municipalityId), 2),
            'withdrawn_amount' => self::withdrawnAmount($municipalityId),
        ];
    }
}
