<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\NewOrderReceivedMail;
use App\Mail\OrderConfirmedMail;
use App\Mail\OrderDeliveredMail;
use App\Mail\PaymentReceiptMail;
use App\Models\AppNotification;
use App\Models\FingerlingListing;
use App\Models\MockPayment;
use App\Models\Order;
use App\Models\PaymentLog;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\PayMongoService;
use App\Support\OrderTransactionPresenter;
use App\Support\SafeMailer;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The order lifecycle and its escrow-style payment flow.
 *
 * Status progression: placed -> paid -> confirmed -> in_transit -> completed
 * (or failed/cancelled). Every order gets a human-facing Order Number
 * (FG-XXXXXX) and exactly one payment row.
 *
 * Money flow (escrow): when a buyer pays, funds are marked 'paid_held' -- held,
 * NOT yet the seller's. Delivery ('completed') makes the payment eligible for
 * LGU earnings approval; only that LGU approval creates the Settlement that
 * actually splits and releases the money (see LguController::approveEarnings
 * and App\Support\CommissionCalculator). This deliberate hold is what stops a
 * seller being paid before the transaction is verified.
 *
 * Suspension: a suspended buyer cannot place orders or pay (guards below);
 * they can still browse and view past orders.
 */
class OrderController extends Controller
{
    /**
     * Scoped to the caller's own orders -- a Buyer sees orders they placed,
     * a Seller sees orders against their own listings. Never a
     * platform-wide list (see App\Support\OrderTransactionPresenter and the
     * Order Lookup endpoints below for the role-scoped single-order views).
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = Order::with(['listing', 'payment', 'review']);

        if ($user->role === 'seller') {
            $seller = SellerProfile::where('user_id', $user->id)->first();
            $query->where('seller_profile_id', $seller?->id ?? 0);
        } else {
            $query->where('buyer_id', $user->id);
        }

        return response()->json($query->latest()->get());
    }

    /**
     * Order Lookup by Order Number -- reused by both the Buyer's Order
     * Details view and the Seller's Order Lookup search (see
     * App\Support\OrderTransactionPresenter). Route-model-bound on
     * order_number (see routes/api.php), so a Buyer/Seller never has to know
     * the internal numeric id.
     */
    public function show(Request $request, Order $order)
    {
        $user = $request->user();

        if ($user->role === 'seller') {
            $seller = SellerProfile::where('user_id', $user->id)->first();
            abort_if(! $seller || $order->seller_profile_id !== $seller->id, 403, 'You can only view orders belonging to your own listings.');
        } else {
            abort_if($order->buyer_id !== $user->id, 403, 'You can only view your own orders.');
        }

        return response()->json(OrderTransactionPresenter::present($order, $user->role));
    }

    /**
     * Lets a Seller attach an internal note to an order (e.g. "buyer asked
     * for morning pickup") -- visible to the Seller, LGU Admin, and Super
     * Admin on the Order Details view, never to the Buyer.
     */
    public function updateSellerNotes(Request $request, Order $order)
    {
        $seller = SellerProfile::where('user_id', $request->user()->id)->firstOrFail();
        abort_if($order->seller_profile_id !== $seller->id, 403, 'You can only add notes to your own orders.');

        $data = $request->validate([
            'seller_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $order->update(['seller_notes' => $data['seller_notes'] ?? null]);

        return response()->json(OrderTransactionPresenter::present($order->fresh(), 'seller'));
    }

    /**
     * Place an order (Buyer only). Validates the listing is approved and in
     * stock, creates the order + its pending payment, and decrements the
     * listing's stock immediately to prevent overselling while the buyer heads
     * to checkout. No money moves yet.
     *
     * @throws \Illuminate\Validation\ValidationException  On invalid input.
     *         Returns 422 if the listing isn't approved or stock is insufficient,
     *         403 if the buyer is suspended.
     */
    public function store(Request $request)
    {
        abort_if($request->user()->status === 'suspended', 403, 'Your account has been suspended and cannot place orders. Contact support for assistance.');

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

    /**
     * Start payment for an order (Buyer only). Delegates to PayMongoService,
     * which returns either a real hosted checkout URL or, when PayMongo isn't
     * configured, a 'demo' session that marks the payment 'paid_held' straight
     * away so the rest of the escrow/settlement flow can still be exercised.
     */
    public function checkout(Order $order, PayMongoService $payMongo)
    {
        if ($order->buyer_id !== request()->user()->id) {
            return response()->json(['message' => 'You can only pay your own orders.'], 403);
        }

        abort_if(request()->user()->status === 'suspended', 403, 'Your account has been suspended and cannot make payments. Contact support for assistance.');

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

    /**
     * Advance an order through delivery (Seller only). Fires the buyer's
     * "confirmed" and "delivered" emails on the relevant transitions, and on
     * 'completed' notifies the municipality's LGU admins that earnings now
     * await their approval (the trigger for the settlement/payout flow).
     * Emails only fire when the status actually changes, so re-saving the same
     * status can't spam the buyer.
     */
    public function updateStatus(Request $request, Order $order)
    {
        $seller = SellerProfile::where('user_id', $request->user()->id)->firstOrFail();

        if ($order->seller_profile_id !== $seller->id) {
            return response()->json(['message' => 'You can only update your own orders.'], 403);
        }

        $data = $request->validate([
            'status' => ['required', 'in:placed,confirmed,in_transit,completed,cancelled'],
        ]);

        $previousStatus = $order->status;
        $order->update($data);
        $statusChanged = $previousStatus !== $data['status'];

        if ($statusChanged && $data['status'] === 'confirmed') {
            $order->loadMissing('buyer');
            SafeMailer::send($order->buyer?->email, new OrderConfirmedMail($order));
        }

        if ($data['status'] === 'completed') {
            $this->notifyLguOfCompletedDelivery($order, $seller);

            if ($statusChanged) {
                $order->loadMissing('buyer');
                SafeMailer::send($order->buyer?->email, new OrderDeliveredMail($order));
            }
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

    /**
     * PayMongo server-to-server payment confirmation. Unauthenticated by design
     * (it's called by PayMongo, not the SPA). Resolves the payment from the
     * provider reference and, if found, runs the idempotent markOrderPaid().
     * Always returns 200 so PayMongo doesn't retry a payload we simply don't
     * recognise.
     */
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

    /**
     * The buyer's post-redirect confirmation after returning from PayMongo's
     * hosted page. Runs the same idempotent markOrderPaid() as the webhook, so
     * whichever arrives first wins and the other is a no-op (no double receipts).
     */
    public function markPaymentSuccess(Request $request, Order $order)
    {
        if ($order->buyer_id !== $request->user()->id) {
            return response()->json(['message' => 'You can only confirm your own orders.'], 403);
        }

        abort_if($request->user()->status === 'suspended', 403, 'Your account has been suspended and cannot make payments. Contact support for assistance.');

        $this->markOrderPaid($order, 'paymongo.success', ['order_number' => $order->order_number]);

        return response()->json([
            'order' => $order->fresh('payment'),
            'status' => 'success',
        ]);
    }

    /**
     * Buyer returned from a declined/abandoned PayMongo checkout. Marks the
     * payment and order 'failed' and restocks the reserved quantity (reversing
     * the decrement from store()), so a failed payment never permanently eats
     * stock. Guarded against double-processing.
     */
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

    /**
     * The single, idempotent "payment captured" transition, shared by the
     * webhook and the buyer's success redirect. Moves the payment to
     * 'paid_held' (escrow) and the order to 'paid', writes an audit PaymentLog
     * row, and sends the receipt/new-order emails EXACTLY once. Money is held,
     * not released -- release happens later at LGU approval.
     *
     * @param  string  $event    Source label recorded on the PaymentLog.
     * @param  array   $payload  Raw provider payload, stored for audit.
     */
    protected function markOrderPaid(Order $order, string $event, array $payload): void
    {
        $payment = $order->payment;

        // The webhook and the frontend's post-redirect call can both reach
        // here for the same order -- only the call that actually performs
        // the pending -> paid_held transition should trigger receipt
        // emails, or a buyer/seller could get duplicate "payment received"
        // emails for a single payment.
        $isNewlyPaid = $payment && ! in_array($payment->status, ['paid_held', 'released'], true);

        if ($isNewlyPaid) {
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

        if ($isNewlyPaid) {
            $order->loadMissing(['buyer', 'sellerProfile.user']);
            SafeMailer::send($order->buyer?->email, new PaymentReceiptMail($order));
            SafeMailer::send($order->sellerProfile?->user?->email, new NewOrderReceivedMail($order));
        }

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

    /**
     * Create an in-app notification only if an identical one doesn't already
     * exist -- keeps the webhook + success-redirect double delivery from
     * producing duplicate notifications.
     */
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
