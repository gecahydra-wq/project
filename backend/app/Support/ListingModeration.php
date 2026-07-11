<?php

namespace App\Support;

use App\Models\AppNotification;
use App\Models\FingerlingListing;
use App\Models\User;
use Illuminate\Support\Carbon;

class ListingModeration
{
    /**
     * Notify the seller that an admin (LGU or Super Admin) archived or deleted
     * their listing. Called before the listing itself is deleted, so callers
     * must load sellerProfile first.
     */
    public static function notifySellerOfRemoval(FingerlingListing $listing, string $action, ?string $reason, User $admin): void
    {
        $sellerUserId = $listing->sellerProfile->user_id;
        $listingName = $listing->title ?: $listing->species;

        AppNotification::firstOrCreate([
            'user_id' => $sellerUserId,
            'type' => "listing_{$action}",
            'title' => $action === 'deleted' ? 'Listing Removed' : 'Listing Archived',
            'body' => sprintf(
                'Your listing "%s" was %s by %s on %s.%s',
                $listingName,
                $action,
                $admin->name,
                Carbon::now()->format('M d, Y'),
                $reason ? " Reason: {$reason}" : ''
            ),
        ]);
    }
}
