<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SellerPost;
use App\Models\SellerPostMedia;
use App\Models\SellerProfile;
use App\Support\ImageUploader;
use Illuminate\Http\Request;

/**
 * Seller Posts -- a seller's farm/hatchery feed. Every write here is scoped to
 * the authenticated seller's OWN profile (see ownProfile()/authorizeOwnPost);
 * reading is public and served by SellerProfileController::show, so buyers,
 * other sellers, LGU Admins, and the Super Admin all see the same posts.
 *
 * Media handling deliberately reuses the exact listing-media pipeline
 * (ImageUploader::validateMediaFile/detectMediaType/store) so posts and
 * listings stay consistent -- but the two data sets never mix: post media
 * lives in seller_post_media, listing media in listing_media.
 */
class SellerPostController extends Controller
{
    private const MAX_MEDIA_PER_POST = 10;

    public function store(Request $request)
    {
        $seller = $this->ownProfile($request);

        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:5000'],
            'media' => ['nullable', 'array', 'max:'.self::MAX_MEDIA_PER_POST],
            'media.*' => ['file'],
        ]);

        $files = $request->file('media', []);

        if (blank($data['body'] ?? null) && count($files) === 0) {
            return response()->json(['message' => 'A post needs some text or at least one photo or video.'], 422);
        }

        foreach ($files as $file) {
            if ($error = ImageUploader::validateMediaFile($file)) {
                return response()->json(['message' => $error], 422);
            }
        }

        $post = $seller->posts()->create(['body' => $data['body'] ?? null]);

        $position = 0;
        foreach ($files as $file) {
            $type = ImageUploader::detectMediaType($file);
            $post->media()->create([
                'type' => $type,
                'title' => $type === 'video' ? 'Farm video' : 'Farm photo',
                'url' => ImageUploader::store($file, "seller-posts/{$post->id}"),
                'position' => $position++,
            ]);
        }

        return response()->json($post->fresh('media'), 201);
    }

    public function update(Request $request, SellerPost $post)
    {
        $this->authorizeOwnPost($request, $post);

        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:5000'],
        ]);

        if (blank($data['body'] ?? null) && $post->media()->count() === 0) {
            return response()->json(['message' => 'A post needs some text or at least one photo or video.'], 422);
        }

        $post->update(['body' => $data['body'] ?? null]);

        return response()->json($post->fresh('media'));
    }

    public function destroy(Request $request, SellerPost $post)
    {
        $this->authorizeOwnPost($request, $post);

        // Remove the stored files before the rows cascade away, so nothing is
        // orphaned on disk.
        foreach ($post->media as $media) {
            ImageUploader::delete($media->url);
        }

        $post->delete();

        return response()->json(['message' => 'Post deleted.']);
    }

    public function addMedia(Request $request, SellerPost $post)
    {
        $this->authorizeOwnPost($request, $post);

        $request->validate([
            'media' => ['required', 'array', 'min:1'],
            'media.*' => ['required', 'file'],
        ]);

        $files = $request->file('media');
        $existingCount = $post->media()->count();

        if ($existingCount + count($files) > self::MAX_MEDIA_PER_POST) {
            return response()->json(['message' => 'A post can have at most '.self::MAX_MEDIA_PER_POST.' photos or videos.'], 422);
        }

        foreach ($files as $file) {
            if ($error = ImageUploader::validateMediaFile($file)) {
                return response()->json(['message' => $error], 422);
            }
        }

        $position = $existingCount;
        foreach ($files as $file) {
            $type = ImageUploader::detectMediaType($file);
            $post->media()->create([
                'type' => $type,
                'title' => $type === 'video' ? 'Farm video' : 'Farm photo',
                'url' => ImageUploader::store($file, "seller-posts/{$post->id}"),
                'position' => $position++,
            ]);
        }

        return response()->json($post->fresh('media'));
    }

    public function deleteMedia(Request $request, SellerPost $post, SellerPostMedia $media)
    {
        $this->authorizeOwnPost($request, $post);

        if ($media->seller_post_id !== $post->id) {
            return response()->json(['message' => 'This media does not belong to that post.'], 404);
        }

        ImageUploader::delete($media->url);
        $media->delete();

        return response()->json($post->fresh('media'));
    }

    private function ownProfile(Request $request): SellerProfile
    {
        return SellerProfile::where('user_id', $request->user()->id)->firstOrFail();
    }

    private function authorizeOwnPost(Request $request, SellerPost $post): void
    {
        abort_if($post->seller_profile_id !== $this->ownProfile($request)->id, 403, 'You can only manage your own posts.');
    }
}
