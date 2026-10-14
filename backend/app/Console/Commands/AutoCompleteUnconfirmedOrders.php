<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\OrderController;
use App\Models\Order;
use App\Support\SystemMessenger;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AutoCompleteUnconfirmedOrders extends Command
{
    protected $signature = 'orders:auto-complete-unconfirmed';

    protected $description = 'Mark paid orders received when the buyer confirmation window expires';

    public function handle(OrderController $orders): int
    {
        $minutes = max(1, (int) config('orders.auto_complete_after_minutes'));
        $cutoff = Carbon::now()->subMinutes($minutes);
        $ids = Order::query()
            ->where('status', 'in_transit')
            ->where('in_transit_at', '<=', $cutoff)
            ->whereHas('payment', fn ($query) => $query->whereIn('status', ['paid_held', 'released']))
            ->pluck('id');
        // The actor on the activity log (and nothing else) -- the messages
        // themselves go out from the AbaiMarket system account via
        // OrderController::completeDelivery.
        $systemAccount = SystemMessenger::user();

        $completed = 0;
        foreach ($ids as $id) {
            $didComplete = DB::transaction(function () use ($id, $cutoff, $orders, $systemAccount) {
                $order = Order::with('payment')->lockForUpdate()->find($id);
                if (! $order || $order->status !== 'in_transit' || ! $order->in_transit_at || $order->in_transit_at->gt($cutoff)) {
                    return false;
                }

                $orders->completeDelivery($order, $systemAccount, automatic: true);

                return true;
            });

            $completed += $didComplete ? 1 : 0;
        }

        $this->info("Automatically marked {$completed} order(s) received after {$minutes} minute(s) without buyer confirmation.");

        return self::SUCCESS;
    }
}
