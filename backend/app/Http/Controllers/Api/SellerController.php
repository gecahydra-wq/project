<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\FingerlingListing;
use App\Models\Message;
use App\Models\MockPayment;
use App\Models\Order;
use App\Models\Review;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Support\ImageUploader;
use Illuminate\Http\Request;
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

        return response()->json([
            'total_completed_sales' => Order::where('seller_profile_id', $seller->id)->where('status', 'completed')->sum('total_amount'),
            'order_status_breakdown' => Order::where('seller_profile_id', $seller->id)->selectRaw('status, count(*) as total')->groupBy('status')->get(),
            'top_species' => FingerlingListing::where('seller_profile_id', $seller->id)->selectRaw('species, sum(quantity) as quantity')->groupBy('species')->get(),
        ]);
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

        if ($data['amount'] > $this->availableBalance($seller)) {
            return response()->json(['message' => 'Withdrawal amount exceeds your available balance.'], 422);
        }

        $withdrawal = WithdrawalRequest::create([
            'seller_profile_id' => $seller->id,
            'method' => $data['method'],
            'account_name' => $data['account_name'],
            'account_number' => $data['account_number'],
            'amount' => $data['amount'],
            'status' => 'pending',
        ]);

        return response()->json($withdrawal, 201);
    }

    /**
     * Withdrawal requests that are not-yet-paid or already-paid both permanently
     * remove money from the available pool; only 'rejected' returns it.
     */
    protected function reservedOrWithdrawn(SellerProfile $seller): float
    {
        return (float) WithdrawalRequest::where('seller_profile_id', $seller->id)
            ->whereIn('status', ['pending', 'approved', 'paid'])
            ->sum('amount');
    }

    /**
     * A running lifetime balance (all released payments ever, minus all
     * withdrawals ever requested), NOT a per-order amount. There is no
     * platform commission, PayMongo fee, service fee, or tax deducted
     * anywhere in this codebase -- every peso of a released payment is
     * credited in full. A seller with prior released earnings and prior
     * withdrawals will see a new order's amount net against that running
     * balance rather than appear on its own, which is the intended
     * cumulative-wallet behavior (verified in
     * test_full_wallet_lifecycle_keeps_every_balance_internally_consistent
     * and test_lgu_approved_earnings_credit_the_full_gross_order_amount_with_no_deductions).
     */
    protected function availableBalance(SellerProfile $seller): float
    {
        $releasedTotal = (float) MockPayment::whereHas('order', fn ($q) => $q->where('seller_profile_id', $seller->id))
            ->where('status', 'released')
            ->sum('amount');

        return max(0, round($releasedTotal - $this->reservedOrWithdrawn($seller), 2));
    }

    protected function withdrawnAmount(SellerProfile $seller): float
    {
        return round((float) WithdrawalRequest::where('seller_profile_id', $seller->id)
            ->where('status', 'paid')
            ->sum('amount'), 2);
    }

    /**
     * Money reserved by a withdrawal request that has been submitted (and
     * possibly approved) but not yet marked paid. This is already excluded
     * from availableBalance() to prevent a seller from over-requesting, but
     * it has left neither the released pool nor become a completed payout,
     * so it must be accounted for separately or totalEarnings will not
     * reconcile against availableBalance + pendingBalance + withdrawnAmount
     * while a payout is in flight.
     */
    protected function processingAmount(SellerProfile $seller): float
    {
        return round((float) WithdrawalRequest::where('seller_profile_id', $seller->id)
            ->whereIn('status', ['pending', 'approved'])
            ->sum('amount'), 2);
    }

    protected function walletSummary(SellerProfile $seller): array
    {
        // Earnings are recognized as soon as the buyer's payment is captured
        // (paid_held), not when the order is delivered/completed. Delivery
        // alone never changes which bucket the money sits in.
        $sellerPayments = MockPayment::whereHas('order', fn ($q) => $q->where('seller_profile_id', $seller->id));

        $totalEarnings = (float) (clone $sellerPayments)->whereIn('status', ['paid_held', 'released'])->sum('amount');
        $pendingBalance = (float) (clone $sellerPayments)->where('status', 'paid_held')->sum('amount');

        return [
            'available_balance' => $this->availableBalance($seller),
            'pending_balance' => round($pendingBalance, 2),
            'processing_amount' => $this->processingAmount($seller),
            'total_earnings' => round($totalEarnings, 2),
            'withdrawn_amount' => $this->withdrawnAmount($seller),
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

        return response()->json([
            'buyer' => $buyer->load('municipality'),
            'stats' => [
                'total_orders' => (clone $orders)->count(),
                'completed_orders' => (clone $orders)->where('status', 'completed')->count(),
                'pending_orders' => (clone $orders)->whereIn('status', ['placed', 'paid', 'confirmed', 'in_transit'])->count(),
                'total_spent' => (clone $orders)->where('status', 'completed')->sum('total_amount'),
                'most_recent_purchase' => (clone $orders)->max('created_at'),
            ],
            'reviews' => Review::where('seller_profile_id', $seller->id)->where('buyer_id', $buyer->id)->latest()->get(),
            'has_conversation' => $hasConversation,
        ]);
    }
}
