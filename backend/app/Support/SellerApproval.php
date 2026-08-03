<?php

namespace App\Support;

use App\Models\AppNotification;
use App\Models\SellerProfile;
use App\Models\User;

/**
 * The Seller Registration Approval workflow -- the single place that moves a
 * seller through it, so LguController and SuperAdminController stay thin and
 * can never disagree about the rules.
 *
 *   register -> PENDING -> (LGU Admin OR Super Admin approves) -> APPROVED
 *                       -> (either rejects, with a reason)     -> REJECTED
 *
 * ONE approval is enough. In practice the LGU Admin reviews the sellers in
 * their own municipality -- that is the normal path -- and the Super Admin
 * is the fallback who can approve any registration platform-wide when the
 * municipality's LGU Admin is away or unavailable. Neither waits on the other.
 *
 * A rejection is not terminal: either reviewer can still approve afterwards,
 * so a mistake, or a seller who fixes their documents, is never permanently
 * locked out.
 *
 * Only APPROVED sets verified/status, which is what actually unlocks listing
 * creation (see ListingController::store). Account standing ('suspended')
 * remains entirely App\Support\AccountModeration's business -- this class
 * never touches a suspension.
 *
 * Every transition writes one seller AppNotification and one ActivityLog
 * entry, mirroring how listing approval/rejection is already audited.
 */
class SellerApproval
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /** Human labels for the workflow states, shared with the API responses. */
    public const LABELS = [
        self::PENDING => 'Pending Approval',
        self::APPROVED => 'Approved',
        self::REJECTED => 'Rejected',
    ];

    public static function label(?string $status): string
    {
        return self::LABELS[$status] ?? 'Pending Approval';
    }

    /** True once the registration has been approved -- the only state that may list. */
    public static function isApproved(SellerProfile $seller): bool
    {
        return $seller->approval_status === self::APPROVED;
    }

    /**
     * A registration is reviewable while it is pending, and stays reviewable
     * after a rejection so the decision can be reconsidered by either admin.
     */
    public static function canReview(SellerProfile $seller): bool
    {
        return in_array($seller->approval_status, [self::PENDING, self::REJECTED], true);
    }

    /**
     * Approve the registration. Either an LGU Admin (their own municipality)
     * or the Super Admin (anywhere, as the fallback reviewer) may do this, and
     * one approval is all it takes -- the seller becomes verified and can
     * start creating listings immediately.
     *
     * @param  string  $role  'lgu_admin' or 'super_admin' -- who approved, recorded for the audit trail.
     */
    public static function approve(SellerProfile $seller, User $reviewer, string $role): SellerProfile
    {
        abort_unless(self::canReview($seller), 422, 'This seller registration has already been approved.');

        $stamp = $role === 'lgu_admin'
            ? ['lgu_reviewed_at' => now(), 'lgu_reviewed_by' => $reviewer->id]
            : ['super_admin_reviewed_at' => now(), 'super_admin_reviewed_by' => $reviewer->id];

        $seller->update(array_merge([
            'approval_status' => self::APPROVED,
            'registration_rejection_reason' => null,
            'rejected_by_role' => null,
            // The account becomes an active, verified seller -- the existing
            // meaning of these two columns is untouched.
            'verified' => true,
            'status' => $seller->status === 'suspended' ? 'suspended' : 'verified',
        ], $stamp));

        $reviewerLabel = $role === 'lgu_admin' ? 'your LGU' : 'the Super Admin';

        self::notify(
            $seller,
            'seller_registration_approved',
            'Registration Approved',
            "Your hatchery registration was approved by {$reviewerLabel} ({$reviewer->name}). Your account is now verified -- you can start creating listings."
        );

        self::record($seller, $reviewer, 'seller_registration_approved', "Approved seller registration for {$seller->hatchery_name}. Seller is now verified.");

        return $seller->fresh();
    }

    /**
     * Reject the registration, with a reason. Clears verified so a seller can
     * never keep listing rights through a rejection, and records which role
     * rejected it for the audit trail.
     */
    public static function reject(SellerProfile $seller, User $reviewer, string $role, string $reason): SellerProfile
    {
        $stamp = $role === 'lgu_admin'
            ? ['lgu_reviewed_at' => now(), 'lgu_reviewed_by' => $reviewer->id]
            : ['super_admin_reviewed_at' => now(), 'super_admin_reviewed_by' => $reviewer->id];

        $seller->update(array_merge([
            'approval_status' => self::REJECTED,
            'registration_rejection_reason' => $reason,
            'rejected_by_role' => $role,
            'verified' => false,
            'status' => $seller->status === 'suspended' ? 'suspended' : 'pending',
        ], $stamp));

        $reviewerLabel = $role === 'lgu_admin' ? 'your LGU' : 'the Super Admin';

        self::notify(
            $seller,
            'seller_registration_rejected',
            'Registration Rejected',
            "Your hatchery registration was rejected by {$reviewerLabel}. Reason: {$reason} You can update your hatchery profile and contact them to have it reviewed again."
        );

        self::record($seller, $reviewer, 'seller_registration_rejected', "Rejected seller registration for {$seller->hatchery_name} -- {$reason}");

        return $seller->fresh();
    }

    /**
     * Notifications are created fresh every time (not firstOrCreate) -- a
     * seller who goes through reject-then-approve must see each decision.
     */
    private static function notify(SellerProfile $seller, string $type, string $title, string $body): void
    {
        if (! $seller->user_id) {
            return;
        }

        AppNotification::create([
            'user_id' => $seller->user_id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
        ]);
    }

    private static function record(SellerProfile $seller, User $reviewer, string $action, string $description): void
    {
        ActivityLog::record([
            'actor_id' => $reviewer->id,
            'actor_role' => $reviewer->role,
            'action' => $action,
            'target_user_id' => $seller->user_id,
            'municipality_id' => $seller->municipality_id,
            'description' => $description,
        ]);
    }
}
