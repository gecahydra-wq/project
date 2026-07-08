<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FingerlingListing extends Model
{
    use HasFactory;

    protected $table = 'listings';

    protected $fillable = [
        'seller_profile_id',
        'municipality_id',
        'species',
        'scientific_name',
        'title',
        'quantity',
        'price_per_piece',
        'average_size',
        'availability_status',
        'approval_status',
    ];

    protected $casts = [
        'price_per_piece' => 'decimal:2',
    ];

    public function sellerProfile()
    {
        return $this->belongsTo(SellerProfile::class);
    }

    public function municipality()
    {
        return $this->belongsTo(Municipality::class);
    }

    public function media()
    {
        return $this->hasMany(ListingMedia::class, 'listing_id');
    }
}
