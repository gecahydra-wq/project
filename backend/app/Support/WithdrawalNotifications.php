<?php

namespace App\Support;

use App\Models\AppNotification;
use App\Models\LguWithdrawalRequest;
use App\Models\User;
use App\Models\WithdrawalRequest;

/**
 * Who hears about a NEW withdrawal request. Approval, rejection and payment
 * already notify the requester (SuperAdminController); this covers the
 * submission itself: the Super Admin, who has to act on it, and the
 * requester, as a receipt of what they asked for.
 */
class WithdrawalNotifications
{
    private const METHOD_LABELS = ['gcash' => 'GCash', 'maya' => 'Maya', 'bank_transfer' => 'Bank Transfer'];

    public static function sellerRequested(WithdrawalRequest $withdrawal): void
    {
        $withdrawal->loadMissing('sellerProfile.user');
        $seller = $withdrawal->sellerProfile;
        $amount = self::money($withdrawal->amount);
        $method = self::method($withdrawal->method);

        self::notifySuperAdmins(
            "withdrawal_requested:{$withdrawal->id}",
            'New Seller Withdrawal Request',
            sprintf(
                '%s requested a withdrawal of ₱%s via %s (₱%s after the platform fee). Review it under Payouts.',
                $seller?->hatchery_name ?: ($seller?->user?->name ?? 'A seller'),
                $amount,
                $method,
                self::money($withdrawal->amount - $withdrawal->platform_fee)
            )
        );

        if ($seller?->user_id) {
            AppNotification::firstOrCreate([
                'user_id' => $seller->user_id,
                'type' => "withdrawal_submitted:{$withdrawal->id}",
            ], [
                'title' => 'Withdrawal Request Submitted',
                'body' => sprintf(
                    'Your request to withdraw ₱%s via %s was sent to the Super Admin. After the %s%% platform fee of ₱%s, you will receive ₱%s once it is approved and paid.',
                    $amount,
                    $method,
                    rtrim(rtrim(number_format(CommissionCalculator::WITHDRAWAL_FEE_PERCENT, 2), '0'), '.'),
                    self::money($withdrawal->platform_fee),
                    self::money($withdrawal->amount - $withdrawal->platform_fee)
                ),
            ]);
        }
    }

    public static function lguRequested(LguWithdrawalRequest $withdrawal): void
    {
        $withdrawal->loadMissing(['municipality', 'requestedBy']);
        $municipality = $withdrawal->municipality?->name ?? 'A municipality';
        $amount = self::money($withdrawal->amount);
        $method = self::method($withdrawal->method);

        self::notifySuperAdmins(
            "lgu_withdrawal_requested:{$withdrawal->id}",
            'New LGU Withdrawal Request',
            sprintf(
                '%s (%s) requested an LGU withdrawal of ₱%s via %s. Review it under Payouts.',
                $municipality,
                $withdrawal->requestedBy?->name ?? 'LGU Admin',
                $amount,
                $method
            )
        );

        // Every LGU Admin of the municipality shares one LGU Wallet, so all of
        // them get the receipt -- not only the one who pressed the button.
        $admins = User::where('role', 'lgu_admin')->where('municipality_id', $withdrawal->municipality_id)->pluck('id');
        foreach ($admins as $adminId) {
            AppNotification::firstOrCreate([
                'user_id' => $adminId,
                'type' => "lgu_withdrawal_submitted:{$withdrawal->id}",
            ], [
                'title' => 'LGU Withdrawal Request Submitted',
                'body' => sprintf(
                    'A withdrawal of ₱%s via %s was requested for %s by %s and sent to the Super Admin for approval.',
                    $amount,
                    $method,
                    $municipality,
                    $withdrawal->requestedBy?->name ?? 'an LGU Admin'
                ),
            ]);
        }
    }

    private static function notifySuperAdmins(string $type, string $title, string $body): void
    {
        foreach (User::where('role', 'super_admin')->pluck('id') as $adminId) {
            AppNotification::firstOrCreate(['user_id' => $adminId, 'type' => $type], ['title' => $title, 'body' => $body]);
        }
    }

    private static function money($value): string
    {
        return number_format((float) $value, 2);
    }

    private static function method(?string $method): string
    {
        return self::METHOD_LABELS[$method] ?? (string) $method;
    }
}
