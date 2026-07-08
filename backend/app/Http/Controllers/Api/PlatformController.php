<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FingerlingListing;
use App\Models\MockPayment;
use App\Models\Order;
use App\Models\Review;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Http\Request;

class PlatformController extends Controller
{
    public function lguAdmins()
    {
        return response()->json(User::where('role', 'lgu_admin')->with('municipality')->get());
    }

    public function superReports()
    {
        $pendingPayments = MockPayment::whereIn('status', ['held', 'paid_held']);
        return response()->json([
            'total_lgus' => User::where('role', 'lgu_admin')->distinct('municipality_id')->count('municipality_id'),
            'total_sellers' => SellerProfile::count(),
            'total_buyers' => User::where('role', 'buyer')->count(),
            'total_listings' => FingerlingListing::count(),
            'total_transactions' => Order::count(),
            'pending_payouts' => $pendingPayments->count(),
            'transactions' => Order::with(['listing', 'payment'])->latest()->take(10)->get(),
            'lgu_admins' => User::where('role', 'lgu_admin')->with('municipality')->get(),
        ]);
    }

    public function lguReviews(Request $request)
    {
        $municipalityId = $request->user()->municipality_id;
        return response()->json(Review::whereHas('sellerProfile', fn ($q) => $q->where('municipality_id', $municipalityId))->latest()->get());
    }

    public function lguReports(Request $request)
    {
        $municipalityId = $request->user()->municipality_id;
        return response()->json([
            'registered_sellers' => SellerProfile::where('municipality_id', $municipalityId)->count(),
            'buyers' => User::where('role', 'buyer')->where('municipality_id', $municipalityId)->count(),
            'listings' => FingerlingListing::where('municipality_id', $municipalityId)->count(),
            'pending_approvals' => FingerlingListing::where('municipality_id', $municipalityId)->where('approval_status', 'pending')->count(),
        ]);
    }
}
