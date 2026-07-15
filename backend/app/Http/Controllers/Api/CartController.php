<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\FingerlingListing;
use Illuminate\Http\Request;

/**
 * The Buyer's "buy later" cart -- a saved-listings shortlist, NOT a second
 * ordering path.
 *
 * Nothing here reserves stock, moves money, or creates an order: checking out
 * a cart item goes through the untouched OrderController::store +
 * OrderController::checkout flow, one order per listing, exactly as buying
 * straight from a listing page always has. That keeps the escrow/settlement
 * model (one order = one listing = one payment) intact -- see OrderController.
 *
 * Because a cart row is only a bookmark, price and availability are always
 * re-read live from the listing (see presentItem), so a saved item can never
 * lock in a stale price or a sold-out quantity.
 */
class CartController extends Controller
{
    public function index(Request $request)
    {
        $items = CartItem::with(['listing.sellerProfile.user', 'listing.municipality', 'listing.media'])
            ->where('buyer_id', $request->user()->id)
            ->latest()
            ->get()
            ->map(fn (CartItem $item) => self::presentItem($item));

        return response()->json([
            'items' => $items->values(),
            // Only purchasable lines count toward the total -- an out-of-stock
            // or unapproved saved item shouldn't inflate what the buyer sees.
            'subtotal' => round($items->where('available', true)->sum('line_total'), 2),
            'count' => $items->count(),
        ]);
    }

    /**
     * Save a listing for later. Adding a listing that's already in the cart
     * tops up the existing row instead of creating a duplicate (the
     * (buyer_id, listing_id) unique index guarantees there's only ever one).
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'fingerling_listing_id' => ['required', 'exists:listings,id'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $listing = FingerlingListing::findOrFail($data['fingerling_listing_id']);

        if ($listing->approval_status !== 'approved') {
            return response()->json(['message' => 'This listing is not currently available.'], 422);
        }

        $existing = CartItem::where('buyer_id', $request->user()->id)
            ->where('listing_id', $listing->id)
            ->first();

        $quantity = ($existing?->quantity ?? 0) + $data['quantity'];

        if ($quantity > $listing->quantity) {
            return response()->json(['message' => 'Requested quantity exceeds available stock.'], 422);
        }

        $item = CartItem::updateOrCreate(
            ['buyer_id' => $request->user()->id, 'listing_id' => $listing->id],
            ['quantity' => $quantity],
        );

        return response()->json(self::presentItem($item->fresh(['listing.sellerProfile.user', 'listing.municipality', 'listing.media'])), $existing ? 200 : 201);
    }

    public function update(Request $request, CartItem $item)
    {
        $this->authorizeItem($request, $item);

        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $item->loadMissing('listing');

        if ($data['quantity'] > $item->listing->quantity) {
            return response()->json(['message' => 'Requested quantity exceeds available stock.'], 422);
        }

        $item->update(['quantity' => $data['quantity']]);

        return response()->json(self::presentItem($item->fresh(['listing.sellerProfile.user', 'listing.municipality', 'listing.media'])));
    }

    public function destroy(Request $request, CartItem $item)
    {
        $this->authorizeItem($request, $item);

        $item->delete();

        return response()->json(['message' => 'Item removed from your cart.']);
    }

    public function clear(Request $request)
    {
        CartItem::where('buyer_id', $request->user()->id)->delete();

        return response()->json(['message' => 'Cart cleared.']);
    }

    private function authorizeItem(Request $request, CartItem $item): void
    {
        abort_if($item->buyer_id !== $request->user()->id, 403, 'You can only manage your own cart.');
    }

    /**
     * Shapes one saved line for the UI, resolving price/stock live and saying
     * plainly why a line can't be bought right now (the seller may have
     * archived the listing or sold out since it was saved).
     */
    private static function presentItem(CartItem $item): array
    {
        $listing = $item->listing;
        $unitPrice = (float) ($listing?->price_per_piece ?? 0);

        $issue = match (true) {
            ! $listing => 'This listing is no longer available.',
            $listing->approval_status !== 'approved' => 'This listing is no longer available.',
            (int) $listing->quantity <= 0 => 'This listing is out of stock.',
            $item->quantity > (int) $listing->quantity => sprintf('Only %s pcs left -- lower the quantity to check out.', number_format((int) $listing->quantity)),
            default => null,
        };

        return [
            'id' => $item->id,
            'quantity' => $item->quantity,
            'unit_price' => $unitPrice,
            'line_total' => round($unitPrice * $item->quantity, 2),
            'available' => $issue === null,
            'issue' => $issue,
            'saved_at' => $item->created_at?->toIso8601String(),
            'listing' => $listing ? [
                'id' => $listing->id,
                'title' => $listing->title,
                'species' => $listing->species,
                'price_per_piece' => $unitPrice,
                'quantity' => (int) $listing->quantity,
                'approval_status' => $listing->approval_status,
                'media' => $listing->media,
                'municipality' => $listing->municipality ? ['id' => $listing->municipality->id, 'name' => $listing->municipality->name] : null,
                'sellerProfile' => $listing->sellerProfile ? [
                    'id' => $listing->sellerProfile->id,
                    'hatchery_name' => $listing->sellerProfile->hatchery_name,
                    'profile_picture' => $listing->sellerProfile->profile_picture,
                    'user' => $listing->sellerProfile->user ? ['id' => $listing->sellerProfile->user->id, 'name' => $listing->sellerProfile->user->name] : null,
                ] : null,
            ] : null,
        ];
    }
}
