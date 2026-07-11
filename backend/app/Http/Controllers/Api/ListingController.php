<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FingerlingListing;
use App\Models\ListingMedia;
use App\Models\SellerProfile;
use App\Support\ImageUploader;
use Illuminate\Http\Request;

/**
 * Fingerling listings -- the marketplace catalogue and the seller's own
 * listing/media management.
 *
 * Public reads (index/show) only ever surface APPROVED listings from
 * non-suspended sellers; the LGU/Super Admin moderation views use their own
 * controllers to see pending/rejected/archived ones. All writes are restricted
 * to the listing's owning seller, and suspended sellers are blocked from
 * creating or editing. A listing with existing orders can't be deleted (the
 * order history must be preserved) -- setting quantity to 0 takes it off the
 * market instead.
 */
class ListingController extends Controller
{
    /** Hard cap on photos/videos per listing, enforced on upload. */
    private const MAX_MEDIA_PER_LISTING = 5;

    /**
     * Public marketplace catalogue with optional species/municipality/max-price/
     * search filters. Excludes anything not approved or whose seller is
     * suspended, so buyers never see unavailable stock.
     */
    public function index(Request $request)
    {
        $query = FingerlingListing::query()
            ->with(['sellerProfile.user:id,name', 'municipality', 'media'])
            ->where('approval_status', 'approved')
            ->whereHas('sellerProfile', fn ($q) => $q->where('status', '!=', 'suspended'));

        $query->when($request->species, fn ($q, $species) => $q->where('species', $species));
        $query->when($request->municipality_id, fn ($q, $id) => $q->where('municipality_id', $id));
        $query->when($request->max_price, fn ($q, $price) => $q->where('price_per_piece', '<=', $price));
        $query->when($request->search, function ($q, $search) {
            $q->where(fn ($inner) => $inner
                ->where('title', 'like', "%{$search}%")
                ->orWhere('species', 'like', "%{$search}%"));
        });

        return response()->json($query->latest()->get());
    }

    public function show(FingerlingListing $listing)
    {
        abort_if($listing->sellerProfile?->status === 'suspended', 404);
        abort_if($listing->approval_status !== 'approved', 404);

        return response()->json($listing->load(['sellerProfile.user', 'municipality', 'media']));
    }

    /**
     * Create a listing (Seller only). Inherits the seller's municipality so a
     * listing is always tied to the same locality as its seller for LGU
     * scoping. New listings start unapproved (the model default) and await LGU
     * moderation before appearing in index().
     */
    public function store(Request $request)
    {
        $seller = SellerProfile::where('user_id', $request->user()->id)->firstOrFail();

        if ($seller->status === 'suspended') {
            return response()->json(['message' => 'Suspended sellers cannot create listings.'], 403);
        }

        $data = $request->validate([
            'species' => ['required', 'string'],
            'scientific_name' => ['nullable', 'string'],
            'title' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'quantity' => ['required', 'integer', 'min:1'],
            'price_per_piece' => ['required', 'numeric', 'min:0.01'],
            'average_size' => ['nullable', 'string'],
            'availability_status' => ['nullable', 'string'],
        ]);

        $data['seller_profile_id'] = $seller->id;
        $data['municipality_id'] = $seller->municipality_id;

        return response()->json(FingerlingListing::create($data), 201);
    }

    public function update(Request $request, FingerlingListing $listing)
    {
        $seller = SellerProfile::where('user_id', $request->user()->id)->firstOrFail();

        if ($listing->seller_profile_id !== $seller->id) {
            return response()->json(['message' => 'You can only edit your own listings.'], 403);
        }

        if ($seller->status === 'suspended') {
            return response()->json(['message' => 'Suspended sellers cannot edit listings.'], 403);
        }

        $data = $request->validate([
            'species' => ['sometimes', 'string'],
            'scientific_name' => ['nullable', 'string'],
            'title' => ['sometimes', 'string'],
            'description' => ['nullable', 'string'],
            'quantity' => ['sometimes', 'integer', 'min:0'],
            'price_per_piece' => ['sometimes', 'numeric', 'min:0.01'],
            'average_size' => ['nullable', 'string'],
            'availability_status' => ['nullable', 'string'],
        ]);

        $listing->update($data);

        return response()->json($listing->fresh(['sellerProfile', 'municipality', 'media']));
    }

    public function destroy(Request $request, FingerlingListing $listing)
    {
        $seller = SellerProfile::where('user_id', $request->user()->id)->firstOrFail();

        if ($listing->seller_profile_id !== $seller->id) {
            return response()->json(['message' => 'You can only delete your own listings.'], 403);
        }

        if ($listing->orders()->exists()) {
            return response()->json(['message' => 'This listing has existing orders and cannot be deleted. Set its quantity to 0 to take it off the market instead.'], 422);
        }

        $listing->delete();

        return response()->json(['message' => 'Listing deleted.']);
    }

    /**
     * Add photos/videos to a listing (owner only), up to MAX_MEDIA_PER_LISTING
     * total. Each file's real MIME type is validated and it's stored via
     * ImageUploader; new media is appended after any existing media by position.
     */
    public function uploadMedia(Request $request, FingerlingListing $listing)
    {
        $this->authorizeOwnListing($request, $listing);

        $request->validate([
            'photos' => ['required', 'array', 'min:1'],
            'photos.*' => ['required', 'file'],
        ]);

        $photos = $request->file('photos');
        $existingCount = $listing->media()->count();

        if ($existingCount + count($photos) > self::MAX_MEDIA_PER_LISTING) {
            return response()->json(['message' => 'A listing can have at most '.self::MAX_MEDIA_PER_LISTING.' photos or videos.'], 422);
        }

        foreach ($photos as $photo) {
            if ($error = ImageUploader::validateMediaFile($photo)) {
                return response()->json(['message' => $error], 422);
            }
        }

        $nextPosition = $existingCount;
        foreach ($photos as $photo) {
            $type = ImageUploader::detectMediaType($photo);
            $listing->media()->create([
                'type' => $type,
                'title' => $type === 'video' ? 'Farm video' : 'Farm photo',
                'url' => ImageUploader::store($photo, "listings/{$listing->id}"),
                'position' => $nextPosition++,
            ]);
        }

        return response()->json($listing->fresh('media'));
    }

    public function deleteMedia(Request $request, FingerlingListing $listing, ListingMedia $media)
    {
        $this->authorizeOwnListing($request, $listing);

        if ($media->listing_id !== $listing->id) {
            return response()->json(['message' => 'This image does not belong to that listing.'], 404);
        }

        ImageUploader::delete($media->url);
        $media->delete();

        return response()->json($listing->fresh('media'));
    }

    public function reorderMedia(Request $request, FingerlingListing $listing)
    {
        $this->authorizeOwnListing($request, $listing);

        $data = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer', 'exists:listing_media,id'],
        ]);

        foreach ($data['order'] as $index => $mediaId) {
            ListingMedia::where('id', $mediaId)->where('listing_id', $listing->id)->update(['position' => $index]);
        }

        return response()->json($listing->fresh('media'));
    }

    /**
     * Guard shared by the media endpoints: aborts 403 unless the caller is the
     * seller who owns the listing.
     */
    private function authorizeOwnListing(Request $request, FingerlingListing $listing): void
    {
        $seller = SellerProfile::where('user_id', $request->user()->id)->firstOrFail();

        abort_if($listing->seller_profile_id !== $seller->id, 403, 'You can only manage images on your own listings.');
    }
}
