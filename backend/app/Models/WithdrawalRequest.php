<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WithdrawalRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'seller_profile_id',
        'method',
        'account_name',
        'account_number',
        'amount',
        'platform_fee',
        'status',
        'rejection_reason',
        'reviewed_at',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'platform_fee' => 'decimal:2',
        'reviewed_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    protected $appends = ['net_amount'];

    /**
     * What the seller actually receives after the platform's payout fee --
     * see App\Support\CommissionCalculator::withdrawalFee(). Not stored
     * separately; always derived from the frozen amount/platform_fee pair.
     */
    public function getNetAmountAttribute(): float
    {
        return round((float) $this->amount - (float) $this->platform_fee, 2);
    }

    public function sellerProfile()
    {
        return $this->belongsTo(SellerProfile::class);
    }
}
