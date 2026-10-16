<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | The SPA is served from its own origin and calls this API with a Bearer
    | token, so the only browser origins allowed to read responses are the
    | configured frontend and the local dev servers. The framework default of
    | '*' would let any website read a signed-in user's API responses.
    |
    | FRONTEND_URL is the single source of the SPA's origin (also used for the
    | Google sign-in callback and password reset links), so production needs no
    | change beyond the variable it already sets.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_unique(array_filter([
        env('FRONTEND_URL'),
        // Vite is pinned to 127.0.0.1:5173, but either spelling can end up in
        // the Origin header depending on how the browser resolves "localhost".
        'http://127.0.0.1:5173',
        'http://localhost:5173',
    ]))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
