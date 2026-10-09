<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use Illuminate\Http\Request;

/**
 * Notification History, shared by every role. Each role's own
 * `notifications` endpoint lists only UNREAD notifications (the bell and the
 * Mark Read buttons depend on that), so a notification used to vanish for good
 * once it was read. This lists the ones already read, newest first.
 */
class NotificationController extends Controller
{
    /** How many read notifications the history keeps on screen. */
    private const HISTORY_LIMIT = 200;

    public function history(Request $request)
    {
        return response()->json(
            AppNotification::where('user_id', $request->user()->id)
                ->whereNotNull('read_at')
                ->latest()
                ->limit(self::HISTORY_LIMIT)
                ->get()
        );
    }
}
