<?php

namespace App\Support;

use App\Models\AppNotification;
use App\Models\Order;
use App\Models\Review;
use App\Models\SellerNotice;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\UserReport;

/**
 * Automatic low-rating detection.
 *
 * Every time a seller's cached average changes -- a new buyer review, or an
 * admin removing one -- refreshAverage() recomputes it. A Notice to Explain is
 * raised only when a NEW review of LOW_REVIEW_MAX stars or fewer leaves the
 * average BELOW LOW_RATING_THRESHOLD: a good review never triggers one (even
 * while older bad reviews keep the average low), an average of exactly 3.00
 * is fine, and removing a review never raises one. The seller and every LGU
 * Admin in their municipality are notified, and the notice appears on the
 * LGU's Notices to Explain dashboard, listing the low reviews behind it.
 *
 * A notice is also raised when an LGU Admin or the Super Admin finds a
 * buyer's User Report against the seller valid (raiseReportNotice()).
 *
 * This class DETECTS; App\Support\SellerSanctions carries the consequences.
 * Every notice works the same, first or tenth (user decision, 2026-10-09):
 * the seller is not suspended and their listings stay up while they send one
 * explanation. Only a REJECTED explanation has a consequence -- suspension
 * (SellerSanctions::rejectExplanation).
 *
 * Only one open notice exists per seller at a time, so a bad week produces one
 * case for the LGU to work rather than one per review. Once the LGU closes it,
 * a later bad review can raise a fresh notice. A suspended seller gets no new
 * notices: they are already past the point of explaining, and talk to their
 * LGU or support instead.
 */
class SellerReputation
{
    /** Below this average (strictly), a bad review raises a Notice to Explain. */
    public const LOW_RATING_THRESHOLD = 3.0;

    /** A review of this many stars or fewer counts as a bad review. */
    public const LOW_REVIEW_MAX = 3;

    /**
     * Recompute a seller's cached average from their reviews and run the
     * low-rating check. This is the single write path for
     * seller_profiles.rating -- ReviewController (on create) and
     * ReviewModeration (on removal) both come through here.
     *
     * @param  ?User  $actor  Whoever caused the change, for the audit trail.
     * @param  ?int  $newRating  The stars of the review just posted; null when
     *                           the average changed because a review was removed.
     * @return float  The seller's new average (0 when they have no reviews).
     */
    public static function refreshAverage(int $sellerProfileId, ?User $actor = null, ?int $newRating = null): float
    {
        $reviews = Review::where('seller_profile_id', $sellerProfileId);
        $count = $reviews->count();
        $average = $count ? round((float) $reviews->avg('rating'), 2) : 0.0;

        SellerProfile::where('id', $sellerProfileId)->update(['rating' => $average]);

        // Only a new bad review that leaves the average below the threshold
        // counts. A seller with no reviews (average 0) has no rating at all.
        if ($count > 0 && $newRating !== null && $newRating <= self::LOW_REVIEW_MAX && $average < self::LOW_RATING_THRESHOLD) {
            $seller = SellerProfile::with('user')->find($sellerProfileId);
            if ($seller) {
                self::raiseLowRatingNotice($seller, $average, $count, $actor);
            }
        }

        return $average;
    }

    /**
     * Issue a Notice to Explain, unless one is already open for this seller
     * or they are already suspended.
     *
     * @return ?SellerNotice  The new notice, or null when none was raised.
     */
    public static function raiseLowRatingNotice(SellerProfile $seller, float $average, int $count, ?User $actor = null): ?SellerNotice
    {
        if ($seller->status === 'suspended') {
            return null;
        }

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
                'Average buyer rating has fallen to %.2f/5 across %d review%s, below the %.1f-star threshold. The seller has been asked to explain.',
                $average,
                $count,
                $count === 1 ? '' : 's',
                self::LOW_RATING_THRESHOLD
            ),
            'status' => 'open',
        ]);

        $why = sprintf(
            'Your average buyer rating is now %.2f/5 across %d review%s, which is below the %.1f-star threshold. The notice lists the reviews of %d stars or fewer behind it.',
            $average,
            $count,
            $count === 1 ? '' : 's',
            self::LOW_RATING_THRESHOLD,
            self::LOW_REVIEW_MAX
        );
        // A seller who already had an explanation rejected does not get to
        // explain again -- the system suspends them (no person acted).
        $repeat = SellerSanctions::isRepeatOffender($seller->id);
        if ($repeat) {
            SellerSanctions::suspendForRepeatOffense($notice, $seller, null, $why);
        } else {
            self::notifySeller($seller, $why.' Your LGU has been notified and has asked you to explain.', 'Notice to Explain -- Low Rating');
        }
        self::notifyLguAdmins($seller, 'seller_low_rating', 'Seller Flagged for Low Rating', sprintf(
            $repeat
                ? '%s now averages %.2f/5 across %d review%s. They already had an explanation rejected, so this repeat offense suspended them automatically. Reinstate them from the Sellers tab if they contact you and you agree.'
                : '%s now averages %.2f/5 across %d review%s. A Notice to Explain has been issued -- review it under Notices to Explain and decide what action, if any, to take.',
            $seller->hatchery_name,
            $average,
            $count,
            $count === 1 ? '' : 's'
        ));

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

    /**
     * A reviewer found a buyer's report against this seller valid, so the
     * seller is asked to explain -- the same notice, and the same
     * consequences, as a low rating. Refused while another notice is open:
     * one case at a time keeps the seller's answer about one thing.
     */
    public static function raiseReportNotice(SellerProfile $seller, UserReport $report, User $actor, string $findings): SellerNotice
    {
        abort_if(
            $seller->status === 'suspended',
            422,
            'This seller is already suspended, so a Notice to Explain cannot be sent. Resolve the report without one.'
        );
        abort_if(
            SellerNotice::where('seller_profile_id', $seller->id)->whereIn('status', SellerNotice::OPEN_STATUSES)->exists(),
            422,
            'This seller already has an open Notice to Explain. Decide that one first, or mention this report in it.'
        );

        $notice = SellerNotice::create([
            'seller_profile_id' => $seller->id,
            'municipality_id' => $seller->municipality_id,
            'type' => 'user_report',
            'average_rating' => null,
            'ratings_count' => 0,
            'details' => sprintf('A buyer reported this seller for "%s" (report #%d). The report was found valid: %s', $report->reason, $report->id, $findings),
            'status' => 'open',
        ]);

        $why = sprintf(
            '%s reviewed a buyer\'s report against you ("%s") and found it valid: %s',
            SellerSanctions::reviewerLabel($actor),
            $report->reason,
            $findings
        );
        $repeat = SellerSanctions::isRepeatOffender($seller->id);
        if ($repeat) {
            SellerSanctions::suspendForRepeatOffense($notice, $seller, $actor, $why);
        } else {
            self::notifySeller($seller, $why.' You have been asked to explain.', 'Notice to Explain -- Buyer Report');
        }
        self::notifyLguAdmins($seller, 'seller_report_notice', 'Notice to Explain Issued', sprintf(
            $repeat
                ? '%s issued %s a Notice to Explain after finding a buyer\'s report valid. They already had an explanation rejected, so this repeat offense suspended them automatically. Reinstate them from the Sellers tab if they contact you and you agree.'
                : '%s issued %s a Notice to Explain after finding a buyer\'s report valid. Review the answer under Notices to Explain.',
            $actor->name,
            $seller->hatchery_name
        ), $actor->id);

        ActivityLog::record([
            'actor_id' => $actor->id,
            'actor_role' => $actor->role,
            'action' => 'seller_notice_issued',
            'target_user_id' => $seller->user_id,
            'municipality_id' => $seller->municipality_id,
            'description' => sprintf('Notice to Explain issued to %s -- buyer report #%d found valid.', $seller->hatchery_name, $report->id),
        ]);

        return $notice;
    }

    /**
     * The seller's reviews of LOW_REVIEW_MAX stars or fewer up to when a
     * low-rating notice was raised, newest first -- the "why" behind it, so
     * the seller knows what to explain and the reviewer what to judge.
     */
    public static function lowReviewsFor(SellerNotice $notice): array
    {
        if ($notice->type !== 'low_rating') {
            return [];
        }

        return Review::with('order:id,order_number', 'buyer:id,name')
            ->where('seller_profile_id', $notice->seller_profile_id)
            ->where('rating', '<=', self::LOW_REVIEW_MAX)
            ->where('created_at', '<=', $notice->created_at)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Review $review) => [
                'id' => $review->id,
                'rating' => (int) $review->rating,
                'title' => $review->title,
                'comment' => $review->comment,
                'created_at' => $review->created_at,
                'order_number' => $review->order?->order_number,
                'buyer_name' => $review->buyer?->name,
            ])
            ->all();
    }

    /**
     * Tell the seller about every new review, 1 to 5 stars. The buyer is named
     * because reviews are already public with the buyer's name on the
     * seller's profile. The type carries the seller profile and review ids so
     * the notification can open that exact review (see notificationLinkFor).
     */
    public static function notifyNewReview(Review $review, Order $order, User $buyer, float $average): void
    {
        $seller = SellerProfile::find($review->seller_profile_id);
        if (! $seller?->user_id) {
            return;
        }

        $rating = (int) $review->rating;
        $count = Review::where('seller_profile_id', $seller->id)->count();
        $comment = trim((string) ($review->comment ?: $review->title));

        AppNotification::create([
            'user_id' => $seller->user_id,
            'type' => "review_received:{$seller->id}:{$review->id}",
            'title' => sprintf('New Review: %s %d/5', str_repeat('★', $rating).str_repeat('☆', 5 - $rating), $rating),
            'body' => sprintf(
                '%s rated you %d out of 5 for order #%s.%s Your average is now %.2f/5 across %d review%s.',
                $buyer->name,
                $rating,
                $order->order_number,
                $comment !== '' ? " \"{$comment}\"" : '',
                $average,
                $count,
                $count === 1 ? '' : 's'
            ),
        ]);
    }

    private static function notifySeller(SellerProfile $seller, string $why, string $title): void
    {
        if (! $seller->user_id) {
            return;
        }

        AppNotification::create([
            'user_id' => $seller->user_id,
            'type' => 'seller_notice_to_explain',
            'title' => $title,
            'body' => sprintf(
                '%s Open the Notices tab on your dashboard to respond. Your listings stay on the marketplace while you explain. Your account has not been suspended, but it will be if your explanation is rejected. You can send one explanation, so make it complete.',
                $why
            ),
        ]);
    }

    /**
     * Every LGU Admin of the seller's municipality gets the notification --
     * a municipality can have more than one, and the notice belongs to the
     * office rather than to whoever happens to be logged in.
     */
    private static function notifyLguAdmins(SellerProfile $seller, string $type, string $title, string $body, ?int $exceptUserId = null): void
    {
        if (! $seller->municipality_id) {
            return;
        }

        $admins = User::where('role', 'lgu_admin')
            ->where('municipality_id', $seller->municipality_id)
            ->when($exceptUserId, fn ($query) => $query->whereKeyNot($exceptUserId))
            ->get();

        foreach ($admins as $admin) {
            AppNotification::create([
                'user_id' => $admin->id,
                'type' => $type,
                'title' => $title,
                'body' => $body,
            ]);
        }
    }
}
