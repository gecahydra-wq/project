<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Review;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function store(Request $request, Order $order)
    {
        if ($order->buyer_id !== $request->user()->id) {
            return response()->json(['message' => 'You can only review your own orders.'], 403);
        }

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
