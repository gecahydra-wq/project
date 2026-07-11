<?php

namespace App\Console\Commands;

use App\Models\Announcement;
use App\Support\AnnouncementNotifier;
use Illuminate\Console\Command;

/**
 * Fires the one-time notification fan-out (see App\Support\
 * AnnouncementNotifier) for announcements whose scheduled starts_at has now
 * arrived. Display of active announcements (Announcement::scopeActive)
 * never depends on this command having run -- only the push notification
 * for future-dated announcements does. Scheduled in routes/console.php.
 */
class PublishScheduledAnnouncements extends Command
{
    protected $signature = 'announcements:publish';

    protected $description = 'Send notifications for scheduled announcements whose start time has arrived';

    public function handle(): int
    {
        $due = Announcement::whereNull('notified_at')
            ->whereNotNull('starts_at')
            ->where('starts_at', '<=', now())
            ->get();

        foreach ($due as $announcement) {
            AnnouncementNotifier::notifyAudience($announcement);
            $this->info("Published announcement #{$announcement->id}: {$announcement->title}");
        }

        return self::SUCCESS;
    }
}
