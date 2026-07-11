<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A comment on a Seller Post. Author is any authenticated role. Deletable by
 * the comment's author, the post's owning seller (moderation of their own
 * feed), or the Super Admin -- see SellerPostInteractionController.
 */
class SellerPostComment extends Model
{
    protected $fillable = ['seller_post_id', 'user_id', 'body'];

    public function post()
    {
        return $this->belongsTo(SellerPost::class, 'seller_post_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
