<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One saved listing in a Buyer's cart. Holds no price -- see the cart_items
 * migration: a cart entry is a bookmark, not a reservation, so price and
 * stock are always read live from the related listing.
 */
class CartItem extends Model
{
    use HasFactory;

    protected $fillable = ['buyer_id', 'listing_id', 'quantity'];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function listing()
    {
        return $this->belongsTo(FingerlingListing::class, 'listing_id');
    }
}
