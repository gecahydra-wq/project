<?php

namespace App\Console\Commands;

use App\Support\RefundRequests;
use Illuminate\Console\Command;

/**
 * Closes the post-completion refund window on every completed order that has
 * passed its deadline without a request, so the audit trail says when the
 * chance lapsed instead of the window simply going quiet.
 *
 * This command decides nothing and deletes nothing. A refund request that is
 * still pending when its window closes stays pending until a Super Admin
 * approves or denies it -- eligibility is derived from orders.completed_at, so
 * there is no state to expire. The only thing this writes is one
 * 'refund_request_window_expired' activity-log row per order, and it skips an
 * order that already has one, so running it twice changes nothing.
 */
class ExpireRefundWindows extends Command
{
    protected $signature = 'orders:expire-refund-windows';

    protected $description = 'Record orders whose post-completion refund window closed with no request';

    public function handle(): int
    {
        $closed = RefundRequests::closeExpiredWindows();

        $this->info("Recorded {$closed} order(s) whose refund window closed with no request.");

        return self::SUCCESS;
    }
}
