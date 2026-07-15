<?php

namespace App\Support;

use App\Mail\AccountReinstatedMail;
use App\Mail\AccountRemovedMail;
use App\Mail\AccountSuspendedMail;
use App\Models\ModerationLog;
use App\Models\SellerProfile;
use App\Models\User;

/**
 * The single place that actually performs an account moderation action, for
 * every role -- Buyer, Seller, and LGU Admin. Controllers (LguController's
 * existing seller suspension, and SuperAdminController's global moderation
 * endpoints) call into here instead of duplicating the status-update +
 * token-revocation + audit-log + email sequence themselves.
 *
 * Suspension is reversible and is the tool for any account with history;
 * removal (removeBuyer/removeSeller) is permanent, Super Admin-only, and
 * refuses accounts that have ever transacted -- see removeBuyer for why.
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

    /**
     * Permanently delete a Buyer account, with a stated reason.
     *
     * Deliberately refused once the account has ANY order history. Every
     * transactional table hangs off users.id with cascadeOnDelete (see the
     * create_fishmarket_tables migration), so deleting a buyer who has
     * ordered would silently take their orders, payments, settlements, and
     * reviews with them -- destroying the financial record the LGU and Super
     * Admin reports are built from. Suspension is the correct tool there, and
     * the 422 below says so; removal exists for spam/never-traded accounts.
     */
    public static function removeBuyer(User $buyer, User $moderator, string $reason, ?string $notes = null): void
    {
        abort_if(
            $buyer->orders()->exists(),
            422,
            'This buyer has order history and cannot be removed, because deleting the account would also delete those orders and their payment records. Suspend the account instead.'
        );

        self::remove($buyer, 'buyer', $moderator, $reason, $notes);
    }

    /**
     * Permanently delete a Seller account and its hatchery profile, with a
     * stated reason. Refused once the seller has any orders against their
     * listings, for the same reason as removeBuyer() -- see that method.
     * The seller's own listings, having never been ordered, go with the
     * account (listings cascade from seller_profiles).
     */
    public static function removeSeller(SellerProfile $seller, User $moderator, string $reason, ?string $notes = null): void
    {
        abort_if(
            $seller->orders()->exists(),
            422,
            'This seller has order history and cannot be removed, because deleting the account would also delete those orders and their payment records. Suspend the account instead.'
        );

        abort_unless($seller->user, 422, 'This seller profile has no linked user account to remove.');

        self::remove($seller->user, 'seller', $moderator, $reason, $notes);
    }

    /**
     * The shared removal sequence: notify, audit, then delete.
     *
     * Order matters. The email goes first because it needs the account that's
     * about to stop existing, and the ActivityLog entry is written before the
     * delete but deliberately spells the account's name and email into its
     * description -- activity_logs.target_user_id is nullOnDelete, so the
     * foreign key is about to go NULL and the description becomes the only
     * surviving record of who was removed. (moderation_logs can't be used for
     * this at all: its user_id cascades, so the row would delete itself along
     * with the account it documents.)
     */
    private static function remove(User $account, string $role, User $moderator, string $reason, ?string $notes): void
    {
        SafeMailer::send($account->email, new AccountRemovedMail($account, $role, $moderator, $reason, $notes));

        ActivityLog::record([
            'actor_id' => $moderator->id,
            'actor_role' => $moderator->role,
            'action' => "{$role}_removed",
            'target_user_id' => $account->id,
            'municipality_id' => $account->municipality_id,
            'description' => sprintf(
                'Permanently removed %s account %s (%s) -- %s%s',
                $role === 'seller' ? 'seller' : 'buyer',
                $account->name,
                $account->email,
                $reason,
                $notes ? " ({$notes})" : ''
            ),
        ]);

        $account->tokens()->delete();
        $account->delete();
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
