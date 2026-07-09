<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\BuyerProfile;
use App\Models\Message;
use App\Models\Order;
use App\Models\Review;
use App\Support\ImageUploader;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
            'unread_messages' => Message::where('receiver_id', $buyerId)->whereNull('read_at')->count(),
            'notifications' => $notifications,
            'recent_orders' => Order::with(['listing', 'payment', 'review', 'sellerProfile.user'])->where('buyer_id', $buyerId)->latest()->take(5)->get(),
            'recent_reviews' => Review::where('buyer_id', $buyerId)->latest()->take(5)->get(),
            'profile' => $request->user()->load('municipality'),
            'buyer_profile' => BuyerProfile::where('user_id', $buyerId)->first(),
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();
        $buyerProfile = BuyerProfile::where('user_id', $user->id)->firstOrFail();

        $data = $request->validate([
            'name' => ['sometimes', 'string'],
            'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'bio' => ['nullable', 'string'],
        ]);

        $user->update(collect($data)->only(['name', 'email', 'phone'])->all());
        $buyerProfile->update(collect($data)->only(['address', 'bio'])->all());

        return response()->json([
            'user' => $user->fresh()->load('municipality'),
            'buyer_profile' => $buyerProfile->fresh(),
        ]);
    }

    public function uploadProfilePicture(Request $request)
    {
        $request->validate(['photo' => array_merge(['required'], ImageUploader::validationRules())]);

        $user = $request->user();
        ImageUploader::delete($user->profile_picture);
        $user->update(['profile_picture' => ImageUploader::store($request->file('photo'), 'profile-pictures/buyers')]);

        return response()->json($user->fresh()->load('municipality'));
    }

    public function removeProfilePicture(Request $request)
    {
        $user = $request->user();
        ImageUploader::delete($user->profile_picture);
        $user->update(['profile_picture' => null]);

        return response()->json($user->fresh()->load('municipality'));
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
