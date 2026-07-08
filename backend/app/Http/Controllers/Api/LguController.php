<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FingerlingListing;
use App\Models\SellerProfile;
use Illuminate\Http\Request;

class LguController extends Controller
{
    public function dashboard(Request $request)
    {
        $municipalityId = $request->user()->municipality_id;
        $sellerQuery = SellerProfile::query()->when($municipalityId, fn ($q) => $q->where('municipality_id', $municipalityId));
        $listingQuery = FingerlingListing::query()->when($municipalityId, fn ($q) => $q->where('municipality_id', $municipalityId));

        return response()->json([
            'registered_sellers' => $sellerQuery->count(),
            'active_listings' => (clone $listingQuery)->where('approval_status', 'approved')->count(),
            'pending_approvals' => (clone $listingQuery)->where('approval_status', 'pending')->with(['sellerProfile', 'municipality'])->get(),
        ]);
    }

    public function approveListing(Request $request, FingerlingListing $listing)
    {
        if ($listing->municipality_id !== $request->user()->municipality_id) {
            return response()->json(['message' => 'LGU admins can only approve listings in their municipality.'], 403);
        }

        $listing->update(['approval_status' => 'approved']);

        return response()->json($listing->load(['sellerProfile', 'municipality']));
    }

    public function rejectListing(Request $request, FingerlingListing $listing)
    {
        if ($listing->municipality_id !== $request->user()->municipality_id) {
            return response()->json(['message' => 'LGU admins can only reject listings in their municipality.'], 403);
        }

        $listing->update(['approval_status' => 'rejected']);

        return response()->json($listing->load(['sellerProfile', 'municipality']));
    }

    public function verifySeller(Request $request, SellerProfile $seller)
    {
        if ($seller->municipality_id !== $request->user()->municipality_id) {
            return response()->json(['message' => 'LGU admins can only verify sellers in their municipality.'], 403);
        }

        $seller->update(['verified' => true, 'status' => 'verified']);

        return response()->json($seller);
    }
}
