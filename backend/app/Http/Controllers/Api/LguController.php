<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\FingerlingListing;
use App\Models\MockPayment;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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
            'notifications' => AppNotification::where('user_id', $request->user()->id)->whereNull('read_at')->latest()->get(),
        ]);
    }

    public function markNotificationRead(Request $request, AppNotification $notification)
    {
        if ($notification->user_id !== $request->user()->id) {
            return response()->json(['message' => 'You can only update your own notifications.'], 403);
        }

        $notification->update(['read_at' => now()]);

        return response()->json($notification);
    }

    public function show(Request $request, FingerlingListing $listing)
    {
        if ($listing->municipality_id !== $request->user()->municipality_id) {
            return response()->json(['message' => 'LGU admins can only review listings in their municipality.'], 403);
        }

        return response()->json($listing->load(['sellerProfile.user', 'municipality', 'media']));
    }

    public function approveListing(Request $request, FingerlingListing $listing)
    {
        if ($listing->municipality_id !== $request->user()->municipality_id) {
            return response()->json(['message' => 'LGU admins can only approve listings in their municipality.'], 403);
        }

        $listing->update(['approval_status' => 'approved', 'rejection_reason' => null]);

        return response()->json($listing->load(['sellerProfile', 'municipality']));
    }

    public function rejectListing(Request $request, FingerlingListing $listing)
    {
        if ($listing->municipality_id !== $request->user()->municipality_id) {
            return response()->json(['message' => 'LGU admins can only reject listings in their municipality.'], 403);
        }

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $listing->update(['approval_status' => 'rejected', 'rejection_reason' => $data['reason'] ?? null]);

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

    public function sellers(Request $request)
    {
        return response()->json(
            SellerProfile::with('user')
                ->where('municipality_id', $request->user()->municipality_id)
                ->latest()
                ->get()
        );
    }

    public function suspendSeller(Request $request, SellerProfile $seller)
    {
        if ($seller->municipality_id !== $request->user()->municipality_id) {
            return response()->json(['message' => 'LGU admins can only suspend sellers in their municipality.'], 403);
        }

        $seller->update(['status' => 'suspended']);
        $seller->user?->tokens()->delete();

        return response()->json($seller);
    }

    public function reinstateSeller(Request $request, SellerProfile $seller)
    {
        if ($seller->municipality_id !== $request->user()->municipality_id) {
            return response()->json(['message' => 'LGU admins can only reinstate sellers in their municipality.'], 403);
        }

        $seller->update(['status' => $seller->verified ? 'verified' : 'pending']);

        return response()->json($seller);
    }

    public function users(Request $request)
    {
        $municipalityId = $request->user()->municipality_id;

        return response()->json([
            'buyers' => User::where('role', 'buyer')->where('municipality_id', $municipalityId)->get(),
            'sellers' => User::where('role', 'seller')->where('municipality_id', $municipalityId)->get(),
        ]);
    }

    public function pendingEarnings(Request $request)
    {
        $municipalityId = $request->user()->municipality_id;

        return response()->json(
            MockPayment::where('status', 'paid_held')
                ->whereHas('order', fn ($q) => $q
                    ->where('status', 'completed')
                    ->whereHas('sellerProfile', fn ($q2) => $q2->where('municipality_id', $municipalityId)))
                ->with(['order.sellerProfile.user', 'order.buyer', 'order.listing'])
                ->latest()
                ->get()
        );
    }

    public function approveEarnings(Request $request, MockPayment $payment)
    {
        $payment->load('order.sellerProfile');
        $order = $payment->order;

        abort_if(! $order || $order->sellerProfile?->municipality_id !== $request->user()->municipality_id, 403, 'You can only approve earnings for sellers in your municipality.');
        abort_unless($order->status === 'completed', 422, 'Only completed (delivered) orders are eligible for earnings approval.');
        abort_unless($payment->status === 'paid_held', 422, 'This payment is not awaiting approval.');

        $payment->update(['status' => 'released', 'released_at' => Carbon::now()]);

        AppNotification::firstOrCreate([
            'user_id' => $order->sellerProfile->user_id,
            'type' => 'earnings_approved',
            'title' => 'Earnings Approved',
            'body' => sprintf(
                'Your LGU has approved ₱%s in earnings from order #%s. The amount is now available for withdrawal.',
                number_format((float) $payment->amount, 2),
                $order->order_number
            ),
        ]);

        AppNotification::where('type', "earnings_pending_approval:{$payment->id}")
            ->whereNull('read_at')
            ->update(['read_at' => Carbon::now()]);

        return response()->json($payment->fresh());
    }
}
