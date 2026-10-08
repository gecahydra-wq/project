<?php

namespace App\Support;

/**
 * The marketplace's fixed revenue-sharing rules -- not configurable, no
 * Super Admin UI or settings table. Changing any of these requires a code
 * change and a deploy, not a runtime setting.
 *
 * One split, taken once (see LguController::approveEarnings): when an LGU
 * approves a completed order, the gross amount splits into Seller Share 94%,
 * LGU Share 2% and Platform Share 4%, frozen onto the Settlement row. The
 * seller's share moves into their Available Balance and is withdrawn in full
 * -- there is no payout fee any more.
 *
 * History: until 2026-10-09 the split was Seller 96% / LGU 4% at settlement
 * plus a 6% Platform payout fee on every withdrawal. Rows made before then
 * keep their frozen figures (settlements.*_percent, withdrawal_requests
 * .platform_fee), and RevenueReport counts both kinds of platform income.
 */
class CommissionCalculator
{
    public const SELLER_PERCENT = 94.0;
    public const LGU_PERCENT = 2.0;
    public const PLATFORM_PERCENT = 4.0;

    /** No longer charged; old withdrawals keep the fee frozen on their row. */
    public const WITHDRAWAL_FEE_PERCENT = 0.0;

    /**
     * Rounds the seller and LGU shares to the nearest centavo, then gives the
     * Platform whatever remains of the gross amount. This guarantees the three
     * shares always sum to exactly gross_amount -- the Platform absorbs the
     * rounding remainder rather than the seller.
     *
     * @return array{seller_share: float, lgu_share: float, platform_share: float, seller_percent: float, lgu_percent: float, platform_percent: float}
     */
    public static function split(float $grossAmount): array
    {
        $sellerShare = round($grossAmount * self::SELLER_PERCENT / 100, 2);
        $lguShare = round($grossAmount * self::LGU_PERCENT / 100, 2);
        $platformShare = round($grossAmount - $sellerShare - $lguShare, 2);

        return [
            'seller_share' => $sellerShare,
            'lgu_share' => $lguShare,
            'platform_share' => $platformShare,
            'seller_percent' => self::SELLER_PERCENT,
            'lgu_percent' => self::LGU_PERCENT,
            'platform_percent' => self::PLATFORM_PERCENT,
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
     * The payout fee on a withdrawal request. Zero since 2026-10-09 (the
     * Platform takes its share at settlement instead); kept so the request
     * flow and old rows' platform_fee column keep one shape.
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
