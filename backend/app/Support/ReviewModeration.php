<?php

namespace App\Support;

use App\Models\BuyerProfile;
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
        // create. No new rating is passed, so a removal never raises a Notice
        // to Explain -- only a newly posted bad review can.
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


}
