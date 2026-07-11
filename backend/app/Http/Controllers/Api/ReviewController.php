<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Review;
use Illuminate\Http\Request;

/**
 * Buyer -> Seller reviews (the reverse direction, Seller -> Buyer ratings,
 * lives in SellerController::rateBuyer). A review is only allowed once per
 * order and only after that order is completed, so ratings reflect real,
 * finished transactions. Suspended buyers cannot review. Each new review
 * refreshes the seller's cached average on seller_profiles.rating, keeping the
 * profile card, listings, and analytics in agreement.
 */
class ReviewController extends Controller
{
    /**
     * Create the review for a completed order the caller actually placed.
     *
     * @throws \Illuminate\Validation\ValidationException  On invalid rating/text.
     *         Returns 403 if the order isn't the caller's or they're suspended,
     *         422 if the order isn't completed or was already reviewed.
     */
    public function store(Request $request, Order $order)
    {
        if ($order->buyer_id !== $request->user()->id) {
            return response()->json(['message' => 'You can only review your own orders.'], 403);
        }

        abort_if($request->user()->status === 'suspended', 403, 'Your account has been suspended and cannot leave reviews. Contact support for assistance.');

        if ($order->status !== 'completed') {
            return response()->json(['message' => 'You can only review orders after they are completed.'], 422);
        }

        if ($order->review()->exists()) {
            return response()->json(['message' => 'You have already reviewed this order.'], 422);
        }

        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'title' => ['nullable', 'string', 'max:255'],
            'comment' => ['nullable', 'string'],
        ]);

        $review = Review::create([
            'order_id' => $order->id,
            'buyer_id' => $order->buyer_id,
            'seller_profile_id' => $order->seller_profile_id,
            'rating' => $data['rating'],
            'title' => $data['title'] ?? null,
            'comment' => $data['comment'] ?? null,
        ]);

        $order->sellerProfile()->update([
            'rating' => round(Review::where('seller_profile_id', $order->seller_profile_id)->avg('rating'), 2),
        ]);

        return response()->json($review, 201);
    }
}
