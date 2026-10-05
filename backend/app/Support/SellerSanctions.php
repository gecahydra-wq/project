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
 *  2. SUSPENSION -- never automatic. Nothing in this class suspends an
 *     account. Whether a seller's conduct warrants suspension is a judgement
 *     an LGU Admin or the Super Admin makes from the Sellers tab, and they can
 *     make it after a single notice or never. A rating can fall because a
 *     buyer was trolling, and a rule that suspended on a count alone would
 *     punish the seller for that.
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
     * and the third one suspends the account.
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

        // Deliberately does NOT freeze and does NOT suspend. Whether the shop
        // stays open is decided by which notice this is (see
        // SellerReputation::raiseLowRatingNotice), and suspension is the LGU's
        // judgement call from the Sellers tab -- available to them after one
        // rejected explanation or never. An automatic sanction here would
        // punish a seller for buyers who were simply trolling.
        $offenses = self::offenseCount($seller->id);

        self::notifySeller($seller, 'seller_notice_rejected', 'Explanation Rejected', sprintf(
            '%s rejected your explanation. This is recorded as offense %d against your account. Reason: %s',
            self::reviewerLabel($actor),
            $offenses,
            $reason
        ));

        ActivityLog::record([
            'actor_id' => $actor->id,
            'actor_role' => $actor->role,
            'action' => 'seller_notice_rejected',
            'target_user_id' => $seller->user_id,
            'municipality_id' => $notice->municipality_id,
            'description' => sprintf(
                'Rejected %s\'s explanation -- offense %d on record. No automatic sanction applied.',
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
