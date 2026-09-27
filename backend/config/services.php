<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'paymongo' => [
        'public_key' => env('PAYMONGO_PUBLIC_KEY'),
        'secret_key' => env('PAYMONGO_SECRET_KEY'),
        'webhook_secret' => env('PAYMONGO_WEBHOOK_SECRET'),

        // Refunds are normally issued by hand in the PayMongo dashboard and
        // then recorded here (OrderCancellation::markRefunded). On TEST keys
        // there are no live funds to send back and no admin worth chasing, so
        // a queued refund completes itself and the buyer sees the whole
        // cancel-to-refunded path without anyone leaving the app.
        //
        // Defaults on for sk_test_ only. Never inferred for live keys: a
        // 'refunded' row there would claim money moved when none did. It is
        // also off when no key is set, so the test suite keeps exercising the
        // real manual queue.
        'auto_refund' => (bool) env(
            'PAYMONGO_AUTO_REFUND',
            str_starts_with((string) env('PAYMONGO_SECRET_KEY'), 'sk_test_')
        ),

        // Minutes an order may stay unpaid before orders:expire-unpaid fails
        // it and releases its reserved stock. The clock starts when the order
        // is placed, and abandoning a PayMongo checkout no longer shortens it
        // -- the buyer may return and pay until the window closes. Stock stays
        // reserved for that whole window, so raising this holds a seller's
        // stock out of circulation for longer.
        //
        // The scheduler ticks every five minutes (routes/console.php), so the
        // effective window is this value plus up to 5 minutes.
        'unpaid_order_timeout_minutes' => (int) env('ORDER_PAYMENT_TIMEOUT_MINUTES', 30),

        // Public HTTPS origin for listing photos shown on PayMongo's hosted
        // checkout page, e.g. https://your-app.ngrok-free.app or your deployed
        // domain. Optional -- when unset, APP_URL is used.
        //
        // This exists because listing_media.url stores the absolute URL that
        // was current when the photo was uploaded (see App\Support\ImageUploader),
        // so a local upload keeps a localhost host forever. PayMongo's page is
        // HTTPS and fetches images from the public internet, so a localhost or
        // plain-HTTP URL renders as a broken image. Setting this rewrites the
        // origin at checkout time without re-uploading anything.
        'asset_base_url' => env('PAYMONGO_ASSET_BASE_URL'),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

];
