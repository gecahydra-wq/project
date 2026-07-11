<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Media attached to a Seller Post -- mirrors App\Models\ListingMedia (a photo
 * or video with a position) but scoped to seller_posts, never to listings.
 */
class SellerPostMedia extends Model
{
    protected $table = 'seller_post_media';

    protected $fillable = ['seller_post_id', 'type', 'title', 'url', 'position'];

    public function post()
    {
        return $this->belongsTo(SellerPost::class, 'seller_post_id');
    }
}
