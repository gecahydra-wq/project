<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\FingerlingListing;
use App\Models\MockPayment;
use App\Models\Order;
use App\Models\PaymentLog;
use App\Services\PayMongoService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    public function index()
    {
        return response()->json(Order::with(['listing', 'payment'])->latest()->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'fingerling_listing_id' => ['required', 'exists:listings,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'pickup_notes' => ['nullable', 'string'],
        ]);

        $listing = FingerlingListing::findOrFail($data['fingerling_listing_id']);

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
        $data = $request->validate([
            'status' => ['required', 'in:placed,confirmed,in_transit,completed,cancelled'],
        ]);

        $order->update($data);

        return response()->json($order->load('payment'));
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
