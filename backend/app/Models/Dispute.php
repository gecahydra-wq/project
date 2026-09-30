<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A seller's (or LGU's) appeal against a rejection. See the
 * create_disputes_table migration for why this is polymorphic, and
 * App\Support\DisputeResolution for what accepting one actually does.
 */
class Dispute extends Model
{
    public const OPEN = 'open';

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    protected $fillable = [
        'disputable_type',
        'disputable_id',
        'filed_by',
        'reason',
        'status',
        'resolution_note',
        'resolved_by',
        'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function disputable()
    {
        return $this->morphTo();
    }

    public function filedBy()
    {
        return $this->belongsTo(User::class, 'filed_by');
    }

    public function resolvedBy()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeOpen($query)
    {
        return $query->where('status', self::OPEN);
    }
}
