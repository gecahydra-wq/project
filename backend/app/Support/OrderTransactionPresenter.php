<?php

namespace App\Support;

use App\Models\Order;

/**
 * The single Order Details payload shape reused by every role's Order
 * Lookup endpoint (OrderController::show, LguController::showOrder,
 * SuperAdminController::showOrder) and by AiDataQueryResolver's order-number
 * answers -- so there is exactly one place that assembles "what does this
 * transaction look like," never a per-role duplicate. Only LGU Admin and
 * Super Admin views get the revenue distribution preview and LGU
 * verification status; Buyer/Seller payloads never include them.
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
            'review' => $order->review ? [
                'rating' => $order->review->rating,
                'comment' => $order->review->comment,
            ] : null,
            'seller_notes' => $order->seller_notes,
            'timeline' => OrderTimeline::build($order),
            'created_at' => $order->created_at?->toIso8601String(),
        ];

        if (in_array($viewerRole, ['lgu_admin', 'super_admin'], true)) {
            $payload['revenue_distribution_preview'] = self::revenueDistributionPreview($order);
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
     * Read-only preview using the existing, unmodified commission rules
     * (App\Support\CommissionCalculator::split) -- never persisted here.
     * Once a Settlement exists, shows the actual frozen shares instead of a
     * hypothetical recomputation.
     */
    private static function revenueDistributionPreview(Order $order): array
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
        ];
    }
}
