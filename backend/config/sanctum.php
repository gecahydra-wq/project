<?php

/*
 * Only the keys AbaiMarket changes from Sanctum's defaults -- the package merges
 * the rest in (see vendor/laravel/sanctum/config/sanctum.php).
 */
return [

    /*
    | Minutes before an API token expires; the SPA's 401 handler then sends the
    | user back to /login. Default 10080 = 7 days (SANCTUM_EXPIRATION).
    */
    'expiration' => (int) env('SANCTUM_EXPIRATION', 10080),

];
