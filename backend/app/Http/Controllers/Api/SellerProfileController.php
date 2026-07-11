<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Review;
use App\Models\SellerPostLike;
use App\Models\SellerProfile;
use Illuminate\Http\Request;

class SellerProfileController extends Controller
{
    public function index()
    {
        return response()->json(
            SellerProfile::with(['user', 'municipality'])
                ->where('status', '!=', 'suspended')
                ->withCount(['listings' => fn ($q) => $q->where('approval_status', 'approved')])
                ->get()
        );
    }

    public function show(Request $request, SellerProfile $seller)
    {
        abort_if($seller->status === 'suspended', 404);

        $seller->load(['user', 'municipality']);

        // Optional viewer -- this is a public route with no auth middleware, so
        // resolve the token holder (if any) through the sanctum guard directly.
        // A guest simply gets liked_by_me = false everywhere.
        $viewerId = $request->user('sanctum')?->id;

        // Seller Posts feed -- public for every viewer (buyer/seller/LGU/Super
        // Admin). Each post carries its like count, this viewer's like state,
        // and its comments (with only safe, public author fields). Only the
        // owner can mutate the posts themselves; likes/comments are open to
        // every authenticated role (see SellerPostInteractionController).
        $posts = $seller->posts()
            ->with(['media', 'comments.user:id,name,profile_picture,role'])
            ->withCount('likes')
            ->get();

        $likedPostIds = $viewerId
            ? SellerPostLike::where('user_id', $viewerId)->whereIn('seller_post_id', $posts->pluck('id'))->pluck('seller_post_id')->all()
            : [];

        $posts->each(fn ($post) => $post->setAttribute('liked_by_me', in_array($post->id, $likedPostIds, true)));

        return response()->json([
            'seller' => $seller,
            'listings' => $seller->listings()->where('approval_status', 'approved')->with('media')->latest()->get(),
            'posts' => $posts,
            'reviews' => Review::where('seller_profile_id', $seller->id)->with('buyer')->latest()->get(),
            'completed_sales' => Order::where('seller_profile_id', $seller->id)->where('status', 'completed')->count(),
        ]);
    }
}
