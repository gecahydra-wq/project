<?php

namespace App\Support;

use App\Models\BuyerProfile;
use App\Models\BuyerRating;
use App\Models\Review;
use App\Models\User;

/**
 * Moderation of feedback -- lets an LGU Admin / Super Admin remove a review
 * (buyer -> seller) or a rating (seller -> buyer) that isn't fair to either
 * party. Deleting one always recomputes the affected party's cached aggregate
 * (seller_profiles.rating or buyer_profiles.rating/ratings_count) and records
 * the removal in the activity trail, so the numbers and the audit log stay
 * correct. Scope/permission is enforced by the calling controller.
 */
class ReviewModeration
{
    public static function deleteReview(Review $review, User $actor): void
    {
        $review->loadMissing(['order', 'sellerProfile']);
        $sellerProfileId = $review->seller_profile_id;
        $sellerUserId = $review->sellerProfile?->user_id;
        $municipalityId = $review->sellerProfile?->municipality_id;
        $orderNumber = $review->order?->order_number;

        $review->delete();

        // Recompute the seller's average from the reviews that remain (0 when
        // none are left), through the same path ReviewController uses on
        // create -- which also re-runs the low-rating check, since removing an
        // unfair review can move a seller back above or below the threshold.
        SellerReputation::refreshAverage($sellerProfileId, $actor);

        ActivityLog::record([
            'actor_id' => $actor->id,
            'actor_role' => $actor->role,
            'action' => 'review_removed',
            'target_user_id' => $sellerUserId,
            'municipality_id' => $municipalityId,
            'reference_type' => $orderNumber ? 'ORD' : null,
            'reference_number' => $orderNumber,
            'description' => 'Removed a buyer review'.($orderNumber ? " for order {$orderNumber}" : '').'.',
        ]);
    }

    public static function deleteBuyerRating(BuyerRating $rating, User $actor): void
    {
        $rating->loadMissing(['order', 'sellerProfile']);
        $buyerId = $rating->buyer_id;
        $municipalityId = $rating->sellerProfile?->municipality_id;
        $orderNumber = $rating->order?->order_number;

        $rating->delete();
        self::refreshBuyerRating($buyerId);

        ActivityLog::record([
            'actor_id' => $actor->id,
            'actor_role' => $actor->role,
            'action' => 'buyer_rating_removed',
            'target_user_id' => $buyerId,
            'municipality_id' => $municipalityId,
            'reference_type' => $orderNumber ? 'ORD' : null,
            'reference_number' => $orderNumber,
            'description' => 'Removed a seller rating of a buyer'.($orderNumber ? " for order {$orderNumber}" : '').'.',
        ]);
    }

    /**
     * Recompute and cache a buyer's average rating + count on their
     * buyer_profile. Shared by SellerController::rateBuyer (on create) and the
     * removals above, so there's one source of truth for the maths.
     */
    public static function refreshBuyerRating(int $buyerId): void
    {
        $profile = BuyerProfile::where('user_id', $buyerId)->first();
        if (! $profile) {
            return;
        }

        $ratings = BuyerRating::where('buyer_id', $buyerId);
        $profile->update([
            'rating' => $ratings->exists() ? round((float) $ratings->avg('rating'), 2) : null,
            'ratings_count' => $ratings->count(),
        ]);
    }
}
