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
use App\Support\ActivityLog;
use App\Support\OrderCancellation;
use App\Support\OrderTransactionPresenter;
use App\Support\PaymentReturnToken;
use App\Support\SafeMailer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;
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

        // sellerProfile.user names the seller on the buyer's My Orders list.
        $query = Order::with(['listing', 'payment', 'review', 'sellerProfile.user']);

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

        // Hiding a frozen seller's listings is not enough on its own: a buyer
        // holding the id from a cart, a link or an open tab could still order.
        $listingSeller = $listing->sellerProfile;
        if ($listingSeller?->status === 'suspended' || $listingSeller?->listings_frozen_at) {
            return response()->json(['message' => 'This seller is not accepting orders at the moment.'], 422);
        }

        // Enforces both the seller's Minimum Order and the stock ceiling, in
        // the listing's own unit (pieces/kilograms/bulk) -- the same check the
        // cart uses, so the two can never disagree.
        if ($issue = $listing->quantityIssue((int) $data['quantity'])) {
            return response()->json(['message' => $issue], 422);
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

        // Orders are counted in the same fish as the stock -- a buyer who chose
        // "by bulk" had it converted before it reached here.
        $listing->decrement('quantity', (int) $data['quantity']);

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

        // A cancelled/expired order has already given its stock back.
        abort_unless(
            $order->status === 'placed' && in_array($order->payment?->status, OrderCancellation::UNPAID_PAYMENT_STATUSES, true),
            422,
            'This order can no longer be paid. Please place a new order.'
        );

        // Resuming payment mints a fresh session, so retire the previous one
        // first. Left alive, the old checkout.paymongo.com URL stays payable
        // and a buyer holding both could pay for this order twice.
        $previousSession = $order->payment?->provider_reference;

        $checkout = $payMongo->createCheckoutSession($order->load('listing'));
        $order->payment()->update([
            'provider_reference' => $checkout['id'],
            'checkout_url' => $checkout['checkout_url'],
            'status' => $checkout['mode'] === 'demo' ? 'paid_held' : 'checkout_created',
        ]);

        if ($previousSession && $previousSession !== $checkout['id']) {
            $payMongo->expireCheckoutSession($previousSession);
        }

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

        // 'completed' is deliberately absent. Marking an order delivered is
        // what releases it into the LGU earnings queue and, from there, to the
        // seller's balance -- so letting the seller declare their own delivery
        // complete let them start their own payout without the buyer ever
        // confirming receipt. That confirmation now belongs to the buyer alone
        // (see confirmReceived), with an LGU/Super Admin backstop
        // (LguController/SuperAdminController::markOrderDelivered) for the case
        // where a buyer goes silent and would otherwise strand the seller's
        // money in escrow indefinitely.
        $data = $request->validate([
            'status' => ['required', 'in:placed,confirmed,in_transit,cancelled'],
            // Required only when cancelling. Cancelling was previously silent:
            // the buyer was told their order was gone and never why, and on a
            // PAID order this is the one seller action that sends money back
            // through the refund queue -- it should be on the record.
            'cancellation_reason' => [Rule::requiredIf(fn () => $request->input('status') === 'cancelled'), 'string', 'max:1000'],
        ]);

        $previousStatus = $order->status;
        $statusChanged = $previousStatus !== $data['status'];

        // Cancelled/failed orders already released their stock (and any
        // payment went to the refund queue), and a completed order is in the
        // LGU earnings flow -- none of them may be moved again.
        if ($statusChanged && in_array($previousStatus, ['cancelled', 'failed', 'completed'], true)) {
            return response()->json(['message' => "This order is already {$previousStatus} and can no longer be changed."], 422);
        }

        // Out for delivery is the point of no return for the seller. The fish
        // have left the farm, so restocking the listing would credit stock that
        // is not there and, on a paid order, refund a buyer who is about to
        // take delivery. The seller's own screen stops offering Cancel Order at
        // in_transit (ORDER_STATUS_TRANSITIONS); this is the same rule enforced
        // where it counts, so it cannot be skipped by calling the API directly.
        if ($statusChanged && $previousStatus === 'in_transit' && $data['status'] === 'cancelled') {
            return response()->json([
                'message' => 'This order is already out for delivery and can no longer be cancelled.',
            ], 422);
        }

        // Advancing an unpaid order is a one-way trap: once it leaves 'placed',
        // checkout() refuses it (so the buyer can never pay) and
        // orders:expire-unpaid stops seeing it (so it never expires or
        // restocks), leaving it to run all the way to 'completed' and into the
        // LGU earnings flow with no money captured.
        //
        // The test is the PAYMENT, not the order status: money reaching escrow
        // is what markOrderPaid records, and an order whose payment never got
        // there has nothing to fulfil regardless of what its own status says.
        // Cancelling stays allowed -- that is how a seller declines an order.
        $paymentCaptured = in_array($order->payment?->status, ['paid_held', 'released'], true);

        if ($statusChanged && $data['status'] !== 'cancelled' && ! $paymentCaptured) {
            return response()->json([
                'message' => 'This order has not been paid yet. You can only cancel it until the buyer completes payment.',
            ], 422);
        }

        if ($statusChanged && $data['status'] === 'cancelled') {
            OrderCancellation::cancel($order, $data['cancellation_reason'], $request->user());

            return response()->json($order->fresh()->load('payment'));
        }

        unset($data['cancellation_reason']);
        $order->update($data);

        if ($statusChanged && $data['status'] === 'confirmed') {
            $order->loadMissing('buyer');
            SafeMailer::send($order->buyer?->email, new OrderConfirmedMail($order));
            $this->notifyOnce(
                $order->buyer_id,
                "order_confirmed:{$order->id}",
                'Order confirmed',
                "The seller confirmed order #{$order->order_number} and is preparing it."
            );
        }

        // Delivery progress reached the buyer by email only, so anyone who did
        // not check their inbox saw the status change silently. The type is
        // per-order so two orders moving through the same stage do not collide
        // in notifyOnce's firstOrCreate, and re-saving the same status cannot
        // raise a second copy. 'Out for Delivery' is the label the whole app
        // uses for in_transit (see STATUS_LABELS on the frontend).
        if ($statusChanged && $data['status'] === 'in_transit') {
            $this->notifyOnce(
                $order->buyer_id,
                "order_out_for_delivery:{$order->id}",
                'Out for delivery',
                "Order #{$order->order_number} is on its way to you."
            );
        }

        return response()->json($order->load('payment'));
    }

    /**
     * The buyer confirms the fingerlings actually arrived.
     *
     * This is the only ordinary route to 'completed', and completing is what
     * makes the payment eligible for LGU earnings approval -- so the person who
     * paid is the one who decides the delivery happened. The seller cannot do
     * it for them (see updateStatus).
     */
    public function confirmReceived(Request $request, Order $order)
    {
        abort_if($order->buyer_id !== $request->user()->id, 403, 'You can only confirm your own orders.');

        return response()->json($this->completeDelivery($order));
    }

    /**
     * Move an order to 'completed' and fan out everything that depends on it.
     *
     * Shared by the buyer's own confirmation and the LGU/Super Admin backstop,
     * so the two can never drift into completing an order by different rules.
     * $confirmedBy names the admin acting for a silent buyer; null means the
     * buyer confirmed it themselves.
     */
    public function completeDelivery(Order $order, ?User $confirmedBy = null): Order
    {
        abort_if(in_array($order->status, ['cancelled', 'failed'], true), 422, "This order is {$order->status} and can no longer be completed.");
        abort_if($order->status === 'completed', 422, 'This order is already marked as received.');

        // The same rule updateStatus enforces: an order whose money never
        // reached escrow has nothing to release, and completing it anyway
        // would push an unpaid order into the LGU earnings queue.
        abort_unless(
            in_array($order->payment?->status, ['paid_held', 'released'], true),
            422,
            'This order has not been paid yet, so it cannot be marked as received.'
        );

        $order->update(['status' => 'completed']);
        $order->loadMissing(['buyer', 'sellerProfile.user']);

        $this->notifyLguOfCompletedDelivery($order, $order->sellerProfile);

        SafeMailer::send($order->buyer?->email, new OrderDeliveredMail($order));

        // The seller is told too: they no longer mark delivery themselves, so
        // without this the first they would hear of it is the money appearing.
        $this->notifyOnce(
            $order->sellerProfile?->user_id,
            "order_received:{$order->id}",
            'Order confirmed as received',
            $confirmedBy
                ? sprintf('Order #%s was marked received by %s on the buyer\'s behalf. It is now with your LGU for earnings approval.', $order->order_number, $confirmedBy->name)
                : sprintf('The buyer confirmed they received order #%s. It is now with your LGU for earnings approval.', $order->order_number)
        );

        $this->notifyOnce(
            $order->buyer_id,
            "order_delivered:{$order->id}",
            'Order completed',
            $confirmedBy
                ? sprintf('Order #%s was marked received by %s. You can now rate the seller.', $order->order_number, $confirmedBy->name)
                : sprintf('Order #%s is complete. You can now rate the seller.', $order->order_number)
        );

        ActivityLog::record([
            'actor_id' => $confirmedBy?->id ?? $order->buyer_id,
            'actor_role' => $confirmedBy?->role ?? 'buyer',
            'action' => $confirmedBy ? 'order_marked_received_by_admin' : 'order_confirmed_received',
            'target_user_id' => $order->sellerProfile?->user_id,
            'municipality_id' => $order->sellerProfile?->municipality_id,
            'reference_type' => 'ORD',
            'reference_number' => $order->order_number,
            'description' => $confirmedBy
                ? sprintf('Marked order %s as received on the buyer\'s behalf.', $order->order_number)
                : sprintf('Buyer confirmed receipt of order %s.', $order->order_number),
        ]);

        return $order->fresh()->load('payment');
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
     * (it's called by PayMongo, not the SPA), so the Paymongo-Signature header
     * is verified against PAYMONGO_WEBHOOK_SECRET first -- a forged or unsigned
     * request gets 401 and changes nothing. Only a
     * checkout_session.payment.paid event marks an order paid. Other signed
     * events and unknown references get 200 so PayMongo doesn't retry them.
     */
    public function paymongoWebhook(Request $request, PayMongoService $payMongo)
    {
        if (! config('services.paymongo.webhook_secret')) {
            Log::warning('PayMongo webhook ignored: PAYMONGO_WEBHOOK_SECRET is not configured.');

            return response()->json(['received' => false, 'message' => 'Webhook secret not configured.'], 503);
        }

        if (! $payMongo->webhookSignatureIsValid($request->getContent(), $request->header('Paymongo-Signature'))) {
            return response()->json(['message' => 'Invalid webhook signature.'], 401);
        }

        $payload = $request->all();

        if (data_get($payload, 'data.attributes.type') !== 'checkout_session.payment.paid') {
            return response()->json(['received' => true]);
        }

        $payment = MockPayment::where('provider_reference', data_get($payload, 'data.attributes.data.id'))->first();

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
    public function markPaymentSuccess(Request $request, Order $order, PayMongoService $payMongo)
    {
        if ($order->buyer_id !== $request->user()->id) {
            return response()->json(['message' => 'You can only confirm your own orders.'], 403);
        }

        abort_if($request->user()->status === 'suspended', 403, 'Your account has been suspended and cannot make payments. Contact support for assistance.');

        // The return URL carries a single-use token, burned here on first use.
        // Order matters: a spent token must never stop us capturing money that
        // is not in escrow yet, because this redirect is a capture path in its
        // own right and not only a receipt. So we reject a replay only once the
        // payment is demonstrably already settled -- at which point there is
        // nothing left to confirm and re-running markOrderPaid() would just add
        // a duplicate PaymentLog row against a closed payment.
        // Judged on the PAYMENT, never the order. An order that expired or was
        // cancelled while its payment was still open is exactly the case where
        // a late arrival has to reach markOrderPaid() so it can be queued for
        // refund -- treating that order as "done" would silently swallow money.
        $firstUse = PaymentReturnToken::consume($order, $request->input('t'));
        $settled = in_array($order->payment?->status, ['paid_held', 'released', OrderCancellation::REFUND_PENDING, OrderCancellation::REFUNDED], true);

        if (! $firstUse && $settled) {
            return response()->json([
                'order' => $order->fresh('payment'),
                'status' => 'already_confirmed',
                'message' => 'This payment link has already been used. Your order is in My Orders.',
            ], 410);
        }

        // Anyone can open the success URL, so for a real PayMongo checkout ask
        // PayMongo whether it was actually paid. Demo mode (no secret key) has
        // no session to check and keeps working as before.
        $payment = $order->payment;
        if (config('services.paymongo.secret_key') && $payment?->status === 'checkout_created'
            && $payMongo->checkoutSessionIsPaid($payment->provider_reference) !== true) {
            return response()->json([
                'order' => $order->fresh('payment'),
                'status' => 'processing',
                'message' => "We haven't received confirmation from PayMongo yet. Your order will update automatically once the payment is confirmed.",
            ]);
        }

        $this->markOrderPaid($order, 'paymongo.success', ['order_number' => $order->order_number]);

        return response()->json([
            'order' => $order->fresh('payment'),
            'status' => 'success',
        ]);
    }

    /**
     * Buyer returned from a declined or abandoned PayMongo checkout.
     *
     * This deliberately does NOT fail the order. Abandoning a checkout is not
     * the same as deciding not to buy -- a declined card, a closed tab or a
     * lost connection all land here -- so the order stays 'placed' and
     * payable, and the reserved stock stays reserved, until the payment window
     * closes. orders:expire-unpaid is the single place an unpaid order is
     * failed and its stock returned; letting this endpoint do it too was what
     * made an abandoned checkout unrecoverable.
     *
     * checkout() can be called again on a 'placed' order and mints a fresh
     * PayMongo session, so no state needs resetting here. An order whose
     * window has already closed is left for the scheduler rather than being
     * failed inline, so expiry keeps happening in exactly one place.
     */
    public function markPaymentCancelled(Request $request, Order $order)
    {
        if ($order->buyer_id !== $request->user()->id) {
            return response()->json(['message' => 'You can only update your own orders.'], 403);
        }

        $payment = $order->payment;

        // Same replay problem as the success link: re-opening an old cancel URL
        // for an order that has since been paid would notify the buyer that
        // their card was "declined" long after the money reached escrow.
        if ($payment && ! in_array($payment->status, OrderCancellation::UNPAID_PAYMENT_STATUSES, true)) {
            return response()->json([
                'order' => $order->fresh('payment'),
                'status' => 'already_confirmed',
                'message' => 'This payment link has already been used. Your order is in My Orders.',
            ], 410);
        }

        $stillPayable = $payment
            && in_array($payment->status, OrderCancellation::UNPAID_PAYMENT_STATUSES, true)
            && $order->status === 'placed';

        $buyerNotification = $stillPayable
            ? $this->notifyOnce(
                $order->buyer_id,
                'payment_incomplete',
                'Payment not completed',
                "Your payment for order #{$order->order_number} was not completed. The order is still reserved for you -- you can pay for it from My Orders until it expires. No funds were captured."
            )
            : $this->notifyOnce(
                $order->buyer_id,
                'payment_failed',
                'Card payment declined',
                "Your payment for order #{$order->order_number} was declined or expired. No funds were captured."
            );

        return response()->json([
            'order' => $order->fresh('payment'),
            'notification' => $buyerNotification,
            'status' => $stillPayable ? 'payable' : $order->status,
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

        // Money arrived for an order that was already cancelled or expired --
        // its stock is gone, so it can't become 'paid'. Queue a refund instead.
        if ($payment && in_array($order->status, ['cancelled', 'failed'], true)) {
            if (! in_array($payment->status, [OrderCancellation::REFUND_PENDING, OrderCancellation::REFUNDED], true)) {
                PaymentLog::create(['payment_id' => $payment->id, 'event' => $event, 'payload' => $payload]);
                OrderCancellation::queueRefund($payment, $order, 'order.paid_after_close',
                    "Payment was received after the order had already {$order->status}.");
            }

            return;
        }

        // The webhook and the frontend's post-redirect call can both reach
        // here for the same order -- only the call that actually performs
        // the pending -> paid_held transition should trigger receipt
        // emails, or a buyer/seller could get duplicate "payment received"
        // emails for a single payment.
        $isNewlyPaid = $payment && ! in_array($payment->status, ['paid_held', 'released', OrderCancellation::REFUND_PENDING, OrderCancellation::REFUNDED], true);

        if ($isNewlyPaid) {
            // Retire the hosted checkout page along with the payment. The
            // checkout.paymongo.com URL is in the buyer's history and stays
            // live on PayMongo's side until it is expired, so without this the
            // page for a paid -- even delivered -- order is still reachable.
            // Best effort: expireCheckoutSession() never throws, and PayMongo
            // may decline to expire a session it has collected on. We also drop
            // our stored copy so nothing here can hand the URL out again.
            app(PayMongoService::class)->expireCheckoutSession($payment->provider_reference);

            $payment->update(['status' => 'paid_held', 'checkout_url' => null]);
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
