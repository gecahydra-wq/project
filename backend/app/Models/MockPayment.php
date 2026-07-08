<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MockPayment extends Model
{
    use HasFactory;

    protected $table = 'payments';

    protected $fillable = ['order_id', 'amount', 'status', 'provider', 'provider_reference', 'checkout_url', 'released_at'];

    protected $casts = [
        'amount' => 'decimal:2',
        'released_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
