<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Http;

class PayMongoService
{
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
                    'description' => 'FishMarket order '.$order->order_number,
                    'line_items' => [[
                        'currency' => 'PHP',
                            'amount' => (int) round($order->unit_price * 100),
                            'name' => $listing?->title ?? 'Fish fingerlings',
                            'quantity' => $order->quantity,
                        ]],
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
}
