<?php

namespace App\Support;

use App\Models\AppNotification;
use App\Models\SellerNotice;
use App\Models\SellerProfile;
use App\Models\User;

/**
 * What actually happens to a seller as a result of a Notice to Explain.
 *
 * SellerReputation raises the notice; this class carries the consequences, so
 * the "detect" and "punish" halves stay separable and each has one owner.
 *
 * Two distinct sanctions, deliberately not the same thing:
 *
 *  1. LISTING FREEZE -- applied the moment a notice is raised. The seller's
 *     listings leave the marketplace and cannot be ordered, but the seller can
 *     still sign in, answer the notice, message buyers and fulfil orders
 *     already placed. It is reversible the moment the LGU accepts their
 *     explanation. This is the "your shop is closed while we talk" state.
 *
 *  2. SUSPENSION -- automatic when an explanation is REJECTED (the team's
 *     rule since 2026-10-09; it replaced the adviser's earlier "never
 *     auto-suspend"). Raising a notice still never suspends: only a reviewer
 *     who has read the explanation and found it wanting can trigger it. There
 *     is no in-app dispute of that decision: the seller messages their LGU or
 *     sends a support ticket, the same path a rejected registration has, and
 *     staff reinstate by hand (AccountModeration::reinstateSeller).
 *
 * THE FIRST NOTICE IS A WARNING ONLY. A seller hitting the threshold for the
 * first time keeps their listings up while they explain -- one bad run is not
 * evidence of anything. The freeze starts from their SECOND notice onward.
 *
 * An "offense" is a REJECTED explanation, never merely a notice raised. A
 * seller who is asked to explain and explains acceptably has not offended, and
 * counting those would make the LGU's accept/reject decision meaningless.
 * Offenses are recorded for the LGU's judgement; they carry no automatic
 * consequence of their own.
 */
class SellerSanctions
{
    /**
     * Which notice starts freezing the listings. The first is a warning the
     * seller can answer with their shop still open.
     */
    public const FREEZE_FROM_NOTICE = 2;

    /**
     * How many explanations this seller has had rejected. Derived from the
     * notices themselves rather than a counter column, so it can never drift
     * out of step with the record it summarises.
     */
    public static function offenseCount(int $sellerProfileId): int
    {
        return SellerNotice::where('seller_profile_id', $sellerProfileId)
            ->where('status', SellerNotice::STATUS_REJECTED)
            ->count();
    }

    /**
     * Offense counts for many sellers in one query, keyed by seller_profile_id.
     * The dashboards list every notice at once, so counting per row would be a
     * query per notice.
     *
     * @param  iterable<int>  $sellerProfileIds
     * @return array<int, int>
     */
    public static function offenseCountsFor(iterable $sellerProfileIds): array
    {
        // Accepts an array or a Collection (callers pass ->pluck(...)).
        $ids = collect($sellerProfileIds)->filter()->unique()->values()->all();
        if (! $ids) {
            return [];
        }

        return SellerNotice::whereIn('seller_profile_id', $ids)
            ->where('status', SellerNotice::STATUS_REJECTED)
            ->selectRaw('seller_profile_id, COUNT(*) as total')
            ->groupBy('seller_profile_id')
            ->pluck('total', 'seller_profile_id')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /** How many Notices to Explain this seller has ever received. */
    public static function noticeCount(int $sellerProfileId): int
    {
        return SellerNotice::where('seller_profile_id', $sellerProfileId)->count();
    }

    /**
     * Stamp each notice with its seller's running offense count, so the LGU
     * and Super Admin dashboards can show "offense 2 of 3" without the
     * frontend having to derive it.
     */
    public static function attachOffenseCounts($notices)
    {
        $counts = self::offenseCountsFor($notices->pluck('seller_profile_id'));

        return $notices->map(function ($notice) use ($counts) {
            $notice->seller_offense_count = $counts[$notice->seller_profile_id] ?? 0;

            return $notice;
        });
    }

    /** Take the seller's listings off the marketplace. Idempotent. */
    public static function freezeListings(SellerProfile $seller): void
    {
        if ($seller->listings_frozen_at) {
            return;
        }

        $seller->update(['listings_frozen_at' => now()]);
    }

    /**
     * Put the listings back. Called when the LGU accepts or dismisses a
     * notice, and when a suspended seller is reinstated -- a reinstatement
     * that left the shop frozen would not be a reinstatement.
     */
    public static function liftFreeze(SellerProfile $seller): void
    {
        if (! $seller->listings_frozen_at) {
            return;
        }

        $seller->update(['listings_frozen_at' => null]);
    }

    /**
     * The LGU is satisfied with the explanation. No offense is recorded and
     * the listings go back on the marketplace.
     */
    public static function acceptExplanation(SellerNotice $notice, User $actor, ?string $notes = null): SellerNotice
    {
        $notice->update([
            'status' => SellerNotice::STATUS_ACCEPTED,
            'lgu_notes' => $notes ?? $notice->lgu_notes,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
        ]);

        $seller = $notice->sellerProfile;
        if ($seller) {
            self::liftFreeze($seller);
            self::notifySeller($seller, 'seller_notice_accepted', 'Explanation Accepted', sprintf(
                '%s accepted your explanation. Your listings are back on the marketplace. No offense was recorded against your account.%s',
                self::reviewerLabel($actor),
                $notes ? " Notes: {$notes}" : ''
            ));
        }

        ActivityLog::record([
            'actor_id' => $actor->id,
            'actor_role' => $actor->role,
            'action' => 'seller_notice_accepted',
            'target_user_id' => $seller?->user_id,
            'municipality_id' => $notice->municipality_id,
            'description' => sprintf('Accepted %s\'s explanation for their Notice to Explain. Listings restored.', $seller?->hatchery_name ?? 'a seller'),
        ]);

        return $notice->fresh();
    }

    /**
     * The LGU is not satisfied. This is the one path that records an offense,
     * and it suspends the seller's account (see the class docblock).
     */
    public static function rejectExplanation(SellerNotice $notice, User $actor, string $reason): SellerNotice
    {
        $notice->update([
            'status' => SellerNotice::STATUS_REJECTED,
            'lgu_notes' => $reason,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
        ]);

        $seller = $notice->sellerProfile;
        if (! $seller) {
            return $notice->fresh();
        }

        $offenses = self::offenseCount($seller->id);

        self::notifySeller($seller, 'seller_notice_rejected', 'Explanation Rejected', sprintf(
            '%s rejected your explanation. This is recorded as offense %d against your account, and your seller account has been suspended. Reason: %s If you want it reviewed again, message your LGU or send a support ticket from Help & Support.',
            self::reviewerLabel($actor),
            $offenses,
            $reason
        ));

        // Suspension lifts only through a manual reinstatement
        // (AccountModeration::reinstateSeller), after the seller has messaged
        // their LGU or sent a support ticket.
        if ($seller->status !== 'suspended') {
            AccountModeration::suspendSeller(
                $seller,
                $actor,
                "Your explanation for a Notice to Explain was rejected: {$reason}",
                null,
                'If you want it reviewed again, message your LGU or send a support ticket from Help & Support.'
            );
        }

        ActivityLog::record([
            'actor_id' => $actor->id,
            'actor_role' => $actor->role,
            'action' => 'seller_notice_rejected',
            'target_user_id' => $seller->user_id,
            'municipality_id' => $notice->municipality_id,
            'description' => sprintf(
                'Rejected %s\'s explanation -- offense %d on record. Seller account suspended.',
                $seller->hatchery_name,
                $offenses
            ),
        ]);

        return $notice->fresh();
    }

    /**
     * Who decided, as the seller should read it. The Super Admin can decide
     * a notice too (as the fallback reviewer), so "Your LGU" is not always true.
     */
    public static function reviewerLabel(?User $reviewer): string
    {
        return $reviewer?->role === 'super_admin' ? 'The Super Admin' : 'Your LGU';
    }

    private static function notifySeller(SellerProfile $seller, string $type, string $title, string $body): void
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
}
