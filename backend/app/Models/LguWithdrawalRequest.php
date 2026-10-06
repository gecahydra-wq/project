<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LguWithdrawalRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'municipality_id',
        'requested_by',
        'method',
        'bank_name',
        'account_name',
        'account_number',
        'amount',
        'status',
        'rejection_reason',
        'reviewed_at',
        'paid_at',
    ];

    protected $appends = ['dispute_deadline', 'can_dispute', 'has_open_dispute'];

    protected $casts = [
        'amount' => 'decimal:2',
        'reviewed_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function municipality()
    {
        return $this->belongsTo(Municipality::class);
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** See App\Support\WithdrawalRejection: a rejection holds the money until it is final. */
    public function getDisputeDeadlineAttribute(): ?string
    {
        return \App\Support\WithdrawalRejection::disputeDeadline($this)?->toIso8601String();
    }

    public function getCanDisputeAttribute(): bool
    {
        return \App\Support\WithdrawalRejection::canDispute($this);
    }

    public function getHasOpenDisputeAttribute(): bool
    {
        return \App\Support\WithdrawalRejection::hasOpenDispute($this);
    }
}
