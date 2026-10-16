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
        'reversed_at',
        'reversed_by',
        'reversal_reason',
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
        'reversed_at' => 'datetime',
    ];

    /**
     * Exclude settlements that were undone by a post-completion refund (see
     * App\Support\RefundRequests). Every balance and revenue figure is summed
     * from this model, so each of those aggregates applies this scope -- a
     * reversed settlement must stop counting toward money the seller/LGU/
     * platform holds, without the row itself being deleted.
     *
     * Scope is applied explicitly rather than as a global scope on purpose: the
     * order's own settlement() relation must still resolve a reversed row, so
     * Order Details can keep showing that the order was verified before it was
     * refunded. Only the money aggregates filter.
     */
    public function scopeNotReversed($query)
    {
        return $query->whereNull('reversed_at');
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

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

    public function reversedBy()
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }
}
