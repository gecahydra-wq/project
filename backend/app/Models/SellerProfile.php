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
        'rating',
        'verified',
        'status',
    ];

    protected $casts = [
        'verified' => 'boolean',
        'rating' => 'decimal:2',
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
}
