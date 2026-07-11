<?php

namespace App\Support;

use App\Models\Order;

/**
 * Computes the marketplace-progress timeline shown on every Order Details
 * view (Buyer, Seller, LGU, Super Admin) and used by the AI Assistant's
 * order-status answers. This is NOT courier/GPS tracking -- every stage is
 * derived purely from data the app already has (order.status, payment.status,
 * settlement existence, LGU review state), so it never invents information
 * and never requires a new order-status value. "Preparing" and "Out for
 * Delivery" share the same underlying trigger (order.status = in_transit)
 * since the app doesn't track anything more granular than that.
 */
class OrderTimeline
{
    private const STAGE_LABELS = [
        'order_placed' => 'Order Placed',
        'payment_successful' => 'Payment Successful',
        'seller_accepted' => 'Seller Accepted',
        'preparing' => 'Preparing',
        'out_for_delivery' => 'Out for Delivery',
        'delivered' => 'Delivered',
        'waiting_lgu_verification' => 'Waiting for LGU Verification',
        'transaction_verified' => 'Transaction Verified',
        'completed' => 'Completed',
    ];

    /**
     * @return array{stages: array<int, array{key: string, label: string, reached: bool, timestamp: ?string}>, terminal_status: ?string, terminal_reason: ?string}
     */
    public static function build(Order $order): array
    {
        $payment = $order->payment;
        $settlement = $order->settlement;

        $paymentSucceeded = $payment && in_array($payment->status, ['paid_held', 'released'], true);
        $sellerAccepted = in_array($order->status, ['confirmed', 'in_transit', 'completed'], true);
        $inTransit = in_array($order->status, ['in_transit', 'completed'], true);
        $delivered = $order->status === 'completed';
        $verified = (bool) $settlement;

        $reached = [
            'order_placed' => true,
            'payment_successful' => $paymentSucceeded,
            'seller_accepted' => $sellerAccepted,
            'preparing' => $inTransit,
            'out_for_delivery' => $inTransit,
            'delivered' => $delivered,
            'waiting_lgu_verification' => $delivered && ! $verified && $order->lgu_review_status !== 'rejected',
            'transaction_verified' => $verified,
            'completed' => $verified,
        ];

        $timestamps = [
            'order_placed' => $order->created_at,
            'payment_successful' => $payment?->updated_at,
            'seller_accepted' => $sellerAccepted ? $order->updated_at : null,
            'preparing' => $inTransit ? $order->updated_at : null,
            'out_for_delivery' => $inTransit ? $order->updated_at : null,
            'delivered' => $delivered ? $order->updated_at : null,
            'waiting_lgu_verification' => null,
            'transaction_verified' => $settlement?->settled_at,
            'completed' => $settlement?->settled_at,
        ];

        $stages = [];
        foreach (self::STAGE_LABELS as $key => $label) {
            $stages[] = [
                'key' => $key,
                'label' => $label,
                'reached' => $reached[$key],
                'timestamp' => $timestamps[$key]?->toIso8601String(),
            ];
        }

        $terminalStatus = match (true) {
            $order->status === 'cancelled' => 'cancelled',
            $order->status === 'failed' => 'failed',
            $order->lgu_review_status === 'rejected' => 'rejected',
            $order->lgu_review_status === 'on_hold' => 'on_hold',
            default => null,
        };

        return [
            'stages' => $stages,
            'terminal_status' => $terminalStatus,
            'terminal_reason' => in_array($terminalStatus, ['rejected', 'on_hold'], true) ? $order->lgu_review_reason : null,
        ];
    }
}
