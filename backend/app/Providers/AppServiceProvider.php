<?php

namespace App\Providers;

use App\Models\User;
use App\Observers\UserActivityObserver;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
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

        // Global Activity Log: logs "user_registered" purely by observing
        // User::created -- see App\Observers\UserActivityObserver. Zero
        // changes to AuthController/GoogleAuthController.
        User::observe(UserActivityObserver::class);

        // AbaiMarket-branded copy for Laravel's built-in verification email --
        // the notification class, signed-URL generation, and expiry are all
        // still the framework defaults; only the message text changes here.
        VerifyEmail::toMailUsing(function ($notifiable, string $url) {
            return (new MailMessage)
                ->subject('Verify your AbaiMarket account')
                ->greeting("Hello {$notifiable->name},")
                ->line('Thanks for joining AbaiMarket, the LGU-supervised fisheries marketplace. Please verify your email address to activate your account.')
                ->action('Verify Email Address', $url)
                ->line('This verification link expires in 60 minutes.')
                ->line('If you did not create a AbaiMarket account, no further action is required.');
        });
    }
}
