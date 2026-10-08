<?php

namespace App\Support;

use App\Models\Order;

/**
 * The single Order Details payload shape reused by every role's Order
 * Lookup endpoint (OrderController::show, LguController::showOrder,
 * SuperAdminController::showOrder) and by AiDataQueryResolver's order-number
 * answers -- so there is exactly one place that assembles "what does this
 * transaction look like," never a per-role duplicate. Only LGU Admin and
 * Super Admin views get the LGU verification status. The seller, LGU Admin
 * and Super Admin all get the earnings breakdown (order total, platform fee,
 * LGU share, seller receives -- see earningsBreakdown()); the buyer never does.
 */
class OrderTransactionPresenter
{
    public static function present(Order $order, string $viewerRole): array
    {
        $order->loadMissing(['listing', 'buyer', 'sellerProfile.user', 'sellerProfile.municipality', 'payment', 'review', 'settlement', 'reviewedBy']);

        $seller = $order->sellerProfile;
        $payment = $order->payment;
        $settlement = $order->settlement;

        $payload = [
            'order_number' => $order->order_number,
            'order_status' => $order->status,
            'delivery_status' => self::deliveryStatus($order->status),
            'payment_status' => $payment?->status,
            'listing' => $order->listing ? [
                'id' => $order->listing->id,
                'title' => $order->listing->title,
                'species' => $order->listing->species,
                // So the order detail panel labels the quantity in the unit
                // the listing is actually sold in, not always "pcs".
                'unit_type' => $order->listing->unit_type,
                'unit_label' => $order->listing->unit_label,
                'unit_label_plural' => $order->listing->unit_label_plural,
                // "1 bulk = 10 fish", so an order for 3 bulk reads as a real
                // quantity of fish in the order detail panel too.
                'unit_contents_label' => $order->listing->unit_contents_label,
            ] : null,
            'buyer' => $order->buyer ? [
                'id' => $order->buyer->id,
                'name' => $order->buyer->name,
            ] : null,
            'seller' => $seller ? [
                'id' => $seller->id,
                'hatchery_name' => $seller->hatchery_name,
                'contact_name' => $seller->user?->name,
            ] : null,
            'municipality' => $seller?->municipality ? [
                'id' => $seller->municipality->id,
                'name' => $seller->municipality->name,
            ] : null,
            'quantity' => $order->quantity,
            'unit_price' => (float) $order->unit_price,
            'total_amount' => (float) $order->total_amount,
            // Why the seller cancelled. Null for every other status, and for
            // orders that expired unpaid -- the status already says which.
            'cancellation_reason' => $order->cancellation_reason,
            'review' => $order->review ? [
                'rating' => $order->review->rating,
                'comment' => $order->review->comment,
            ] : null,
            'seller_notes' => $order->seller_notes,
            'timeline' => OrderTimeline::build($order),
            'created_at' => $order->created_at?->toIso8601String(),
        ];

        // Since 2026-10-09 the seller sees the split too, so they know why
        // they receive less than the order total (user request).
        if (in_array($viewerRole, ['seller', 'lgu_admin', 'super_admin'], true)) {
            $payload['revenue_distribution_preview'] = self::earningsBreakdown($order);
        }

        if (in_array($viewerRole, ['lgu_admin', 'super_admin'], true)) {
            $payload['lgu_verification'] = [
                'status' => self::lguVerificationStatus($order),
                'review_reason' => $order->lgu_review_reason,
                'reviewed_at' => $order->lgu_reviewed_at?->toIso8601String(),
                'reviewed_by' => $order->reviewedBy?->name,
            ];
        }

        if ($viewerRole === 'super_admin') {
            // Settlement moves the seller's share into their Available
            // Balance (see LguController::approveEarnings); the actual
            // withdrawal/payout is a separate, unlinked lump-sum request
            // (see App\Support\SellerWallet), so this reports what this
            // order's own money has done, not a specific withdrawal's status.
            $payload['seller_payout_status'] = $settlement ? 'earnings_released_to_seller_wallet' : 'awaiting_settlement';
        }

        return $payload;
    }

    private static function deliveryStatus(string $orderStatus): string
    {
        return match ($orderStatus) {
            'placed', 'paid', 'confirmed' => 'Not yet shipped',
            'in_transit' => 'Out for delivery',
            'completed' => 'Delivered',
            'cancelled' => 'Cancelled',
            'failed' => 'Payment failed',
            default => ucfirst($orderStatus),
        };
    }

    private static function lguVerificationStatus(Order $order): string
    {
        return match (true) {
            (bool) $order->settlement => 'verified',
            $order->lgu_review_status === 'rejected' => 'rejected',
            $order->lgu_review_status === 'on_hold' => 'on_hold',
            $order->status === 'completed' => 'pending',
            default => 'not_applicable',
        };
    }

    /**
     * How an order's total splits into the seller's earnings, the LGU share
     * and the platform fee. Once a Settlement exists it shows the actual
     * frozen shares and percentages (so an order settled under the old 96/4
     * rule still reads 96/4); before that it is a preview using today's
     * rules (App\Support\CommissionCalculator::split) -- never persisted.
     * Shared by Order Details, the seller's Payment History and the
     * earnings-approved email so all three always show the same numbers.
     */
    public static function earningsBreakdown(Order $order): array
    {
        $settlement = $order->settlement;

        if ($settlement) {
            return [
                'source' => 'settled',
                'gross_amount' => (float) $settlement->gross_amount,
                'seller_share' => (float) $settlement->seller_share,
                'lgu_share' => (float) $settlement->lgu_share,
                'platform_share' => (float) $settlement->platform_share,
                'seller_percent' => (float) $settlement->seller_percent,
                'lgu_percent' => (float) $settlement->lgu_percent,
                'platform_percent' => (float) $settlement->platform_percent,
            ];
        }

        $gross = (float) ($order->payment?->amount ?? $order->total_amount);
        $split = CommissionCalculator::split($gross);

        return [
            'source' => 'preview',
            'gross_amount' => $gross,
            'seller_share' => $split['seller_share'],
            'lgu_share' => $split['lgu_share'],
            'platform_share' => $split['platform_share'],
            'seller_percent' => $split['seller_percent'],
            'lgu_percent' => $split['lgu_percent'],
            'platform_percent' => $split['platform_percent'],
        ];
    }
}
