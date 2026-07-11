<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SellerPost;
use App\Models\SellerPostComment;
use App\Models\SellerPostLike;
use Illuminate\Http\Request;

/**
 * Engagement on Seller Posts -- likes and comments -- open to EVERY
 * authenticated role (buyer, seller, LGU admin, Super Admin), not just the
 * post owner. Reads come with the public seller profile
 * (SellerProfileController::show); these are the write paths.
 */
class SellerPostInteractionController extends Controller
{
    /**
     * Idempotent like toggle -- one like per user per post. Returns the fresh
     * count and this user's new like state so the UI never has to guess.
     */
    public function toggleLike(Request $request, SellerPost $post)
    {
        $existing = SellerPostLike::where('seller_post_id', $post->id)
            ->where('user_id', $request->user()->id)
            ->first();

        if ($existing) {
            $existing->delete();
            $liked = false;
        } else {
            SellerPostLike::create(['seller_post_id' => $post->id, 'user_id' => $request->user()->id]);
            $liked = true;
        }

        return response()->json([
            'likes_count' => $post->likes()->count(),
            'liked_by_me' => $liked,
        ]);
    }

    public function storeComment(Request $request, SellerPost $post)
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $comment = $post->comments()->create([
            'user_id' => $request->user()->id,
            'body' => $data['body'],
        ]);

        // Only the safe, public-facing author fields -- never email/phone.
        $comment->load('user:id,name,profile_picture,role');

        return response()->json($comment, 201);
    }

    /**
     * A comment may be removed ONLY by its own author or by the Super Admin.
     * The seller who owns the post deliberately cannot delete other people's
     * comments on their feed -- moderation of others' comments is reserved for
     * the Super Admin, so a seller can't silence buyer/competitor feedback.
     */
    public function destroyComment(Request $request, SellerPostComment $comment)
    {
        $user = $request->user();

        $isAuthor = $comment->user_id === $user->id;
        $isSuperAdmin = $user->role === 'super_admin';

        abort_unless($isAuthor || $isSuperAdmin, 403, 'You cannot delete this comment.');

        $comment->delete();

        return response()->json(['message' => 'Comment deleted.']);
    }
}
