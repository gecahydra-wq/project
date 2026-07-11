<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single like on a Seller Post -- one per user per post (enforced by the
 * unique index in the migration). Any authenticated role may like a post.
 */
class SellerPostLike extends Model
{
    protected $fillable = ['seller_post_id', 'user_id'];

    public function post()
    {
        return $this->belongsTo(SellerPost::class, 'seller_post_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
