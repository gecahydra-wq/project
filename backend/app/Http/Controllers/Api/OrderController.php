<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\FingerlingListing;
use App\Models\MockPayment;
use App\Models\Order;
use App\Models\PaymentLog;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\PayMongoService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    public function index()
    {
        return response()->json(Order::with(['listing', 'payment', 'review'])->latest()->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'fingerling_listing_id' => ['required', 'exists:listings,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'pickup_notes' => ['nullable', 'string'],
        ]);

        $listing = FingerlingListing::findOrFail($data['fingerling_listing_id']);

        if ($listing->approval_status !== 'approved') {
            return response()->json(['message' => 'This listing is not currently available for order.'], 422);
        }

        if ($data['quantity'] > $listing->quantity) {
            return response()->json(['message' => 'Requested quantity exceeds available stock.'], 422);
        }

        $order = Order::create([
            'order_number' => 'FG-'.Str::upper(Str::random(6)),
            'buyer_id' => $request->user()->id,
            'seller_profile_id' => $listing->seller_profile_id,
            'listing_id' => $listing->id,
            'quantity' => $data['quantity'],
            'unit_price' => $listing->price_per_piece,
            'total_amount' => $data['quantity'] * $listing->price_per_piece,
            'status' => 'placed',
            'pickup_notes' => $data['pickup_notes'] ?? null,
        ]);

        MockPayment::create([
            'order_id' => $order->id,
            'amount' => $order->total_amount,
            'status' => 'pending',
            'provider' => 'paymongo',
        ]);

        $listing->decrement('quantity', $data['quantity']);

        return response()->json($order->load('payment'), 201);
    }

    public function checkout(Order $order, PayMongoService $payMongo)
    {
        if ($order->buyer_id !== request()->user()->id) {
            return response()->json(['message' => 'You can only pay your own orders.'], 403);
        }

        $checkout = $payMongo->createCheckoutSession($order->load('listing'));
        $order->payment()->update([
            'provider_reference' => $checkout['id'],
            'checkout_url' => $checkout['checkout_url'],
            'status' => $checkout['mode'] === 'demo' ? 'paid_held' : 'checkout_created',
        ]);

        return response()->json([
            'order' => $order->fresh('payment'),
            'checkout_url' => $checkout['checkout_url'],
            'mode' => $checkout['mode'],
        ]);
    }

    public function updateStatus(Request $request, Order $order)
    {
        $seller = SellerProfile::where('user_id', $request->user()->id)->firstOrFail();

        if ($order->seller_profile_id !== $seller->id) {
            return response()->json(['message' => 'You can only update your own orders.'], 403);
        }

        $data = $request->validate([
            'status' => ['required', 'in:placed,confirmed,in_transit,completed,cancelled'],
        ]);

        $order->update($data);

        if ($data['status'] === 'completed') {
            $this->notifyLguOfCompletedDelivery($order, $seller);
        }

        return response()->json($order->load('payment'));
    }

    /**
     * A completed delivery makes its payment eligible for LGU earnings
     * approval (see LguController::pendingEarnings/approveEarnings). Notify
     * every LGU admin in the seller's municipality so the pending approval
     * doesn't go unnoticed. Keyed by payment id (not just user+generic type)
     * so this is idempotent if the order is (re)marked completed, and so
     * LguController::approveEarnings can mark exactly this notification read
     * once the earnings are actually approved.
     */
    protected function notifyLguOfCompletedDelivery(Order $order, SellerProfile $seller): void
    {
        $order->loadMissing(['buyer', 'listing', 'payment']);
        $seller->loadMissing('user');

        $payment = $order->payment;
        if (! $payment) {
            return;
        }

        $lguAdmins = User::where('role', 'lgu_admin')->where('municipality_id', $seller->municipality_id)->get();

        foreach ($lguAdmins as $lguAdmin) {
            AppNotification::firstOrCreate([
                'user_id' => $lguAdmin->id,
                'type' => "earnings_pending_approval:{$payment->id}",
            ], [
                'title' => 'Seller earnings await your approval',
                'body' => sprintf(
                    'A completed delivery requires earnings approval. Seller: %s (%s) · Species: %s · Buyer: %s · Order #%s · Delivered: %s.',
                    $seller->user?->name ?? 'Unknown seller',
                    $seller->hatchery_name,
                    $order->listing?->species ?? 'Unknown species',
                    $order->buyer?->name ?? 'Unknown buyer',
                    $order->order_number,
                    $order->updated_at?->format('M d, Y') ?? now()->format('M d, Y')
                ),
            ]);
        }
    }

    public function paymongoWebhook(Request $request)
    {
        $payload = $request->all();
        $reference = data_get($payload, 'data.attributes.data.id')
            ?: data_get($payload, 'data.id')
            ?: data_get($payload, 'checkout_session_id');

        $payment = MockPayment::where('provider_reference', $reference)->first();

        if ($payment) {
            $this->markOrderPaid($payment->order, 'paymongo.webhook', $payload);
        }

        return response()->json(['received' => true]);
    }

    public function markPaymentSuccess(Request $request, Order $order)
    {
        if ($order->buyer_id !== $request->user()->id) {
            return response()->json(['message' => 'You can only confirm your own orders.'], 403);
        }

        $this->markOrderPaid($order, 'paymongo.success', ['order_number' => $order->order_number]);

        return response()->json([
            'order' => $order->fresh('payment'),
            'status' => 'success',
        ]);
    }

    public function markPaymentCancelled(Request $request, Order $order)
    {
        if ($order->buyer_id !== $request->user()->id) {
            return response()->json(['message' => 'You can only update your own orders.'], 403);
        }

        $payment = $order->payment;
        if ($payment && ! in_array($payment->status, ['failed', 'cancelled'], true) && $order->status !== 'failed') {
            $payment->update(['status' => 'failed']);
            $order->update(['status' => 'failed']);
            $order->listing()->increment('quantity', $order->quantity);
        }

        $buyerNotification = $this->notifyOnce(
            $order->buyer_id,
            'payment_failed',
            'Card payment declined',
            "Your payment for order #{$order->order_number} was declined or expired. No funds were captured."
        );

        return response()->json([
            'order' => $order->fresh('payment'),
            'notification' => $buyerNotification,
            'status' => 'failed',
        ]);
    }

    protected function markOrderPaid(Order $order, string $event, array $payload): void
    {
        $payment = $order->payment;
        if ($payment && ! in_array($payment->status, ['paid_held', 'released'], true)) {
            $payment->update(['status' => 'paid_held']);
        }

        if (! in_array($order->status, ['paid', 'confirmed', 'in_transit', 'completed'], true)) {
            $order->update(['status' => 'paid']);
        }

        if ($payment) {
            PaymentLog::create([
                'payment_id' => $payment->id,
                'event' => $event,
                'payload' => $payload,
            ]);
        }

        $this->notifyOnce(
            $order->buyer_id,
            'payment_success',
            'Payment received',
            "Your payment for order #{$order->order_number} was successful and funds are now held in escrow."
        );

        $sellerUserId = $order->sellerProfile?->user_id;
        if ($sellerUserId) {
            $this->notifyOnce(
                $sellerUserId,
                'order_paid',
                'Order paid',
                "Order #{$order->order_number} has been paid and funds are now held in escrow."
            );
        }
    }

    protected function notifyOnce(int $userId, string $type, string $title, string $body): AppNotification
    {
        return AppNotification::firstOrCreate([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
        ]);
    }
}
