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
        'account_name',
        'account_number',
        'amount',
        'status',
        'rejection_reason',
        'reviewed_at',
        'paid_at',
    ];

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
}
