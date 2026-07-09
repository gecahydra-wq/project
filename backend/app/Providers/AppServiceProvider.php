<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Keep relation keys (e.g. "sellerProfile") camelCase in JSON responses,
        // matching how the frontend already reads them everywhere.
        Model::$snakeAttributes = false;
    }
}
