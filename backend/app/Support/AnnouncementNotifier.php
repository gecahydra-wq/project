<?php

namespace App\Support;

use App\Models\Announcement;
use App\Models\AppNotification;
use App\Models\User;

/**
 * Fans an Announcement out to every Buyer/Seller/LGU Admin as a normal
 * AppNotification row -- the exact same model and firstOrCreate pattern
 * every other controller already uses (see e.g. LguController::
 * notifyLguOfCompletedDelivery), so nothing about the notification system
 * itself changes. Called once per announcement, either immediately on
 * creation (see AnnouncementController::store) or by the scheduled command
 * for future-dated announcements (see
 * App\Console\Commands\PublishScheduledAnnouncements).
 */
class AnnouncementNotifier
{
    public static function notifyAudience(Announcement $announcement): void
    {
        User::whereIn('role', ['buyer', 'seller', 'lgu_admin'])
            ->select('id')
            ->chunkById(200, function ($users) use ($announcement) {
                foreach ($users as $user) {
                    AppNotification::firstOrCreate([
                        'user_id' => $user->id,
                        'type' => "announcement:{$announcement->id}",
                    ], [
                        'title' => $announcement->title,
                        'body' => $announcement->body,
                    ]);
                }
            });

        $announcement->update(['notified_at' => now()]);
    }
}
