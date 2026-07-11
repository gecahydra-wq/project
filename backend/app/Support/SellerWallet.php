<?php

namespace App\Support;

use App\Models\MockPayment;
use App\Models\SellerProfile;
use App\Models\Settlement;
use App\Models\WithdrawalRequest;

/**
 * Wallet balance math shared by SellerController (the Wallet page) and the
 * AI Assistant (so a seller's "how much can I withdraw?" answer is computed
 * by the exact same rules as the wallet page itself, never a re-derived
 * approximation).
 *
 * Every balance here is expressed in Seller Share terms, never the gross
 * order amount -- see App\Support\CommissionCalculator and
 * LguController::approveEarnings. A payment only becomes part of the
 * seller's real, spendable balance once it has an immutable Settlement row
 * (i.e. an LGU has approved it); before that, "Pending Balance" is a
 * same-formula *projection* of what the seller will receive, computed from
 * today's commission settings since no settlement has locked in the actual
 * split yet.
 */
class SellerWallet
{
    /**
     * Withdrawal requests that are not-yet-paid or already-paid both permanently
     * remove money from the available pool; only 'rejected' returns it.
     */
    public static function reservedOrWithdrawn(SellerProfile $seller): float
    {
        return (float) WithdrawalRequest::where('seller_profile_id', $seller->id)
            ->whereIn('status', ['pending', 'approved', 'paid'])
            ->sum('amount');
    }

    /**
     * Sum of Seller Share across every immutable settlement this seller has
     * ever had approved -- the seller's lifetime "real" earnings pool, from
     * which Available Balance and Total Earnings are both derived. Never
     * recomputed from current commission settings; each settlement already
     * carries the split that applied when the LGU approved it.
     */
    public static function settledSellerShareTotal(SellerProfile $seller): float
    {
        return (float) Settlement::where('seller_profile_id', $seller->id)->sum('seller_share');
    }

    /**
     * Payments captured (paid_held) but not yet approved by an LGU, so no
     * Settlement exists for them yet. Projects what the seller will receive
     * using *today's* commission settings -- once approved, the real
     * settlement is created and this projection is superseded by
     * settledSellerShareTotal(), which is why this must never be persisted.
     */
    public static function pendingBalance(SellerProfile $seller): float
    {
        $unsettledGross = (float) MockPayment::whereHas('order', fn ($q) => $q->where('seller_profile_id', $seller->id))
            ->where('status', 'paid_held')
            ->sum('amount');

        return CommissionCalculator::sellerShareOf($unsettledGross);
    }

    /**
     * A running lifetime balance (all settled Seller Share ever, minus all
     * withdrawals ever requested), NOT a per-order amount.
     */
    public static function availableBalance(SellerProfile $seller): float
    {
        return max(0, round(self::settledSellerShareTotal($seller) - self::reservedOrWithdrawn($seller), 2));
    }

    /**
     * The seller's real cumulative cash received -- net of the platform's
     * payout fee (see CommissionCalculator::withdrawalFee()), not the gross
     * amount they requested. This intentionally makes Withdrawn Amount no
     * longer sum up to Total Earnings alongside Available/Pending/Processing
     * -- the difference is exactly the fees paid to the platform, which are
     * real money that left the seller's earnings but never became cash in
     * their hands.
     */
    public static function withdrawnAmount(SellerProfile $seller): float
    {
        $paid = WithdrawalRequest::where('seller_profile_id', $seller->id)
            ->where('status', 'paid')
            ->selectRaw('COALESCE(SUM(amount - platform_fee), 0) as net_total')
            ->value('net_total');

        return round((float) $paid, 2);
    }

    /**
     * Money reserved by a withdrawal request that has been submitted (and
     * possibly approved) but not yet marked paid.
     */
    public static function processingAmount(SellerProfile $seller): float
    {
        return round((float) WithdrawalRequest::where('seller_profile_id', $seller->id)
            ->whereIn('status', ['pending', 'approved'])
            ->sum('amount'), 2);
    }

    public static function summary(SellerProfile $seller): array
    {
        $pendingBalance = self::pendingBalance($seller);

        return [
            'available_balance' => self::availableBalance($seller),
            'pending_balance' => $pendingBalance,
            'processing_amount' => self::processingAmount($seller),
            // Deliberately the gross Seller Share (settled + projected),
            // NEVER reduced by the platform's withdrawal fee -- Total
            // Earnings is what the seller earned from selling, not what
            // survived the payout process. See withdrawnAmount() for the
            // net (post-fee) figure.
            'total_earnings' => round(self::settledSellerShareTotal($seller) + $pendingBalance, 2),
            'withdrawn_amount' => self::withdrawnAmount($seller),
        ];
    }
}
