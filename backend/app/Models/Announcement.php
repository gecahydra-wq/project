<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'body',
        'category',
        'created_by',
        'starts_at',
        'expires_at',
        'notified_at',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'notified_at' => 'datetime',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Currently within its display window -- independent of whether the
     * notification fan-out (notified_at) has happened yet, so display never
     * depends on the scheduler having run (see
     * App\Console\Commands\PublishScheduledAnnouncements).
     */
    public function scopeActive($query)
    {
        $now = now();

        return $query->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now));
    }
}
