<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An immutable record of how one completed order's payment was split
 * between the seller, the seller's LGU, and the platform, at the moment the
 * LGU approved it (see LguController::approveEarnings). The marketplace
 * commission split is fixed (see App\Support\CommissionCalculator), but the
 * percentages are still snapshotted onto every row so historical settlements
 * remain self-describing even if the fixed split is ever changed in code.
 */
class Settlement extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'payment_id',
        'seller_profile_id',
        'municipality_id',
        'approved_by',
        'gross_amount',
        'seller_share',
        'lgu_share',
        'platform_share',
        'seller_percent',
        'lgu_percent',
        'platform_percent',
        'status',
        'settled_at',
    ];

    protected $casts = [
        'gross_amount' => 'decimal:2',
        'seller_share' => 'decimal:2',
        'lgu_share' => 'decimal:2',
        'platform_share' => 'decimal:2',
        'seller_percent' => 'decimal:2',
        'lgu_percent' => 'decimal:2',
        'platform_percent' => 'decimal:2',
        'settled_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function payment()
    {
        return $this->belongsTo(MockPayment::class, 'payment_id');
    }

    public function sellerProfile()
    {
        return $this->belongsTo(SellerProfile::class);
    }

    public function municipality()
    {
        return $this->belongsTo(Municipality::class);
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
