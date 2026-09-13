<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AuthValidation;
use App\Support\SafeMailer;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * "Forgot password" for every role, built on Laravel's own password broker
 * (hashed single-use tokens in password_reset_tokens, 60-minute expiry and
 * per-address throttle from config/auth.php). Both endpoints are
 * unauthenticated -- the person resetting can't log in, which is the point.
 *
 * Works the same for Google-registered accounts: they were created with an
 * unusable random password (see GoogleAuthController), so a reset simply gives
 * them a real one. Google sign-in keeps working afterwards because it matches
 * accounts by email, not by password.
 */
class PasswordResetController extends Controller
{
    /**
     * Email a reset link. Always answers with the same message whether or not
     * the address is registered (or was throttled), so it can't be used to
     * discover which emails have accounts -- same rule as email/resend.
     */
    public function sendLink(Request $request)
    {
        $data = $request->validate([
            'email' => AuthValidation::emailRules(),
        ], AuthValidation::messages());

        // The callback replaces the broker's direct $user->notify() so a broken
        // mail transport is logged instead of surfacing as a 500 (SafeMailer).
        Password::sendResetLink(['email' => $data['email']], function (User $user, string $token) {
            SafeMailer::notify($user, new ResetPassword($token));
        });

        return response()->json([
            'message' => 'If that email is registered, a password reset link has been sent. Check your inbox and spam folder.',
        ]);
    }

    /**
     * Set a new password from the emailed token. The new password follows the
     * same StrongPassword policy as registration. On success every existing
     * Sanctum token is revoked (anyone signed in with the old password is
     * logged out), and an unverified account is marked verified -- opening
     * the emailed link proves ownership of the address exactly like the
     * verification link does.
     */
    public function reset(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => AuthValidation::emailRules(),
            'password' => [...AuthValidation::passwordRules(), 'confirmed'],
        ], AuthValidation::messages() + [
            'password.confirmed' => 'The passwords do not match.',
        ]);

        $status = Password::reset($data, function (User $user, string $password) {
            // The 'hashed' cast on User hashes the plain value on save.
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ]);

            if (! $user->hasVerifiedEmail()) {
                $user->email_verified_at = now();
            }

            $user->save();
            $user->tokens()->delete();
        });

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'This password reset link is invalid or has expired. Please request a new one.',
            ], 422);
        }

        return response()->json([
            'message' => 'Your password has been reset. You can now log in with your new password.',
        ]);
    }
}
