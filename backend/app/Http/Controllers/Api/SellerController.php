<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\BuyerRating;
use App\Models\FingerlingListing;
use App\Models\Message;
use App\Models\MockPayment;
use App\Models\Order;
use App\Models\Review;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Support\ActivityLog;
use App\Support\AnalyticsPeriod;
use App\Support\CommissionCalculator;
use App\Support\ImageUploader;
use App\Support\ReviewModeration;
use App\Support\SellerWallet;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class SellerController extends Controller
{
    public function dashboard(Request $request)
    {
        $seller = SellerProfile::with(['user', 'municipality'])->where('user_id', $request->user()->id)->firstOrFail();

        return response()->json([
            'seller' => $seller,
            'active_listings' => FingerlingListing::where('seller_profile_id', $seller->id)->count(),
            'pending_orders' => Order::where('seller_profile_id', $seller->id)->whereIn('status', ['placed', 'paid', 'confirmed'])->count(),
            'total_sales' => Order::where('seller_profile_id', $seller->id)->where('status', 'completed')->sum('total_amount'),
            'ratings' => $seller->rating,
            'unread_messages' => Message::where('receiver_id', $request->user()->id)->whereNull('read_at')->count(),
            'listings' => FingerlingListing::with('media')->where('seller_profile_id', $seller->id)->latest()->get(),
            'orders' => Order::with(['listing', 'payment', 'buyer'])->where('seller_profile_id', $seller->id)->latest()->get(),
            'notifications' => AppNotification::where('user_id', $request->user()->id)->whereNull('read_at')->latest()->get(),
        ]);
    }

    public function analytics(Request $request)
    {
        $seller = SellerProfile::where('user_id', $request->user()->id)->firstOrFail();
        ['period' => $period, 'start' => $start, 'end' => $end, 'unit' => $unit] = AnalyticsPeriod::resolve($request->query('period'));

        $completedRows = $this->completedOrders($seller, $start, $end)->get(['orders.created_at', 'orders.total_amount']);
        $salesOverTime = AnalyticsPeriod::bucketize($start, $end, $unit, $completedRows);

        $ordersByStatus = $this->allOrders($seller, $start, $end)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->get();

        $topSpecies = $this->completedOrders($seller, $start, $end)
            ->join('listings', 'orders.listing_id', '=', 'listings.id')
            ->selectRaw('listings.species as species, sum(orders.quantity) as quantity')
            ->groupBy('listings.species')
            ->orderByDesc('quantity')
            ->get();

        return response()->json([
            'period' => $period,
            'range' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'summary' => [
                'total_sales' => $completedRows->count(),
                'total_revenue' => round((float) $completedRows->sum('total_amount'), 2),
                'total_orders' => $this->allOrders($seller, $start, $end)->count(),
                'active_listings' => FingerlingListing::where('seller_profile_id', $seller->id)->count(),
            ],
            'sales_over_time' => $salesOverTime,
            'orders_by_status' => $ordersByStatus,
            'top_species' => $topSpecies,
        ]);
    }

    private function completedOrders(SellerProfile $seller, Carbon $start, Carbon $end)
    {
        return Order::where('orders.seller_profile_id', $seller->id)->where('orders.status', 'completed')->whereBetween('orders.created_at', [$start, $end]);
    }

    private function allOrders(SellerProfile $seller, Carbon $start, Carbon $end)
    {
        return Order::where('orders.seller_profile_id', $seller->id)->whereBetween('orders.created_at', [$start, $end]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();
        $seller = SellerProfile::where('user_id', $user->id)->firstOrFail();

        $data = $request->validate([
            'name' => ['sometimes', 'string'],
            'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string'],
            'hatchery_name' => ['sometimes', 'string'],
            'description' => ['nullable', 'string'],
            'farming_methods' => ['nullable', 'string'],
            'fish_raising_practices' => ['nullable', 'string'],
            'farm_history' => ['nullable', 'string'],
            'water_source' => ['nullable', 'string'],
            'feeding_practices' => ['nullable', 'string'],
            'years_experience' => ['nullable', 'integer', 'min:0', 'max:200'],
            'certifications' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'gallery' => ['nullable', 'array'],
            'gallery.*' => ['url', 'max:2048'],
        ]);

        $user->update(collect($data)->only(['name', 'email', 'phone'])->all());
        $seller->update(collect($data)->except(['name', 'email', 'phone'])->all());

        return response()->json($seller->fresh(['user', 'municipality']));
    }

    public function uploadProfilePicture(Request $request)
    {
        $request->validate(['photo' => array_merge(['required'], ImageUploader::validationRules())]);

        $user = $request->user();
        $seller = SellerProfile::where('user_id', $user->id)->firstOrFail();

        ImageUploader::delete($seller->profile_picture);
        $url = ImageUploader::store($request->file('photo'), 'profile-pictures/sellers');
        $user->update(['profile_picture' => $url]);
        $seller->update(['profile_picture' => $url]);

        return response()->json($seller->fresh(['user', 'municipality']));
    }

    public function removeProfilePicture(Request $request)
    {
        $user = $request->user();
        $seller = SellerProfile::where('user_id', $user->id)->firstOrFail();

        ImageUploader::delete($seller->profile_picture);
        $user->update(['profile_picture' => null]);
        $seller->update(['profile_picture' => null]);

        return response()->json($seller->fresh(['user', 'municipality']));
    }

    public function uploadCoverPhoto(Request $request)
    {
        $request->validate(['photo' => array_merge(['required'], ImageUploader::validationRules())]);

        $seller = SellerProfile::where('user_id', $request->user()->id)->firstOrFail();

        ImageUploader::delete($seller->cover_photo);
        $seller->update(['cover_photo' => ImageUploader::store($request->file('photo'), 'cover-photos')]);

        return response()->json($seller->fresh(['user', 'municipality']));
    }

    public function removeCoverPhoto(Request $request)
    {
        $seller = SellerProfile::where('user_id', $request->user()->id)->firstOrFail();

        ImageUploader::delete($seller->cover_photo);
        $seller->update(['cover_photo' => null]);

        return response()->json($seller->fresh(['user', 'municipality']));
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

    public function wallet(Request $request)
    {
        $seller = SellerProfile::where('user_id', $request->user()->id)->firstOrFail();

        return response()->json($this->walletSummary($seller));
    }

    public function requestWithdrawal(Request $request)
    {
        $seller = SellerProfile::where('user_id', $request->user()->id)->firstOrFail();

        $data = $request->validate([
            'method' => ['required', Rule::in(['gcash', 'maya', 'bank_transfer'])],
            'account_name' => ['required', 'string'],
            'account_number' => ['required', 'string'],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        if ($data['amount'] > SellerWallet::availableBalance($seller)) {
            return response()->json(['message' => 'Withdrawal amount exceeds your available balance.'], 422);
        }

        // The platform's payout fee is frozen onto the request at the
        // moment it's made -- see App\Support\CommissionCalculator -- so a
        // later change to the fee percentage never retroactively alters an
        // already-submitted withdrawal request.
        $fee = CommissionCalculator::withdrawalFee((float) $data['amount']);

        $withdrawal = WithdrawalRequest::create([
            'seller_profile_id' => $seller->id,
            'method' => $data['method'],
            'account_name' => $data['account_name'],
            'account_number' => $data['account_number'],
            'amount' => $data['amount'],
            'platform_fee' => $fee['fee'],
            'status' => 'pending',
        ]);

        return response()->json($withdrawal, 201);
    }

    /**
     * Balance math lives in App\Support\SellerWallet so the AI Assistant can
     * compute a seller's own wallet answers using the exact same rules as
     * this page instead of a re-derived approximation.
     */
    protected function walletSummary(SellerProfile $seller): array
    {
        return [
            ...SellerWallet::summary($seller),
            'payment_history' => MockPayment::whereHas('order', fn ($q) => $q->where('seller_profile_id', $seller->id))
                ->with(['order.buyer', 'order.listing'])
                ->latest()
                ->get(),
            'withdrawal_requests' => WithdrawalRequest::where('seller_profile_id', $seller->id)->latest()->get(),
        ];
    }

    public function buyerProfile(Request $request, User $buyer)
    {
        abort_unless($buyer->role === 'buyer', 404);

        $seller = SellerProfile::where('user_id', $request->user()->id)->firstOrFail();
        $sellerUserId = $request->user()->id;

        $hasOrder = Order::where('seller_profile_id', $seller->id)->where('buyer_id', $buyer->id)->exists();
        $hasConversation = Message::where(function ($q) use ($sellerUserId, $buyer) {
            $q->where('sender_id', $sellerUserId)->where('receiver_id', $buyer->id);
        })->orWhere(function ($q) use ($sellerUserId, $buyer) {
            $q->where('sender_id', $buyer->id)->where('receiver_id', $sellerUserId);
        })->exists();

        abort_unless($hasOrder || $hasConversation, 403, 'You can only view profiles of buyers who have ordered from you or messaged you.');

        $orders = Order::where('seller_profile_id', $seller->id)->where('buyer_id', $buyer->id);

        // Platform-wide buyer reputation (across every seller) so the viewing
        // seller can judge whether this is a reliable buyer, not just how they
        // behaved on this one seller's orders.
        $buyerRatings = BuyerRating::where('buyer_id', $buyer->id)
            ->with(['sellerProfile:id,hatchery_name,profile_picture', 'order:id,order_number,listing_id', 'order.listing:id,species,title'])
            ->latest()
            ->get();

        // This seller's own orders with the buyer, each carrying its existing
        // rating (if any) so the frontend can show a rate form on completed,
        // as-yet-unrated orders and the given rating on the rest.
        $sellerOrders = (clone $orders)
            ->with(['listing:id,species,title', 'buyerRating'])
            ->latest()
            ->get();

        return response()->json([
            'buyer' => $buyer->load('municipality'),
            'stats' => [
                'total_orders' => (clone $orders)->count(),
                'completed_orders' => (clone $orders)->where('status', 'completed')->count(),
                'pending_orders' => (clone $orders)->whereIn('status', ['placed', 'paid', 'confirmed', 'in_transit'])->count(),
                'total_spent' => (clone $orders)->where('status', 'completed')->sum('total_amount'),
                'most_recent_purchase' => (clone $orders)->max('created_at'),
                // Platform-wide activity, for legitimacy at a glance.
                'total_orders_all' => Order::where('buyer_id', $buyer->id)->count(),
                'completed_orders_all' => Order::where('buyer_id', $buyer->id)->where('status', 'completed')->count(),
            ],
            'buyer_rating' => [
                'average' => round((float) $buyerRatings->avg('rating'), 2),
                'count' => $buyerRatings->count(),
            ],
            'buyer_ratings' => $buyerRatings,
            'seller_orders' => $sellerOrders,
            // Reviews this buyer left FOR THIS SELLER, now with the order/listing
            // each one is about.
            'reviews' => Review::where('seller_profile_id', $seller->id)->where('buyer_id', $buyer->id)
                ->with(['order:id,order_number,listing_id', 'order.listing:id,species,title'])
                ->latest()
                ->get(),
            'has_conversation' => $hasConversation,
        ]);
    }

    /**
     * Seller rates a buyer for one of their own completed orders -- the reverse
     * of a buyer Review (see ReviewController). One rating per order; the
     * buyer's cached aggregate on buyer_profiles is refreshed after each,
     * mirroring how a Review refreshes seller_profiles.rating.
     */
    public function rateBuyer(Request $request, Order $order)
    {
        $seller = SellerProfile::where('user_id', $request->user()->id)->firstOrFail();

        abort_if($order->seller_profile_id !== $seller->id, 403, 'You can only rate buyers on your own orders.');
        abort_if($order->status !== 'completed', 422, 'You can only rate a buyer after the order is completed.');
        abort_if($order->buyerRating()->exists(), 422, 'You have already rated the buyer for this order.');

        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $rating = BuyerRating::create([
            'order_id' => $order->id,
            'seller_profile_id' => $seller->id,
            'buyer_id' => $order->buyer_id,
            'rating' => $data['rating'],
            'comment' => $data['comment'] ?? null,
        ]);

        ReviewModeration::refreshBuyerRating($order->buyer_id);

        ActivityLog::record([
            'actor_id' => $request->user()->id,
            'actor_role' => 'seller',
            'action' => 'buyer_rating_submitted',
            'target_user_id' => $order->buyer_id,
            'municipality_id' => $seller->municipality_id,
            'reference_type' => 'ORD',
            'reference_number' => $order->order_number,
            'description' => "Rated buyer {$data['rating']}/5 for order {$order->order_number}.",
        ]);

        return response()->json($rating->load('sellerProfile:id,hatchery_name,profile_picture', 'order:id,order_number,listing_id', 'order.listing:id,species,title'), 201);
    }
}
