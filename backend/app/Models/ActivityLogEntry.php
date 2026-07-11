<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per Activity Log event that has no existing table of its own --
 * written exclusively by App\Support\ActivityLog::record(). See that class
 * for the unified read path, which also merges in moderation_logs/
 * settlements/withdrawal_requests/lgu_withdrawal_requests.
 */
class ActivityLogEntry extends Model
{
    use HasFactory;

    protected $table = 'activity_logs';

    protected $fillable = [
        'actor_id',
        'actor_role',
        'action',
        'target_user_id',
        'municipality_id',
        'reference_type',
        'reference_number',
        'description',
    ];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function targetUser()
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function municipality()
    {
        return $this->belongsTo(Municipality::class);
    }
}
