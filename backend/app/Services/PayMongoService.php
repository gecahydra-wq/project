<?php

namespace App\Services;

use App\Models\FingerlingListing;
use App\Models\Order;
use App\Support\PaymentReturnToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PayMongoService
{
    /**
     * PayMongo shows one image per line item on its checkout page; sending
     * the whole gallery would just be ignored payload.
     */
    private const MAX_CHECKOUT_IMAGES = 1;

    public function createCheckoutSession(Order $order): array
    {
        $secret = config('services.paymongo.secret_key');
        $frontend = rtrim(config('app.frontend_url', 'http://127.0.0.1:5173'), '/');
        // Single-use token, so the buyer's return URL stops working once it has
        // confirmed the payment instead of staying a replayable receipt in
        // their history. See App\Support\PaymentReturnToken.
        $query = http_build_query([
            'order' => $order->order_number,
            'listing_id' => $order->listing_id,
            'role' => 'buyer',
            't' => PaymentReturnToken::issue($order),
        ]);
        $successUrl = "{$frontend}/payment-success?{$query}";
        $cancelUrl = "{$frontend}/payment-cancelled?{$query}";

        if (! $secret) {
            return [
                'id' => 'demo_checkout_'.$order->id,
                'checkout_url' => $successUrl,
                'mode' => 'demo',
            ];
        }

        $listing = $order->listing()->first();

        $lineItem = [
            'currency' => 'PHP',
            'amount' => (int) round($order->unit_price * 100),
            'name' => $listing?->title ?? 'Fish fingerlings',
            'quantity' => $order->quantity,
        ];

        // PayMongo renders the first line-item image on its hosted checkout
        // page. Omit the key entirely rather than sending an empty array when
        // the listing has no photo, so the seller's own placeholder-free
        // listing just shows no image instead of a broken one.
        $images = self::listingImages($listing);
        if ($images) {
            $lineItem['images'] = $images;
        }

        $attributes = [
            'send_email_receipt' => false,
            'show_description' => true,
            'show_line_items' => true,
            'payment_method_types' => ['gcash', 'card', 'paymaya'],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'description' => 'AbaiMarket order '.$order->order_number,
            'line_items' => [$lineItem],
        ];

        // Identify the buyer to PayMongo. Without this the hosted page asks the
        // payer to type their own billing details, which the browser autofills
        // from whoever last paid on that device -- so a transaction could show a
        // name belonging to a different AbaiMarket account entirely. Sending it
        // means the dashboard shows the account that actually placed the order,
        // and the payer cannot overwrite it.
        if ($billing = self::billingFor($order)) {
            $attributes['billing'] = $billing;
        }

        $response = Http::withBasicAuth($secret, '')
            ->acceptJson()
            ->post('https://api.paymongo.com/v1/checkout_sessions', [
                'data' => ['attributes' => $attributes],
            ]);

        $response->throw();
        $payload = $response->json('data');

        return [
            'id' => $payload['id'] ?? null,
            'checkout_url' => $payload['attributes']['checkout_url'] ?? null,
            'mode' => 'paymongo',
        ];
    }

    /**
     * The order's buyer as PayMongo's billing object.
     *
     * Empty values are dropped rather than sent blank: PayMongo rejects an
     * empty string where it will accept the key being absent, and `phone` is
     * nullable on users. Returns null when there is nothing worth sending, so
     * the caller omits the key entirely.
     */
    private static function billingFor(Order $order): ?array
    {
        $buyer = $order->loadMissing('buyer')->buyer;

        if (! $buyer) {
            return null;
        }

        $billing = array_filter([
            'name' => trim((string) $buyer->name) ?: null,
            'email' => trim((string) $buyer->email) ?: null,
            'phone' => trim((string) $buyer->phone) ?: null,
        ], fn ($value) => $value !== null);

        return $billing ?: null;
    }

    /**
     * Checks the Paymongo-Signature header ("t=<timestamp>,te=<test sig>,li=<live sig>")
     * against HMAC-SHA256("<timestamp>.<raw body>", webhook secret), as PayMongo
     * documents. Without this anyone who knows the webhook URL could post a fake
     * "payment paid" event.
     */
    public function webhookSignatureIsValid(string $rawBody, ?string $header): bool
    {
        $secret = (string) config('services.paymongo.webhook_secret');
        if ($secret === '' || ! $header) {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $pair) {
            [$key, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
            $parts[$key] = $value;
        }

        if (empty($parts['t'])) {
            return false;
        }

        $expected = hash_hmac('sha256', $parts['t'].'.'.$rawBody, $secret);

        foreach (['te', 'li'] as $key) {
            if (! empty($parts[$key]) && hash_equals($expected, $parts[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Asks PayMongo whether a checkout session has actually been paid, so the
     * buyer's success redirect (which anyone can call) is never trusted on its
     * own. Returns null when it can't tell (no key, network/API error).
     */
    public function checkoutSessionIsPaid(?string $checkoutSessionId): ?bool
    {
        $secret = config('services.paymongo.secret_key');
        if (! $secret || ! $checkoutSessionId || Str::startsWith($checkoutSessionId, 'demo_')) {
            return null;
        }

        try {
            $response = Http::withBasicAuth($secret, '')->acceptJson()->timeout(15)
                ->get("https://api.paymongo.com/v1/checkout_sessions/{$checkoutSessionId}");
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $attributes = $response->json('data.attributes', []);
        $paidPayment = collect($attributes['payments'] ?? [])
            ->contains(fn ($payment) => data_get($payment, 'attributes.status') === 'paid');

        return $paidPayment || data_get($attributes, 'payment_intent.attributes.status') === 'succeeded';
    }

    /**
     * Kills a hosted checkout session so its checkout.paymongo.com URL stops
     * accepting payments.
     *
     * The URL lives in the buyer's history and in any tab they left open, and
     * PayMongo keeps serving it until the session is expired -- nothing on our
     * side can take it down. Two things go wrong without this:
     *
     *  - a settled order's checkout page is still reachable after the buyer has
     *    paid and been redirected home, and
     *  - resuming payment mints a NEW session while the OLD one stays live, so
     *    a buyer holding both URLs could pay for the same order twice (the
     *    second payment lands as refund_pending and has to be refunded).
     *
     * Best effort by design: returns false rather than throwing, because every
     * caller is finishing something more important (capturing a payment,
     * cancelling an order) and must not fail if PayMongo is unreachable or
     * refuses. PayMongo may decline to expire a session it has already
     * collected on -- that is its call, not ours -- so the outcome is logged
     * rather than asserted.
     */
    public function expireCheckoutSession(?string $checkoutSessionId): bool
    {
        $secret = config('services.paymongo.secret_key');
        if (! $secret || ! $checkoutSessionId || Str::startsWith($checkoutSessionId, 'demo_')) {
            return false;
        }

        try {
            $response = Http::withBasicAuth($secret, '')->acceptJson()->timeout(15)
                ->post("https://api.paymongo.com/v1/checkout_sessions/{$checkoutSessionId}/expire");
        } catch (\Throwable $e) {
            Log::warning('PayMongo checkout session could not be expired.', [
                'checkout_session_id' => $checkoutSessionId,
                'reason' => $e->getMessage(),
            ]);

            return false;
        }

        if (! $response->successful()) {
            Log::warning('PayMongo refused to expire a checkout session.', [
                'checkout_session_id' => $checkoutSessionId,
                'status' => $response->status(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * The listing's photos, as public HTTPS URLs PayMongo's checkout page can
     * actually load.
     *
     * Videos are skipped (PayMongo only takes images) and the seller's own
     * media order is respected, so the lead photo is the same one the
     * marketplace card shows.
     *
     * Every URL is re-based onto the public origin (see
     * services.paymongo.asset_base_url) because listing_media.url stores
     * whatever origin was current at upload time -- a photo uploaded against a
     * local APP_URL keeps "http://127.0.0.1:8000" in the database forever, and
     * would still be sent as such long after the app is deployed.
     *
     * Anything still not HTTPS after that is dropped rather than sent. It
     * could never render anyway: PayMongo's page is HTTPS, so browsers block
     * plain-HTTP images as mixed content, and a localhost host resolves to the
     * buyer's own machine. Sending one produces a broken-image box, so no
     * image is the better failure.
     */
    private static function listingImages(?FingerlingListing $listing): array
    {
        if (! $listing) {
            return [];
        }

        return $listing->media
            ->where('type', 'photo')
            ->pluck('url')
            ->map(fn ($url) => is_string($url) ? self::publicAssetUrl($url) : null)
            ->filter(fn ($url) => $url && Str::startsWith($url, 'https://'))
            ->take(self::MAX_CHECKOUT_IMAGES)
            ->values()
            ->all();
    }

    /**
     * Swap this app's own stored origin for the configured public one, leaving
     * the path intact. URLs already hosted elsewhere (an external CDN) are
     * passed through untouched.
     */
    private static function publicAssetUrl(string $url): string
    {
        $publicBase = rtrim((string) (config('services.paymongo.asset_base_url') ?: config('app.url')), '/');
        $appBase = rtrim((string) config('app.url'), '/');

        if ($appBase === '' || ! Str::startsWith($url, $appBase)) {
            return $url;
        }

        return $publicBase.Str::after($url, $appBase);
    }
}
