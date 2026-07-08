<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MockPayment;
use App\Models\Order;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Support\Carbon;

class SuperAdminController extends Controller
{
    public function dashboard()
    {
        $pending = MockPayment::whereIn('status', ['held', 'paid_held']);

        return response()->json([
            'lgu_admins' => User::where('role', 'lgu_admin')->count(),
            'total_sellers' => SellerProfile::count(),
            'held_in_escrow' => (clone $pending)->sum('amount'),
            'pending_payouts' => (clone $pending)->with('order')->get(),
            'transactions' => Order::with(['listing', 'payment'])->latest()->get(),
        ]);
    }

    public function releasePayment(MockPayment $payment)
    {
        $payment->update([
            'status' => 'released',
            'released_at' => Carbon::now(),
        ]);

        return response()->json($payment->fresh());
    }
}
