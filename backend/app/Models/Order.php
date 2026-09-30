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
        'cancellation_reason',
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

    protected $appends = ['payment_expires_at'];

    /**
     * When orders:expire-unpaid will fail this order if it is still unpaid,
     * so the buyer can be shown how long they have left to pay. Null for any
     * order that is no longer awaiting payment.
     *
     * Deliberately derived from status + created_at alone: this is appended to
     * every serialised order, and touching the payment relation here would fire
     * a query per row in the order tables.
     */
    public function getPaymentExpiresAtAttribute(): ?string
    {
        if ($this->status !== 'placed' || ! $this->created_at) {
            return null;
        }

        return $this->created_at
            ->copy()
            ->addMinutes((int) config('services.paymongo.unpaid_order_timeout_minutes', 30))
            ->toIso8601String();
    }

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

    /**
     * Appeals against this order's rejected earnings review. `latestDispute`
     * is what the seller's own dashboard reads, so their order card can say
     * "submitted, awaiting review" instead of offering the button again as
     * though nothing had happened.
     */
    public function disputes()
    {
        return $this->morphMany(Dispute::class, 'disputable');
    }

    public function latestDispute()
    {
        return $this->morphOne(Dispute::class, 'disputable')->latestOfMany();
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
