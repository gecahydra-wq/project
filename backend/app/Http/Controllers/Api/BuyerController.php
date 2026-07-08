<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\Order;
use App\Models\Review;
use Illuminate\Http\Request;

class BuyerController extends Controller
{
    public function dashboard(Request $request)
    {
        $buyerId = $request->user()->id;
        $notifications = AppNotification::where('user_id', $buyerId)
            ->whereNull('read_at')
            ->latest()
            ->get();

        return response()->json([
            'active_orders' => Order::where('buyer_id', $buyerId)->whereIn('status', ['placed', 'paid', 'confirmed', 'in_transit'])->count(),
            'completed_orders' => Order::where('buyer_id', $buyerId)->where('status', 'completed')->count(),
            'saved_listings' => 15,
            'unread_messages' => 0,
            'notifications' => $notifications,
            'recent_orders' => Order::with(['listing', 'payment'])->where('buyer_id', $buyerId)->latest()->take(5)->get(),
            'recent_reviews' => Review::where('buyer_id', $buyerId)->latest()->take(5)->get(),
        ]);
    }

    public function notifications(Request $request)
    {
        return response()->json(
            AppNotification::where('user_id', $request->user()->id)
                ->whereNull('read_at')
                ->latest()
                ->get()
        );
    }

    public function markNotificationRead(Request $request, AppNotification $notification)
    {
        if ($notification->user_id !== $request->user()->id) {
            return response()->json(['message' => 'You can only update your own notifications.'], 403);
        }

        $notification->update(['read_at' => now()]);

        return response()->json($notification);
    }
}
