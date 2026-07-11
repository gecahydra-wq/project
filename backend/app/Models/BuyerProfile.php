<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BuyerProfile extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'municipality_id', 'farm_name', 'water_source', 'pond_area', 'address', 'bio', 'rating', 'ratings_count'];

    protected $casts = [
        'rating' => 'decimal:2',
        'ratings_count' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
