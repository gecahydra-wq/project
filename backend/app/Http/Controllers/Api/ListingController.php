<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FingerlingListing;
use App\Models\ListingMedia;
use App\Models\SellerProfile;
use App\Support\ImageUploader;
use App\Support\SellerApproval;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Fingerling listings -- the marketplace catalogue and the seller's own
 * listing/media management.
 *
 * Public reads (index/show) only ever surface APPROVED listings from
 * non-suspended sellers; the LGU/Super Admin moderation views use their own
 * controllers to see pending/rejected/archived ones. All writes are restricted
 * to the listing's owning seller. Suspended sellers -- and sellers whose
 * registration has not been approved yet -- are blocked from creating or
 * editing. A listing with existing orders can't be deleted (the
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
            // Frozen sellers (under an open Notice to Explain) come off the
            // marketplace exactly like suspended ones -- see SellerSanctions.
            ->whereHas('sellerProfile', fn ($q) => $q->where('status', '!=', 'suspended')->whereNull('listings_frozen_at'));

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
        abort_if((bool) $listing->sellerProfile?->listings_frozen_at, 404);
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

        // Otherwise a frozen seller could simply post new listings and carry on
        // selling, which would make the freeze meaningless.
        if ($seller->listings_frozen_at) {
            return response()->json(['message' => 'Your listings are frozen while your Notice to Explain is open. You cannot post new listings until your LGU accepts your explanation.'], 403);
        }

        if ($response = $this->guardRegistrationApproved($seller)) {
            return $response;
        }

        $data = $request->validate([
            'species' => ['required', 'string'],
            'scientific_name' => ['nullable', 'string'],
            'title' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'quantity' => ['required', 'integer', 'min:1'],
            // The price of ONE unit_type unit -- per piece, per kilogram, or
            // per bulk, whichever the seller chose.
            'price_per_piece' => ['required', 'numeric', 'min:0.01'],
            'unit_type' => ['nullable', Rule::in(FingerlingListing::SELECTABLE_UNIT_TYPES)],
            'minimum_order' => ['nullable', 'integer', 'min:1'],
            'unit_description' => ['nullable', 'string', 'max:255'],
            // A bulk listing MUST say how many fish one bulk holds. Without it
            // the buyer cannot tell what they are buying and the Turnout/ROI
            // projection has to skip the purchase. Optional for kilogram, where
            // a count is useful but not everyone can give one, and ignored for
            // piece, where one unit is one fish by definition.
            'pieces_per_unit' => [
                Rule::requiredIf(fn () => $request->input('unit_type') === 'bulk'),
                'nullable', 'integer', 'min:1', 'max:1000000',
            ],
            'average_size' => ['nullable', 'string'],
            'availability_status' => ['nullable', 'string'],
            // At least one photo is mandatory. Buyers are committing money up
            // front to fingerlings they cannot inspect, so a listing with no
            // picture is not something we want on the marketplace at all.
            // Sent in the SAME request as the listing -- see the note below on
            // why this is not the separate uploadMedia() call.
            'photos' => ['required', 'array', 'min:1', 'max:'.self::MAX_MEDIA_PER_LISTING],
            'photos.*' => ['required', 'file'],
        ], [
            'pieces_per_unit.required' => 'Tell buyers how many fish are in one bulk.',
            'photos.required' => 'Add at least one photo of your fingerlings.',
            'photos.min' => 'Add at least one photo of your fingerlings.',
            'photos.max' => 'A listing can have at most '.self::MAX_MEDIA_PER_LISTING.' photos or videos.',
        ]);

        $photos = $request->file('photos');

        // Validate every file BEFORE the listing row exists. Creating the
        // listing first and then rejecting a bad file would leave exactly the
        // photo-less listing this rule is meant to prevent.
        foreach ($photos as $photo) {
            if ($error = ImageUploader::validateMediaFile($photo)) {
                return response()->json(['message' => $error], 422);
            }
        }

        unset($data['photos']);
        $data['seller_profile_id'] = $seller->id;
        $data['municipality_id'] = $seller->municipality_id;

        // One transaction so a listing can never be committed without its
        // photos: if a file fails to store, the listing is rolled back too.
        $listing = DB::transaction(function () use ($data, $photos) {
            $listing = FingerlingListing::create($data);

            foreach (array_values($photos) as $position => $photo) {
                $type = ImageUploader::detectMediaType($photo);
                $listing->media()->create([
                    'type' => $type,
                    'title' => $type === 'video' ? 'Farm video' : 'Farm photo',
                    'url' => ImageUploader::store($photo, "listings/{$listing->id}"),
                    'position' => $position,
                ]);
            }

            return $listing;
        });

        return response()->json($listing->load('media'), 201);
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

        if ($response = $this->guardRegistrationApproved($seller)) {
            return $response;
        }

        $data = $request->validate([
            'species' => ['sometimes', 'string'],
            'scientific_name' => ['nullable', 'string'],
            'title' => ['sometimes', 'string'],
            'description' => ['nullable', 'string'],
            'quantity' => ['sometimes', 'integer', 'min:0'],
            'price_per_piece' => ['sometimes', 'numeric', 'min:0.01'],
            'unit_type' => ['sometimes', Rule::in(FingerlingListing::SELECTABLE_UNIT_TYPES)],
            'minimum_order' => ['sometimes', 'integer', 'min:1'],
            'unit_description' => ['nullable', 'string', 'max:255'],
            // Required when this edit leaves the listing sold by bulk with no
            // count on file -- either by switching it to bulk, or by editing a
            // bulk listing created before this field existed. Deliberately not
            // 'sometimes', which would let the rule be skipped by omitting it.
            'pieces_per_unit' => [
                Rule::requiredIf(fn () => $request->input('unit_type', $listing->unit_type) === 'bulk'
                    && ! $listing->pieces_per_unit),
                'nullable', 'integer', 'min:1', 'max:1000000',
            ],
            'average_size' => ['nullable', 'string'],
            'availability_status' => ['nullable', 'string'],
        ], [
            'pieces_per_unit.required' => 'Tell buyers how many fish are in one bulk.',
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

        // A photo is mandatory at creation, so it has to stay mandatory
        // afterwards -- otherwise a seller could publish a listing with a photo
        // and then delete it, which is the same photo-less listing by a longer
        // route. Replacing the last photo means adding the new one first.
        if ($listing->media()->count() <= 1) {
            return response()->json([
                'message' => 'A listing must keep at least one photo. Upload a replacement before removing this one.',
            ], 422);
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

    /**
     * The Seller Registration Approval gate: a seller may only create or edit
     * listings once their registration has been approved by an LGU Admin or
     * the Super Admin (App\Support\SellerApproval). Returns a 403 response to
     * return, or null when the seller is cleared.
     */
    private function guardRegistrationApproved(SellerProfile $seller): ?\Illuminate\Http\JsonResponse
    {
        if (SellerApproval::isApproved($seller)) {
            return null;
        }

        $message = $seller->approval_status === SellerApproval::REJECTED
            ? 'Your seller registration was rejected'.($seller->registration_rejection_reason ? ": {$seller->registration_rejection_reason}" : '.').' Contact your LGU to have it reviewed again.'
            : 'Your seller registration is still awaiting approval. You can manage listings once your LGU Admin has approved it.';

        return response()->json(['message' => $message], 403);
    }
}
