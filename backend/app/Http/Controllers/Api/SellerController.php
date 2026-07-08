<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FingerlingListing;
use App\Models\Order;
use App\Models\SellerProfile;
use Illuminate\Http\Request;

class SellerController extends Controller
{
    public function dashboard(Request $request)
    {
        $seller = SellerProfile::where('user_id', $request->user()->id)->firstOrFail();

        return response()->json([
            'seller' => $seller,
            'active_listings' => FingerlingListing::where('seller_profile_id', $seller->id)->where('approval_status', 'approved')->count(),
            'pending_orders' => Order::where('seller_profile_id', $seller->id)->whereIn('status', ['placed', 'paid', 'confirmed'])->count(),
            'total_sales' => Order::where('seller_profile_id', $seller->id)->where('status', 'completed')->sum('total_amount'),
            'ratings' => $seller->rating,
            'listings' => FingerlingListing::with('media')->where('seller_profile_id', $seller->id)->latest()->get(),
            'orders' => Order::with(['listing', 'payment'])->where('seller_profile_id', $seller->id)->latest()->get(),
        ]);
    }

    public function analytics(Request $request)
    {
        $seller = SellerProfile::where('user_id', $request->user()->id)->firstOrFail();

        return response()->json([
            'total_completed_sales' => Order::where('seller_profile_id', $seller->id)->where('status', 'completed')->sum('total_amount'),
            'order_status_breakdown' => Order::where('seller_profile_id', $seller->id)->selectRaw('status, count(*) as total')->groupBy('status')->get(),
            'top_species' => FingerlingListing::where('seller_profile_id', $seller->id)->selectRaw('species, sum(quantity) as quantity')->groupBy('species')->get(),
        ]);
    }
}
