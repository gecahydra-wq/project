<?php

namespace App\Providers;

use App\Models\User;
use App\Observers\UserActivityObserver;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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

        // Login brute-force guard, keyed by email + IP so one person hammering
        // an account can't lock out everyone else on the same network.
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)
                ->by(strtolower((string) $request->input('email')).'|'.$request->ip())
                ->response(fn () => response()->json([
                    'message' => 'Too many login attempts. Please wait a minute and try again.',
                ], 429));
        });

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

        // Password reset links open the SPA's /reset-password page (not a
        // backend route), which posts the token back to /api/auth/reset-password.
        $resetUrl = fn ($notifiable, string $token) => rtrim(config('app.frontend_url'), '/').'/reset-password?'.http_build_query([
            'token' => $token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);
        ResetPassword::createUrlUsing($resetUrl);

        ResetPassword::toMailUsing(function ($notifiable, string $token) use ($resetUrl) {
            $expire = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

            return (new MailMessage)
                ->subject('Reset your AbaiMarket password')
                ->greeting("Hello {$notifiable->name},")
                ->line('We received a request to reset the password for your AbaiMarket account.')
                ->action('Reset Password', $resetUrl($notifiable, $token))
                ->line("This password reset link expires in {$expire} minutes.")
                ->line('If you signed up with Google, this lets you set a password too -- Continue with Google will keep working.')
                ->line('If you did not request a password reset, no further action is required.');
        });
    }
}
