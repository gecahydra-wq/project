<?php

namespace App\Console\Commands;

use App\Support\WithdrawalRejection;
use Illuminate\Console\Command;

/**
 * Releases the money held by rejected withdrawals whose dispute window
 * (WithdrawalRejection::DISPUTE_DAYS) closed with no dispute filed, so a
 * seller or LGU who simply moves on is never left with funds stuck on hold.
 * Scheduled in routes/console.php.
 */
class FinalizeWithdrawalRejections extends Command
{
    protected $signature = 'withdrawals:finalize-rejections';

    protected $description = 'Make undisputed withdrawal rejections final after the dispute window and release their held amounts';

    public function handle(): int
    {
        $count = WithdrawalRejection::finalizeExpired();
        $this->info("Released {$count} rejected withdrawal(s) past the dispute window.");

        return self::SUCCESS;
    }
}
