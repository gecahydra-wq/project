<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SellerProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'municipality_id',
        'hatchery_name',
        'description',
        'farming_methods',
        'fish_raising_practices',
        'farm_history',
        'water_source',
        'feeding_practices',
        'years_experience',
        'certifications',
        'address',
        'profile_picture',
        'cover_photo',
        'gallery',
        'rating',
        'verified',
        'status',
    ];

    protected $casts = [
        'verified' => 'boolean',
        'rating' => 'decimal:2',
        'gallery' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function municipality()
    {
        return $this->belongsTo(Municipality::class);
    }

    public function listings()
    {
        return $this->hasMany(FingerlingListing::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    public function posts()
    {
        return $this->hasMany(SellerPost::class)->latest();
    }

    public function withdrawalRequests()
    {
        return $this->hasMany(WithdrawalRequest::class);
    }
}
