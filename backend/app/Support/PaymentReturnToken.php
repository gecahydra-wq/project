<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Single-use token for the buyer's return trip from PayMongo's hosted page.
 *
 * Without this, /payment-success?order=ORD-123 is a plain URL: it sits in the
 * buyer's history and works forever. Re-opening it weeks later re-ran the
 * confirmation endpoint, which wrote another PaymentLog row against a long
 * settled payment and re-rendered "Payment Successful" for an order that had
 * already been delivered. Neither is a double charge -- markOrderPaid() is
 * idempotent -- but a replayable receipt and a polluted audit trail are both
 * wrong.
 *
 * issue() is called once when the checkout session is created and the token is
 * put in the success/cancel URLs; consume() burns it on first use.
 *
 * The cache store is the database (see config/cache.php), so a token survives
 * a container restart. The TTL only has to outlive a checkout attempt -- the
 * payment window itself is 30 minutes -- but is generous so that a buyer who
 * leaves the tab open overnight and then pays still gets a clean confirmation.
 */
class PaymentReturnToken
{
    /**
     * Long enough that a slow real-world checkout is never mistaken for a
     * replay, short enough that the cache table does not accumulate rows.
     */
    private const TTL_HOURS = 24;

    public static function issue(Order $order): string
    {
        $token = Str::random(40);

        Cache::put(self::key($order), $token, now()->addHours(self::TTL_HOURS));

        return $token;
    }

    /**
     * True only for the first caller presenting the matching token; every
     * later call for that order is false.
     *
     * A missing cache entry is also false. That covers both a replay and a
     * checkout minted before this token existed -- callers must therefore
     * treat false as "do not re-confirm", never as "do not capture". See
     * OrderController::markPaymentSuccess.
     */
    public static function consume(Order $order, ?string $token): bool
    {
        if (! is_string($token) || $token === '') {
            return false;
        }

        $stored = Cache::get(self::key($order));

        // hash_equals over == so a wrong token cannot be narrowed by timing.
        if (! is_string($stored) || ! hash_equals($stored, $token)) {
            return false;
        }

        Cache::forget(self::key($order));

        return true;
    }

    private static function key(Order $order): string
    {
        return "payment_return_token:{$order->id}";
    }
}
