<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiGrowthLog extends Model
{
    use HasFactory;

    protected $fillable = ['order_id', 'buyer_id', 'stage', 'notes', 'logged_at'];

    protected $casts = ['logged_at' => 'datetime'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }
}
