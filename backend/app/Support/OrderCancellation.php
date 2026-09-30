<?php

namespace App\Support;

use App\Models\AppNotification;
use App\Models\MockPayment;
use App\Models\Order;
use App\Models\PaymentLog;
use App\Models\User;
use App\Services\PayMongoService;

/**
 * The single place an order that will never be fulfilled is wound down, so
 * seller cancellation, unpaid-order expiry, and a payment that arrives after
 * either of those can never disagree about stock or money.
 *
 *   - Stock reserved by OrderController::store is always given back.
 *   - Unpaid (pending / checkout_created): the payment is simply closed.
 *   - Paid ('paid_held' escrow): the payment becomes 'refund_pending' and joins
 *     the Super Admin's refund queue. It is no longer 'paid_held', so it drops
 *     out of seller Pending Balance and LGU earnings approval automatically.
 *   - The Super Admin refunds the buyer in the PayMongo dashboard, then marks
 *     it 'refunded' here (markRefunded). No money is moved by this app.
 */
class OrderCancellation
{
    /** Payment states in which no money has been captured yet. */
    public const UNPAID_PAYMENT_STATUSES = ['pending', 'checkout_created'];

    public const REFUND_PENDING = 'refund_pending';

    public const REFUNDED = 'refunded';

    /** Seller cancels an order (OrderController::updateStatus). */
    /**
     * $reason is why the SELLER cancelled. It is null for expiry and for any
     * automated cancellation -- the order's own status already distinguishes
     * those -- and is passed straight through to the buyer, who otherwise
     * learned only that their order had vanished.
     */
    public static function cancel(Order $order, ?string $reason = null, ?User $actor = null): void
    {
        $payment = $order->payment;

        $order->update(array_filter([
            'status' => 'cancelled',
            'cancellation_reason' => $reason,
        ], fn ($value) => $value !== null));

        // Recorded here rather than at the call site so an expired order and a
        // seller cancellation both leave a trail, and so the LGU and Super
        // Admin logs carry the reason without either dashboard having to go
        // looking for it. $actor is null for the scheduler's expiry run.
        $order->loadMissing('sellerProfile');
        ActivityLog::record([
            'actor_id' => $actor?->id,
            'actor_role' => $actor?->role,
            'action' => 'order_cancelled',
            'target_user_id' => $order->buyer_id,
            'municipality_id' => $order->sellerProfile?->municipality_id,
            'reference_type' => 'ORD',
            'reference_number' => $order->order_number,
            'description' => $reason
                ? sprintf('Cancelled order %s. Reason: %s', $order->order_number, $reason)
                : sprintf('Order %s was cancelled.', $order->order_number),
        ]);
        self::restock($order);

        if ($payment?->status === 'paid_held') {
            self::queueRefund(
                $payment,
                $order,
                'order.cancelled',
                'The seller cancelled the order after it was paid.'.($reason ? " Reason: {$reason}" : '')
            );

            return;
        }

        if ($payment && in_array($payment->status, self::UNPAID_PAYMENT_STATUSES, true)) {
            self::closeCheckoutPage($payment);
            $payment->update(['status' => 'cancelled']);
        }

        self::notify($order->buyer_id, 'order_cancelled', 'Order cancelled',
            "Order #{$order->order_number} was cancelled by the seller. No payment was captured."
            .($reason ? " Reason: {$reason}" : ''));
    }

    /** An unpaid order passed its payment window (orders:expire-unpaid). */
    public static function expire(Order $order): void
    {
        self::closeCheckoutPage($order->payment);
        $order->payment?->update(['status' => 'failed']);
        $order->update(['status' => 'failed']);
        self::restock($order);

        self::notify($order->buyer_id, 'order_expired', 'Order expired',
            "Order #{$order->order_number} was not paid in time, so it was cancelled and the reserved stock was released. No payment was captured.");
    }

    /**
     * Retire the hosted PayMongo checkout page for a payment that will never
     * be collected, and drop our stored copy of its URL.
     *
     * The checkout.paymongo.com link is not ours to take down -- it stays live
     * and payable until the session is expired -- so an order that expired or
     * was cancelled would otherwise leave a working payment page behind, and
     * any money paid through it would arrive for a closed order and have to be
     * refunded. Best effort: expireCheckoutSession() logs and returns false
     * rather than throwing, because closing the order matters more than
     * reaching PayMongo.
     */
    private static function closeCheckoutPage(?MockPayment $payment): void
    {
        if (! $payment) {
            return;
        }

        app(PayMongoService::class)->expireCheckoutSession($payment->provider_reference);
        $payment->update(['checkout_url' => null]);
    }

    /**
     * Money was captured, but the order it was for has already been cancelled
     * or expired (its stock is gone). The buyer must get it back.
     */
    public static function queueRefund(MockPayment $payment, Order $order, string $event, string $reason): void
    {
        PaymentLog::create([
            'payment_id' => $payment->id,
            'event' => $event,
            'payload' => ['reason' => $reason],
        ]);

        // On test keys nothing was really captured, so there is nothing for an
        // admin to send back: finish the refund here rather than parking it in
        // a queue nobody will work. See services.paymongo.auto_refund -- this
        // never engages on live keys.
        if (config('services.paymongo.auto_refund')) {
            self::completeAutomaticRefund($payment, $order, $reason);

            return;
        }

        $payment->update(['status' => self::REFUND_PENDING]);

        $amount = number_format((float) $payment->amount, 2);

        self::notify($order->buyer_id, "refund_pending:{$payment->id}", 'Refund pending',
            "Order #{$order->order_number} will not be fulfilled. Your payment of ₱{$amount} will be refunded to your original payment method.");

        foreach (User::where('role', 'super_admin')->pluck('id') as $adminId) {
            AppNotification::firstOrCreate([
                'user_id' => $adminId,
                'type' => "refund_pending:{$payment->id}",
            ], [
                'title' => 'Refund needed',
                'body' => "Order #{$order->order_number} (₱{$amount}) needs a refund. {$reason} Refund it in PayMongo, then mark it refunded under Payouts.",
            ]);
        }
    }

    /**
     * The test-mode counterpart of markRefunded: same end state, no admin.
     *
     * The PaymentLog payload records that this was automatic rather than
     * inventing a reference number, and the activity log entry has no actor,
     * so the audit trail never implies a person issued a refund they did not.
     */
    private static function completeAutomaticRefund(MockPayment $payment, Order $order, string $reason): void
    {
        $payment->update(['status' => self::REFUNDED]);

        PaymentLog::create([
            'payment_id' => $payment->id,
            'event' => 'refund.completed',
            'payload' => [
                'automatic' => true,
                'reason' => $reason,
                'notes' => 'Completed automatically: PayMongo is on test keys, so no captured funds needed returning.',
            ],
        ]);

        $amount = number_format((float) $payment->amount, 2);

        self::notify($order->buyer_id, "refund_completed:{$payment->id}", 'Refund sent',
            "Order #{$order->order_number} will not be fulfilled. Your payment of ₱{$amount} has been refunded to your original payment method.");

        ActivityLog::record([
            'actor_id' => null,
            'actor_role' => null,
            'action' => 'order_refunded',
            'target_user_id' => $order->buyer_id,
            'municipality_id' => $order->sellerProfile?->municipality_id,
            'description' => sprintf('Refunded ₱%s for order #%s automatically (PayMongo test mode).',
                $amount, $order->order_number),
        ]);
    }

    /** Super Admin confirms the refund was sent through PayMongo. */
    public static function markRefunded(MockPayment $payment, User $admin, ?string $reference, ?string $notes): MockPayment
    {
        abort_unless($payment->status === self::REFUND_PENDING, 422, 'Only payments awaiting a refund can be marked refunded.');

        $payment->update(['status' => self::REFUNDED]);
        $order = $payment->order()->with('sellerProfile')->first();

        PaymentLog::create([
            'payment_id' => $payment->id,
            'event' => 'refund.completed',
            'payload' => ['reference' => $reference, 'notes' => $notes, 'admin_id' => $admin->id],
        ]);

        AppNotification::where('type', "refund_pending:{$payment->id}")
            ->where('user_id', '!=', $order?->buyer_id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        if ($order) {
            self::notify($order->buyer_id, "refund_completed:{$payment->id}", 'Refund sent',
                sprintf('Your refund of ₱%s for order #%s has been sent%s.',
                    number_format((float) $payment->amount, 2),
                    $order->order_number,
                    $reference ? " (reference {$reference})" : ''));
        }

        ActivityLog::record([
            'actor_id' => $admin->id,
            'actor_role' => $admin->role,
            'action' => 'order_refunded',
            'target_user_id' => $order?->buyer_id,
            'municipality_id' => $order?->sellerProfile?->municipality_id,
            'description' => sprintf('Refunded ₱%s for order #%s%s.',
                number_format((float) $payment->amount, 2),
                $order?->order_number ?? $payment->order_id,
                $reference ? " (reference {$reference})" : ''),
        ]);

        return $payment->fresh();
    }

    private static function restock(Order $order): void
    {
        $order->listing()->increment('quantity', $order->quantity);
    }

    private static function notify(int $userId, string $type, string $title, string $body): void
    {
        AppNotification::firstOrCreate(['user_id' => $userId, 'type' => $type, 'title' => $title], ['body' => $body]);
    }
}
