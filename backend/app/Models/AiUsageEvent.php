<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Aggregate-only AI Assistant telemetry -- see the ai_usage_events migration.
 * Intentionally holds no message/response content; only role, intent category,
 * whether a fallback was served, and how long the answer took.
 */
class AiUsageEvent extends Model
{
    protected $fillable = ['user_id', 'role', 'category', 'was_fallback', 'response_time_ms'];

    protected $casts = [
        'was_fallback' => 'boolean',
        'response_time_ms' => 'integer',
    ];
}
