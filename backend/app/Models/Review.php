<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\SellerProfile;

class Review extends Model
{
    use HasFactory;

    protected $fillable = ['order_id', 'buyer_id', 'seller_profile_id', 'rating', 'comment'];

    public function sellerProfile()
    {
        return $this->belongsTo(SellerProfile::class);
    }
}
