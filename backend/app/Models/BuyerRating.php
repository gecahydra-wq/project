<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A seller's rating of a buyer for one completed order -- the reverse of a
 * Review (see the buyer_ratings migration). One per order.
 */
class BuyerRating extends Model
{
    protected $fillable = ['order_id', 'seller_profile_id', 'buyer_id', 'rating', 'comment'];

    protected $casts = ['rating' => 'integer'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function sellerProfile()
    {
        return $this->belongsTo(SellerProfile::class);
    }

    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }
}
