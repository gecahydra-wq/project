<?php

namespace App\Support;

use App\Models\AppNotification;
use App\Models\LguWithdrawalRequest;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Mail\LguWithdrawalReleasedMail;
use App\Mail\WithdrawalReleasedMail;
use App\Support\SafeMailer;

/**
 * Withdrawal request and receipt-confirmation notifications. Approvals and
 * rejections are sent by SuperAdminController; confirmation completes the
 * payout and sends the paid notification and email here.
 */
class WithdrawalNotifications
{
    public static function sellerConfirmedReceived(WithdrawalRequest $withdrawal, bool $automatic = false): void
    {
        $withdrawal->loadMissing('sellerProfile.user');
        $seller = $withdrawal->sellerProfile;
        if ($seller?->user) {
            AppNotification::firstOrCreate([
                'user_id' => $seller->user_id,
                'type' => ($automatic ? 'withdrawal_auto_completed:' : 'withdrawal_paid:').$withdrawal->id,
            ], [
                'title' => $automatic ? 'Withdrawal Automatically Completed' : 'Withdrawal Completed',
                'body' => $automatic
                    ? sprintf(
                        'Your withdrawal payout of ₱%s via %s was automatically marked paid after the confirmation window expired.',
                        self::money($withdrawal->net_amount),
                        $withdrawal->method
                    )
                    : sprintf(
                        'Your withdrawal payout of ₱%s via %s was marked paid after you confirmed receipt on %s.',
                        self::money($withdrawal->net_amount),
                        $withdrawal->method,
                        $withdrawal->paid_at?->format('M d, Y') ?? now()->format('M d, Y')
                    ),
            ]);
            SafeMailer::send($seller->user->email, new WithdrawalReleasedMail($withdrawal));

            // The payout's own acknowledgement, from the AbaiMarket system
            // account (see App\Support\SystemMessenger), the way a buyer who
            // clicks "Order Received" gets one (OrderController::completeDelivery).
            // Skipped on the automatic run, which sends its own version of this
            // from the scheduler -- one message per completion, whichever path
            // completed it.
            if (! $automatic) {
                SystemMessenger::send(
                    $seller->user,
                    sprintf(
                        'Thank you for confirming that you received your withdrawal payout of ₱%s via %s. Your withdrawal is now complete and marked paid in your Wallet. If something about the payout is wrong -- the amount, or the account it was sent to -- message the Super Admin or contact Support.',
                        self::money($withdrawal->net_amount),
                        PayoutAccount::methodLabel($withdrawal->method, $withdrawal->bank_name)
                    ),
                    'payout_received',
                    ['withdrawal_type' => 'seller', 'withdrawal_id' => $withdrawal->id]
                );
            }
        }

        self::notifySuperAdmins(
            ($automatic ? 'withdrawal_auto_completed:' : 'withdrawal_received:').$withdrawal->id,
            $automatic ? 'Seller Payout Automatically Completed' : 'Seller Confirmed Payout Received',
            sprintf(
                $automatic
                    ? '%s’s ₱%s withdrawal was automatically marked paid after the confirmation window expired.'
                    : '%s confirmed receiving the ₱%s withdrawal. It has been marked paid and completed.',
                $seller?->hatchery_name ?: ($seller?->user?->name ?? 'A seller'),
                self::money($withdrawal->amount)
            )
        );
    }

    public static function lguConfirmedReceived(LguWithdrawalRequest $withdrawal, bool $automatic = false): void
    {
        $withdrawal->loadMissing(['municipality', 'requestedBy']);
        if ($withdrawal->requestedBy) {
            AppNotification::firstOrCreate([
                'user_id' => $withdrawal->requested_by,
                'type' => ($automatic ? 'lgu_withdrawal_auto_completed:' : 'lgu_withdrawal_paid:').$withdrawal->id,
            ], [
                'title' => $automatic ? 'LGU Withdrawal Automatically Completed' : 'LGU Withdrawal Completed',
                'body' => $automatic
                    ? sprintf(
                        'Your municipality’s ₱%s withdrawal via %s was automatically marked paid after the confirmation window expired.',
                        self::money($withdrawal->amount),
                        $withdrawal->method
                    )
                    : sprintf(
                        'Your municipality confirmed receipt of the ₱%s withdrawal via %s. It was marked paid on %s.',
                        self::money($withdrawal->amount),
                        $withdrawal->method,
                        $withdrawal->paid_at?->format('M d, Y') ?? now()->format('M d, Y')
                    ),
            ]);
            SafeMailer::send($withdrawal->requestedBy->email, new LguWithdrawalReleasedMail($withdrawal));

            // Same acknowledgement as the seller's, for the LGU Wallet. It goes
            // to the admin this notification names, not to whoever happened to
            // click -- the LGU Wallet is shared by every admin of a
            // municipality, so the requester is the stable recipient.
            if (! $automatic) {
                SystemMessenger::send(
                    $withdrawal->requestedBy,
                    sprintf(
                        'Thank you for confirming that your municipality received the withdrawal payout of ₱%s via %s. The withdrawal is now complete and marked paid in the LGU Wallet. If something about the payout is wrong -- the amount, or the account it was sent to -- message the Super Admin or contact Support.',
                        self::money($withdrawal->amount),
                        PayoutAccount::methodLabel($withdrawal->method, $withdrawal->bank_name)
                    ),
                    'payout_received',
                    ['withdrawal_type' => 'lgu', 'withdrawal_id' => $withdrawal->id]
                );
            }
        }

        self::notifySuperAdmins(
            ($automatic ? 'lgu_withdrawal_auto_completed:' : 'lgu_withdrawal_received:').$withdrawal->id,
            $automatic ? 'LGU Payout Automatically Completed' : 'LGU Confirmed Payout Received',
            sprintf(
                $automatic
                    ? '%s’s ₱%s withdrawal was automatically marked paid after the confirmation window expired.'
                    : '%s confirmed receiving the ₱%s withdrawal. It has been marked paid and completed.',
                $withdrawal->municipality?->name ?? 'A municipality',
                self::money($withdrawal->amount)
            )
        );
    }

    public static function sellerRequested(WithdrawalRequest $withdrawal): void
    {
        $withdrawal->loadMissing('sellerProfile.user');
        $seller = $withdrawal->sellerProfile;
        $amount = self::money($withdrawal->amount);
        $method = PayoutAccount::methodLabel($withdrawal->method, $withdrawal->bank_name);

        self::notifySuperAdmins(
            "withdrawal_requested:{$withdrawal->id}",
            'New Seller Withdrawal Request',
            sprintf(
                '%s requested a withdrawal of ₱%s via %s. Review it under Payouts.',
                $seller?->hatchery_name ?: ($seller?->user?->name ?? 'A seller'),
                $amount,
                $method
            )
        );

        if ($seller?->user_id) {
            AppNotification::firstOrCreate([
                'user_id' => $seller->user_id,
                'type' => "withdrawal_submitted:{$withdrawal->id}",
            ], [
                'title' => 'Withdrawal Request Submitted',
                'body' => sprintf(
                    'Your request to withdraw ₱%s via %s was sent to the Super Admin. You will receive the full ₱%s once it is approved and paid.',
                    $amount,
                    $method,
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
        $method = PayoutAccount::methodLabel($withdrawal->method, $withdrawal->bank_name);

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
}
