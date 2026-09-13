<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\PayMongoService;
use App\Support\OrderCancellation;
use Illuminate\Console\Command;

/**
 * Releases stock held by orders nobody paid for. OrderController::store
 * reserves stock the moment an order is placed; if the buyer closes PayMongo's
 * page without paying (and never lands on the cancel URL) that stock would
 * otherwise stay reserved forever. Scheduled in routes/console.php.
 *
 * A real PayMongo session is double-checked first: if it turns out to be paid
 * (webhook delayed), the order is left alone for the webhook / success
 * redirect to finish. If a payment still arrives after expiry, markOrderPaid
 * routes it to the refund queue (see App\Support\OrderCancellation).
 */
class ExpireUnpaidOrders extends Command
{
    protected $signature = 'orders:expire-unpaid {--minutes= : Override the payment window}';

    protected $description = 'Fail unpaid orders older than the payment window and release their reserved stock';

    public function handle(PayMongoService $payMongo): int
    {
        $minutes = (int) ($this->option('minutes') ?: config('services.paymongo.unpaid_order_timeout_minutes', 60));

        $stale = Order::with('payment')
            ->where('status', 'placed')
            ->where('created_at', '<=', now()->subMinutes($minutes))
            ->whereHas('payment', fn ($q) => $q->whereIn('status', OrderCancellation::UNPAID_PAYMENT_STATUSES))
            ->get();

        $expired = 0;
        foreach ($stale as $order) {
            if ($order->payment->status === 'checkout_created' && $payMongo->checkoutSessionIsPaid($order->payment->provider_reference) !== false) {
                // Paid, or PayMongo couldn't be reached -- never expire on a guess.
                continue;
            }

            OrderCancellation::expire($order);
            $expired++;
        }

        $this->info("Expired {$expired} unpaid order(s) older than {$minutes} minutes.");

        return self::SUCCESS;
    }
}
