<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A Seller Post -- one entry in a seller's farm/hatchery feed. Separate from
 * listings and listing media entirely (see the seller_posts migration). Media
 * is ordered by position, exactly like FingerlingListing::media().
 */
class SellerPost extends Model
{
    protected $fillable = ['seller_profile_id', 'body'];

    public function sellerProfile()
    {
        return $this->belongsTo(SellerProfile::class);
    }

    public function media()
    {
        return $this->hasMany(SellerPostMedia::class, 'seller_post_id')->orderBy('position');
    }

    public function likes()
    {
        return $this->hasMany(SellerPostLike::class, 'seller_post_id');
    }

    public function comments()
    {
        return $this->hasMany(SellerPostComment::class, 'seller_post_id')->oldest();
    }
}
