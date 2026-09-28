<?php

namespace App\Support;

use App\Models\AppNotification;
use App\Models\Review;
use App\Models\SellerNotice;
use App\Models\SellerProfile;
use App\Models\User;

/**
 * Automatic low-rating detection.
 *
 * Every time a seller's cached average changes -- a new buyer review, or an
 * admin removing one -- refreshAverage() recomputes it and, if it has fallen
 * to LOW_RATING_THRESHOLD or below, raises a Notice to Explain: the seller and
 * every LGU Admin in their municipality are notified, and the notice appears
 * on the LGU's Notices to Explain dashboard.
 *
 * This class DETECTS; App\Support\SellerSanctions carries the consequences.
 * A seller's FIRST notice is a warning only -- their listings stay up while
 * they explain. From the second notice onward the listings are frozen until
 * the LGU accepts the explanation. Nothing here ever suspends an account:
 * seller_profiles.status is untouched and tokens are never revoked, so the
 * seller can always sign in, answer, and finish orders already placed.
 * Suspension stays a manual LGU/Super Admin judgement.
 *
 * Only one open notice exists per seller at a time, so a bad week produces one
 * case for the LGU to work rather than one per review. Once the LGU closes it,
 * a later drop can raise a fresh notice.
 */
class SellerReputation
{
    /** At or below this average, a Notice to Explain is raised. */
    public const LOW_RATING_THRESHOLD = 3.0;

    /**
     * Recompute a seller's cached average from their reviews and run the
     * low-rating check. This is the single write path for
     * seller_profiles.rating -- ReviewController (on create) and
     * ReviewModeration (on removal) both come through here.
     *
     * @param  ?User  $actor  Whoever caused the change, for the audit trail.
     * @return float  The seller's new average (0 when they have no reviews).
     */
    public static function refreshAverage(int $sellerProfileId, ?User $actor = null): float
    {
        $reviews = Review::where('seller_profile_id', $sellerProfileId);
        $count = $reviews->count();
        $average = $count ? round((float) $reviews->avg('rating'), 2) : 0.0;

        SellerProfile::where('id', $sellerProfileId)->update(['rating' => $average]);

        // A seller with no reviews yet has an average of 0, which is not a bad
        // rating -- it is no rating. Only judge sellers who have been rated.
        if ($count > 0 && $average <= self::LOW_RATING_THRESHOLD) {
            $seller = SellerProfile::with('user')->find($sellerProfileId);
            if ($seller) {
                self::raiseLowRatingNotice($seller, $average, $count, $actor);
            }
        }

        return $average;
    }

    /**
     * Issue a Notice to Explain, unless one is already open for this seller.
     *
     * @return ?SellerNotice  The new notice, or null when one was already open.
     */
    public static function raiseLowRatingNotice(SellerProfile $seller, float $average, int $count, ?User $actor = null): ?SellerNotice
    {
        $alreadyOpen = SellerNotice::where('seller_profile_id', $seller->id)
            ->where('type', 'low_rating')
            ->whereIn('status', SellerNotice::OPEN_STATUSES)
            ->exists();

        if ($alreadyOpen) {
            return null;
        }

        $notice = SellerNotice::create([
            'seller_profile_id' => $seller->id,
            'municipality_id' => $seller->municipality_id,
            'type' => 'low_rating',
            'average_rating' => $average,
            'ratings_count' => $count,
            'details' => sprintf(
                'Average buyer rating has fallen to %.2f/5 across %d review%s, at or below the %.1f-star threshold. The seller has been asked to explain.',
                $average,
                $count,
                $count === 1 ? '' : 's',
                self::LOW_RATING_THRESHOLD
            ),
            'status' => 'open',
        ]);

        // The FIRST notice is a warning: the seller keeps selling while they
        // explain. From the second onward the shop comes down until the LGU
        // accepts. See SellerSanctions::FREEZE_FROM_NOTICE.
        $noticeNumber = SellerSanctions::noticeCount($seller->id);
        $frozen = $noticeNumber >= SellerSanctions::FREEZE_FROM_NOTICE;

        if ($frozen) {
            SellerSanctions::freezeListings($seller);
        }

        self::notifySeller($seller, $average, $count, $frozen);
        self::notifyLguAdmins($seller, $average, $count);

        ActivityLog::record([
            'actor_id' => $actor?->id,
            'actor_role' => $actor?->role ?? 'system',
            'action' => 'seller_notice_issued',
            'target_user_id' => $seller->user_id,
            'municipality_id' => $seller->municipality_id,
            'description' => sprintf(
                'Notice to Explain issued to %s -- average rating %.2f/5 across %d review%s.',
                $seller->hatchery_name,
                $average,
                $count,
                $count === 1 ? '' : 's'
            ),
        ]);

        return $notice;
    }

    private static function notifySeller(SellerProfile $seller, float $average, int $count, bool $frozen = false): void
    {
        if (! $seller->user_id) {
            return;
        }

        AppNotification::create([
            'user_id' => $seller->user_id,
            'type' => 'seller_notice_to_explain',
            'title' => 'Notice to Explain -- Low Rating',
            'body' => sprintf(
                'Your average buyer rating is now %.2f/5 across %d review%s, which is at or below the %.1f-star threshold. Your LGU has been notified and has asked you to explain. Open the Notices tab on your dashboard to respond. %s Your account has NOT been suspended.',
                $average,
                $count,
                $count === 1 ? '' : 's',
                self::LOW_RATING_THRESHOLD,
                $frozen
                    ? 'Because this is not your first notice, your listings have been taken off the marketplace until your LGU accepts your explanation. You can still sign in, reply to buyers and complete orders already placed.'
                    : 'This is your first notice, so your listings stay on the marketplace while you explain.'
            ),
        ]);
    }

    /**
     * Every LGU Admin of the seller's municipality gets the notification --
     * a municipality can have more than one, and the notice belongs to the
     * office rather than to whoever happens to be logged in.
     */
    private static function notifyLguAdmins(SellerProfile $seller, float $average, int $count): void
    {
        if (! $seller->municipality_id) {
            return;
        }

        $admins = User::where('role', 'lgu_admin')
            ->where('municipality_id', $seller->municipality_id)
            ->get();

        foreach ($admins as $admin) {
            AppNotification::create([
                'user_id' => $admin->id,
                'type' => 'seller_low_rating',
                'title' => 'Seller Flagged for Low Rating',
                'body' => sprintf(
                    '%s now averages %.2f/5 across %d review%s. A Notice to Explain has been issued -- review it under Notices to Explain and decide what action, if any, to take.',
                    $seller->hatchery_name,
                    $average,
                    $count,
                    $count === 1 ? '' : 's'
                ),
            ]);
        }
    }
}
