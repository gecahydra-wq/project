<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\BuyerProfile;
use App\Models\Message;
use App\Models\Order;
use App\Models\Review;
use App\Support\AnalyticsPeriod;
use App\Support\BuyerInvestmentReport;
use App\Support\ImageUploader;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Everything scoped to the signed-in Buyer: their dashboard, profile, profile
 * picture, notifications, and purchase analytics. Every query here is filtered
 * by the authenticated buyer's own id -- a buyer can never see another buyer's
 * data through this controller.
 */
class BuyerController extends Controller
{
    /** At-a-glance counts, recent orders/reviews, unread notifications, and profile for the buyer home screen. */
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

    /**
     * Update buyer details, split across two tables: identity fields
     * (name/email/phone) live on the user, while address/bio live on the
     * buyer_profile. Municipality is fixed at registration and not editable here.
     */
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

    public function markAllNotificationsRead(Request $request)
    {
        $updated = AppNotification::where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['updated' => $updated]);
    }

    /**
     * Personal purchase analytics for the selected period: spend over time,
     * orders by status, and top species. Reuses the shared AnalyticsPeriod
     * helper so buyer/seller/LGU/admin charts all bucket time the same way.
     * "Purchases" count completed orders only; total_orders counts every status.
     */
    public function analytics(Request $request)
    {
        $buyerId = $request->user()->id;
        ['period' => $period, 'start' => $start, 'end' => $end, 'unit' => $unit] = AnalyticsPeriod::resolve($request->query('period'));

        $completedRows = $this->completedOrders($buyerId, $start, $end)->get(['orders.created_at', 'orders.total_amount']);
        $purchasesOverTime = AnalyticsPeriod::bucketize($start, $end, $unit, $completedRows);

        $ordersByStatus = $this->allOrders($buyerId, $start, $end)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->get();

        $topSpecies = $this->completedOrders($buyerId, $start, $end)
            ->join('listings', 'orders.listing_id', '=', 'listings.id')
            ->selectRaw('listings.species as species, sum(orders.quantity) as quantity')
            ->groupBy('listings.species')
            ->orderByDesc('quantity')
            ->get();

        return response()->json([
            'period' => $period,
            'range' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'summary' => [
                'total_purchases' => $completedRows->count(),
                'total_orders' => $this->allOrders($buyerId, $start, $end)->count(),
                'total_spending' => round((float) $completedRows->sum('total_amount'), 2),
                'favorite_species' => $topSpecies->first()?->species ?? 'None',
            ],
            'purchases_over_time' => $purchasesOverTime,
            'orders_by_status' => $ordersByStatus,
            'top_species' => $topSpecies,
            // Buyer Turnout / ROI -- recorded investment and engagement, plus
            // a clearly-labelled harvest projection the farmer drives with
            // their own survival rate and farm-gate price. See
            // App\Support\BuyerInvestmentReport for why the return figures are
            // an estimate and never presented as realised earnings.
            'investment' => BuyerInvestmentReport::build($buyerId, $start, $end, [
                'survival_rate' => $request->query('survival_rate'),
                'harvest_value_per_piece' => $request->query('harvest_value_per_piece'),
            ]),
        ]);
    }

    private function completedOrders(int $buyerId, Carbon $start, Carbon $end)
    {
        return Order::where('orders.buyer_id', $buyerId)->where('orders.status', 'completed')->whereBetween('orders.created_at', [$start, $end]);
    }

    private function allOrders(int $buyerId, Carbon $start, Carbon $end)
    {
        return Order::where('orders.buyer_id', $buyerId)->whereBetween('orders.created_at', [$start, $end]);
    }
}
