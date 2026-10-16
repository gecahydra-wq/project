<?php

namespace App\Http\Controllers\Api;

use App\Models\Order;
use App\Support\ImageUploader;
use App\Support\RefundRequests;
use Illuminate\Http\Request;

/**
 * The buyer's side of a post-completion refund request: filing one. Everything
 * that happens to it afterwards belongs to the Super Admin (see
 * SuperAdminController::refundRequests / approveRefundRequest /
 * denyRefundRequest).
 *
 * This is NOT the cancellation flow. A cancellation refund is queued by the app
 * itself (App\Support\OrderCancellation); a buyer asking for their money back
 * after they already received the order is a request that a person decides.
 */
class RefundRequestController
{
    /**
     * File a refund request for one of the caller's own completed orders.
     *
     * Ownership is checked here; whether the order is still inside its window,
     * and whether it may be refunded at all, is re-checked inside
     * App\Support\RefundRequests under a lock -- the client's word on any of it
     * is worth nothing.
     */
    public function store(Request $request, Order $order)
    {
        abort_if($order->buyer_id !== $request->user()->id, 403, 'You can only request a refund for your own order.');

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:'.RefundRequests::MAX_REASON_LENGTH],
            'evidence' => ['nullable', 'array', 'max:'.RefundRequests::MAX_EVIDENCE_PHOTOS],
            'evidence.*' => ['required', 'file'],
        ], [
            'reason.required' => 'Tell us why you are requesting a refund.',
            'evidence.max' => 'You can attach at most '.RefundRequests::MAX_EVIDENCE_PHOTOS.' photos.',
        ]);

        $evidence = $request->file('evidence') ?? [];

        // Checked before anything is written, exactly like a listing's photos,
        // so a rejected file cannot leave a half-filed request behind.
        foreach ($evidence as $photo) {
            if ($error = ImageUploader::validateMediaFile($photo)) {
                return response()->json(['message' => $error], 422);
            }
        }

        $refundRequest = RefundRequests::file(
            $order,
            $request->user(),
            $data['reason'],
            $evidence
        );

        $refundRequest->loadMissing(['order.listing', 'order.sellerProfile', 'payment', 'buyer', 'media']);

        return response()->json([
            'message' => 'Your refund request has been submitted for review.',
            'refund_request' => RefundRequests::present($refundRequest),
        ], 201);
    }
}
