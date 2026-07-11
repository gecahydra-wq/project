<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per account-level moderation action (suspend/reinstate), across
 * every role. Written exclusively by App\Support\AccountModeration -- never
 * created directly by a controller, so every suspend/reinstate in the app
 * is guaranteed to leave an audit trail.
 */
class ModerationLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'role',
        'moderator_id',
        'action',
        'reason',
        'notes',
        'resulting_status',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function moderator()
    {
        return $this->belongsTo(User::class, 'moderator_id');
    }
}
