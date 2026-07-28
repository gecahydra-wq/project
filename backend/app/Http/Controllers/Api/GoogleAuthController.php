<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BuyerProfile;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * Google OAuth sign-in (via Laravel Socialite). Both legs are full-page
 * browser redirects, not XHR: redirect() sends the user to Google's consent
 * screen, and callback() receives them back, upserts a Buyer account matched
 * by email, and hands the SPA a Sanctum token through a frontend callback URL.
 * Google accounts arrive already email-verified, so they skip our own
 * email-ownership step. Suspended/disabled accounts are bounced to /login with
 * a reason, mirroring AuthController::login.
 */
class GoogleAuthController extends Controller
{
    /**
     * Full-page redirect into Google's consent screen. 'stateless' because
     * this is an API-only backend behind an SPA -- there's no guarantee the
     * browser's session cookie survives the round trip to Google and back
     * (different origin, and the SPA/API can run on different ports in dev).
     *
     * prompt=select_account forces Google's account chooser every time,
     * instead of silently reusing whichever Google account last authorized
     * this app in the browser -- without it, a user who is (or was) signed
     * into a Google account with no way to pick a different one, since
     * Google otherwise treats a previously-consented app as a one-click
     * "log back in with the same account" flow. We deliberately do NOT use
     * prompt=consent, which would additionally force the OAuth permissions
     * screen on every single login -- that's not needed here since we only
     * ever request the default email/profile scopes.
     */
    public function redirect()
    {
        return Socialite::driver('google')->stateless()
            ->with(['prompt' => 'select_account'])
            ->redirect();
    }

    /**
     * Google sends the browser back here after consent. We never hand a
     * Sanctum token directly to the SPA via JSON (there's no XHR call for
     * Google to POST back to) -- instead we redirect the browser to a
     * frontend callback route with the plain-text token in the query
     * string, and that page exchanges it for the user via GET /auth/me,
     * exactly like a normal token-based login.
     */
    public function callback()
    {
        $frontend = rtrim(config('app.frontend_url'), '/');

        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (Throwable $e) {
            return redirect($frontend.'/login?google_error=1');
        }

        $email = $googleUser->getEmail();
        if (! $email) {
            return redirect($frontend.'/login?google_error=1');
        }

        // Match by email first -- this is what prevents a second, duplicate
        // account from being created for someone who already registered the
        // normal way (or signed in with Google before) using this address.
        $user = User::where('email', $email)->first();

        if ($user) {
            if (! $user->google_id) {
                $user->update(['google_id' => $googleUser->getId()]);
            }
        } else {
            $user = User::create([
                'name' => $googleUser->getName() ?: $googleUser->getNickname() ?: 'AbaiMarket Buyer',
                'email' => $email,
                // Unusable random password -- this account only ever signs
                // in through Google, but the column is non-nullable and
                // every other auth code path (Hash::check in login()) must
                // keep working unmodified for it.
                'password' => Hash::make(Str::random(40)),
                'google_id' => $googleUser->getId(),
                'role' => 'buyer',
                'status' => 'active',
            ]);

            BuyerProfile::create([
                'user_id' => $user->id,
                'municipality_id' => null,
            ]);
        }

        // Google only ever surfaces an account to us via OAuth if that
        // account's email is already verified on Google's side, so there is
        // nothing meaningful left for our own email-ownership check to add.
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        if ($user->role === 'seller') {
            $sellerProfile = SellerProfile::where('user_id', $user->id)->first();
            if ($sellerProfile?->status === 'suspended') {
                return redirect($frontend.'/login?google_error=1&reason=suspended');
            }
        }

        if ($user->role === 'lgu_admin' && $user->status === 'disabled') {
            return redirect($frontend.'/login?google_error=1&reason=disabled');
        }

        $token = $user->createToken('fishmarket')->plainTextToken;

        return redirect($frontend.'/auth/google/callback?token='.urlencode($token));
    }
}
