<?php

namespace App\Console\Commands;

use App\Models\SellerProfile;
use App\Support\OrderCancellation;
use Illuminate\Console\Command;

/**
 * Backstop for AccountModeration::suspendSeller, which cancels a suspended
 * seller's unfinished orders the moment they are suspended. This catches any
 * that slipped past it -- sellers suspended before that rule existed, or an
 * order paid in the instant around the suspension -- so no buyer is left
 * waiting on a seller who cannot fulfil. Scheduled in routes/console.php.
 */
class CancelSuspendedSellerOrders extends Command
{
    protected $signature = 'orders:cancel-suspended-seller-orders';

    protected $description = 'Cancel and refund unfinished orders of suspended sellers';

    public function handle(): int
    {
        $sellers = SellerProfile::where('status', 'suspended')
            ->whereHas('orders', fn ($q) => $q->whereIn('status', OrderCancellation::OPEN_ORDER_STATUSES))
            ->get();

        $cancelled = 0;
        foreach ($sellers as $seller) {
            $cancelled += OrderCancellation::cancelOpenOrdersOfSuspendedSeller($seller);
        }

        $this->info("Cancelled {$cancelled} order(s) of suspended sellers.");

        return self::SUCCESS;
    }
}
