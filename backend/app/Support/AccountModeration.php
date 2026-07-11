<?php

namespace App\Support;

use App\Mail\AccountReinstatedMail;
use App\Mail\AccountSuspendedMail;
use App\Models\ModerationLog;
use App\Models\SellerProfile;
use App\Models\User;

/**
 * The single place that actually performs a suspend/reinstate action, for
 * every role -- Buyer, Seller, and LGU Admin. Controllers (LguController's
 * existing seller suspension, and SuperAdminController's new global
 * moderation endpoints) call into here instead of duplicating the
 * status-update + token-revocation + audit-log + email sequence themselves.
 *
 * Each role stores its status differently (users.status for Buyers and LGU
 * Admins, seller_profiles.status for Sellers) and has different
 * consequences (a suspended Buyer can still log in; a suspended Seller or
 * LGU Admin cannot, so their tokens are revoked immediately) -- but every
 * action always produces exactly one ModerationLog row and one email.
 */
class AccountModeration
{
    public static function suspendBuyer(User $buyer, User $moderator, string $reason, ?string $notes = null): User
    {
        $buyer->update(['status' => 'suspended']);
        // Deliberately NOT revoking tokens -- a suspended Buyer must still
        // be able to log in (see AuthController::login), just not place
        // orders, pay, message, or review. See the per-action guards in
        // OrderController/MessageController/ReviewController.

        self::log($buyer, 'buyer', $moderator, 'suspended', $reason, $notes, 'suspended');
        SafeMailer::send($buyer->email, new AccountSuspendedMail($buyer, 'buyer', $moderator, $reason, $notes));

        return $buyer->fresh();
    }

    public static function reinstateBuyer(User $buyer, User $moderator, string $reason, ?string $notes = null): User
    {
        $buyer->update(['status' => 'active']);

        self::log($buyer, 'buyer', $moderator, 'reinstated', $reason, $notes, 'active');
        SafeMailer::send($buyer->email, new AccountReinstatedMail($buyer, 'buyer', $moderator, $reason, $notes));

        return $buyer->fresh();
    }

    public static function suspendSeller(SellerProfile $seller, User $moderator, ?string $reason = null, ?string $notes = null): SellerProfile
    {
        $seller->update(['status' => 'suspended']);
        $seller->user?->tokens()->delete();

        if ($seller->user) {
            self::log($seller->user, 'seller', $moderator, 'suspended', $reason, $notes, 'suspended');
            SafeMailer::send($seller->user->email, new AccountSuspendedMail($seller->user, 'seller', $moderator, $reason, $notes));
        }

        return $seller->fresh();
    }

    public static function reinstateSeller(SellerProfile $seller, User $moderator, string $reason, ?string $notes = null): SellerProfile
    {
        $seller->update(['status' => $seller->verified ? 'verified' : 'pending']);

        if ($seller->user) {
            self::log($seller->user, 'seller', $moderator, 'reinstated', $reason, $notes, $seller->status);
            SafeMailer::send($seller->user->email, new AccountReinstatedMail($seller->user, 'seller', $moderator, $reason, $notes));
        }

        return $seller->fresh();
    }

    public static function suspendLguAdmin(User $lguAdmin, User $moderator, ?string $reason = null, ?string $notes = null): User
    {
        $lguAdmin->update(['status' => 'disabled']);
        $lguAdmin->tokens()->delete();

        self::log($lguAdmin, 'lgu_admin', $moderator, 'suspended', $reason, $notes, 'disabled');
        SafeMailer::send($lguAdmin->email, new AccountSuspendedMail($lguAdmin, 'lgu_admin', $moderator, $reason, $notes));

        return $lguAdmin->fresh();
    }

    public static function reinstateLguAdmin(User $lguAdmin, User $moderator, string $reason, ?string $notes = null): User
    {
        $lguAdmin->update(['status' => 'active']);

        self::log($lguAdmin, 'lgu_admin', $moderator, 'reinstated', $reason, $notes, 'active');
        SafeMailer::send($lguAdmin->email, new AccountReinstatedMail($lguAdmin, 'lgu_admin', $moderator, $reason, $notes));

        return $lguAdmin->fresh();
    }

    private static function log(User $subject, string $role, User $moderator, string $action, ?string $reason, ?string $notes, string $resultingStatus): void
    {
        ModerationLog::create([
            'user_id' => $subject->id,
            'role' => $role,
            'moderator_id' => $moderator->id,
            'action' => $action,
            'reason' => $reason,
            'notes' => $notes,
            'resulting_status' => $resultingStatus,
        ]);
    }
}
