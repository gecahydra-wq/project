<?php

namespace App\Support;

/**
 * The marketplace's fixed revenue-sharing rules -- not configurable, no
 * Super Admin UI or settings table. Changing any of these requires a code
 * change and a deploy, not a runtime setting.
 *
 * Two distinct, separately-timed splits:
 *
 * 1. Order settlement (see LguController::approveEarnings): when an LGU
 *    approves a completed order, the gross amount splits into Seller Share
 *    and LGU Share only. The Platform does NOT take a cut here -- see
 *    split(). The seller's full settled share moves into their Available
 *    Balance immediately; the LGU's share is informational revenue,
 *    recognized immediately too.
 *
 * 2. Withdrawal payout (see SellerController::requestWithdrawal): the
 *    Platform instead earns a payout fee charged on the withdrawal amount
 *    itself -- see withdrawalFee(). This is frozen onto the
 *    WithdrawalRequest row at request time (withdrawal_requests.platform_fee)
 *    and only becomes realized Platform Revenue once the Super Admin marks
 *    that withdrawal Paid -- see App\Support\RevenueReport.
 */
class CommissionCalculator
{
    public const SELLER_PERCENT = 96.0;
    public const LGU_PERCENT = 4.0;
    public const WITHDRAWAL_FEE_PERCENT = 6.0;

    /**
     * Rounds the seller share to the nearest centavo first, then derives the
     * LGU share as whatever remains of the gross amount. This guarantees
     * seller_share + lgu_share always sums to exactly gross_amount, even
     * when the percentages don't divide the amount evenly -- the LGU
     * absorbs the rounding remainder rather than the seller.
     *
     * @return array{seller_share: float, lgu_share: float, platform_share: float, seller_percent: float, lgu_percent: float, platform_percent: float}
     */
    public static function split(float $grossAmount): array
    {
        $sellerShare = round($grossAmount * self::SELLER_PERCENT / 100, 2);
        $lguShare = round($grossAmount - $sellerShare, 2);

        return [
            'seller_share' => $sellerShare,
            'lgu_share' => $lguShare,
            // The Platform no longer takes a cut at settlement time -- its
            // revenue comes from the withdrawal fee instead (see
            // withdrawalFee() below). Kept at 0 here (rather than dropping
            // the column) so Settlement's schema and every existing reader
            // of platform_share/platform_percent stay valid.
            'platform_share' => 0.0,
            'seller_percent' => self::SELLER_PERCENT,
            'lgu_percent' => self::LGU_PERCENT,
            'platform_percent' => 0.0,
        ];
    }

    /**
     * The seller's share only, for projecting a not-yet-settled payment's
     * eventual value (see SellerWallet::pendingBalance) -- no settlement row
     * is created here.
     */
    public static function sellerShareOf(float $grossAmount): float
    {
        return round($grossAmount * self::SELLER_PERCENT / 100, 2);
    }

    /**
     * The Platform's payout fee on a withdrawal request -- charged against
     * the amount the seller is asking to withdraw, not the original order
     * amount. Rounds down to the nearest centavo so the fee never exceeds
     * exactly WITHDRAWAL_FEE_PERCENT of what was requested.
     *
     * @return array{fee: float, net_amount: float}
     */
    public static function withdrawalFee(float $requestedAmount): array
    {
        $fee = round($requestedAmount * self::WITHDRAWAL_FEE_PERCENT / 100, 2);

        return [
            'fee' => $fee,
            'net_amount' => round($requestedAmount - $fee, 2),
        ];
    }
}
