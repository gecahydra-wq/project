<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\SellerProfile;
use App\Models\User;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_number',
        'buyer_id',
        'seller_profile_id',
        'listing_id',
        'quantity',
        'unit_price',
        'total_amount',
        'status',
        'pickup_notes',
        'seller_notes',
        'lgu_review_status',
        'lgu_review_reason',
        'lgu_reviewed_at',
        'lgu_reviewed_by',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'lgu_reviewed_at' => 'datetime',
    ];

    public function payment()
    {
        return $this->hasOne(MockPayment::class);
    }

    public function listing()
    {
        return $this->belongsTo(FingerlingListing::class, 'listing_id');
    }

    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function sellerProfile()
    {
        return $this->belongsTo(SellerProfile::class);
    }

    public function review()
    {
        return $this->hasOne(Review::class);
    }

    public function buyerRating()
    {
        return $this->hasOne(BuyerRating::class);
    }

    public function settlement()
    {
        return $this->hasOne(Settlement::class);
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'lgu_reviewed_by');
    }
}
