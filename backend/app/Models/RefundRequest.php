<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A buyer's post-completion refund request and the Super Admin's decision on
 * it (see App\Support\RefundRequests for every rule that governs it).
 *
 * Deliberately NOT the same thing as a cancellation refund. A cancellation
 * refund is queued by the app itself when an order is cancelled -- nobody
 * decides it. This row exists only because a person asked and a person has to
 * answer, which is why it carries a decider and a denial reason at all.
 */
class RefundRequest extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const DENIED = 'denied';

    protected $fillable = [
        'order_id',
        'payment_id',
        'buyer_id',
        'reason',
        'status',
        'decided_by',
        'decided_at',
        'denial_reason',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function payment()
    {
        return $this->belongsTo(MockPayment::class, 'payment_id');
    }

    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function decidedBy()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function media()
    {
        return $this->hasMany(RefundRequestMedia::class)->orderBy('position');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }
}
