<?php

namespace App\Support;

use App\Models\AppNotification;
use App\Models\MockPayment;
use App\Models\Order;
use App\Models\PaymentLog;
use App\Models\User;

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
    public static function cancel(Order $order): void
    {
        $payment = $order->payment;

        $order->update(['status' => 'cancelled']);
        self::restock($order);

        if ($payment?->status === 'paid_held') {
            self::queueRefund($payment, $order, 'order.cancelled', 'The seller cancelled the order after it was paid.');

            return;
        }

        if ($payment && in_array($payment->status, self::UNPAID_PAYMENT_STATUSES, true)) {
            $payment->update(['status' => 'cancelled']);
        }

        self::notify($order->buyer_id, 'order_cancelled', 'Order cancelled',
            "Order #{$order->order_number} was cancelled by the seller. No payment was captured.");
    }

    /** An unpaid order passed its payment window (orders:expire-unpaid). */
    public static function expire(Order $order): void
    {
        $order->payment?->update(['status' => 'failed']);
        $order->update(['status' => 'failed']);
        self::restock($order);

        self::notify($order->buyer_id, 'order_expired', 'Order expired',
            "Order #{$order->order_number} was not paid in time, so it was cancelled and the reserved stock was released. No payment was captured.");
    }

    /**
     * Money was captured, but the order it was for has already been cancelled
     * or expired (its stock is gone). The buyer must get it back.
     */
    public static function queueRefund(MockPayment $payment, Order $order, string $event, string $reason): void
    {
        $payment->update(['status' => self::REFUND_PENDING]);

        PaymentLog::create([
            'payment_id' => $payment->id,
            'event' => $event,
            'payload' => ['reason' => $reason],
        ]);

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
