<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Review;
use App\Models\SellerProfile;

class SellerProfileController extends Controller
{
    public function index()
    {
        return response()->json(
            SellerProfile::with(['user', 'municipality'])
                ->where('status', '!=', 'suspended')
                ->withCount(['listings' => fn ($q) => $q->where('approval_status', 'approved')])
                ->get()
        );
    }

    public function show(SellerProfile $seller)
    {
        abort_if($seller->status === 'suspended', 404);

        $seller->load(['user', 'municipality']);

        return response()->json([
            'seller' => $seller,
            'listings' => $seller->listings()->where('approval_status', 'approved')->with('media')->latest()->get(),
            'reviews' => Review::where('seller_profile_id', $seller->id)->with('buyer')->latest()->get(),
            'completed_sales' => Order::where('seller_profile_id', $seller->id)->where('status', 'completed')->count(),
        ]);
    }
}
