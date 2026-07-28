<?php

namespace App\Services;

use App\Models\FingerlingListing;
use App\Models\Order;
use Illuminate\Support\Facades\Http;
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
        $query = http_build_query([
            'order' => $order->order_number,
            'listing_id' => $order->listing_id,
            'role' => 'buyer',
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

        $response = Http::withBasicAuth($secret, '')
            ->acceptJson()
            ->post('https://api.paymongo.com/v1/checkout_sessions', [
                'data' => [
                    'attributes' => [
                        'send_email_receipt' => false,
                        'show_description' => true,
                        'show_line_items' => true,
                        'payment_method_types' => ['gcash', 'card', 'paymaya'],
                        'success_url' => $successUrl,
                        'cancel_url' => $cancelUrl,
                        'description' => 'AbaiMarket order '.$order->order_number,
                        'line_items' => [$lineItem],
                    ],
                ],
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
