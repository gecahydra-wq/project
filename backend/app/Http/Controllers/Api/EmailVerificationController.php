<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\SafeMailer;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Http\Request;

/**
 * Email-ownership verification -- the gate between registration and first
 * login (see AuthController). verify() consumes the signed link from the
 * verification email; resend() re-issues it. Both are intentionally
 * unauthenticated (an unverified user has no token yet) and both render a
 * branded HTML result page rather than JSON, since a human is clicking a link
 * in their inbox.
 */
class EmailVerificationController extends Controller
{
    /**
     * Handles the signed link from the verification email notification.
     * Deliberately NOT behind auth:sanctum -- the person clicking the link
     * may be on a different device/browser than the one that registered, so
     * the signed URL + id/hash pair are themselves the proof of identity
     * (mirrors Laravel's own EmailVerificationRequest::authorize(), just
     * without requiring an authenticated session/token to check it against).
     */
    public function verify(Request $request, int $id, string $hash)
    {
        $user = User::find($id);

        if (! $user || ! hash_equals(sha1($user->getEmailForVerification()), (string) $hash)) {
            return $this->result(
                'cancelled',
                'Invalid Link',
                'This verification link is invalid.',
                "The link you used doesn't match any AbaiMarket account. Please register again or request a new verification email from the login page."
            );
        }

        if (! $request->hasValidSignature()) {
            return $this->result(
                'cancelled',
                'Link Expired',
                'This verification link has expired.',
                'Verification links expire after 60 minutes for security. Request a new one from the login page.',
                $user->email
            );
        }

        if ($user->hasVerifiedEmail()) {
            return $this->result(
                'success',
                'Already Verified',
                'Your email is already verified.',
                'Your AbaiMarket account is already active -- you can log in now.'
            );
        }

        $user->markEmailAsVerified();

        return $this->result(
            'success',
            'Email Verified',
            'Your email is verified.',
            'Your AbaiMarket account is now active. You can log in now.'
        );
    }

    /**
     * Re-sends the verification email. Unauthenticated by design (an
     * unverified user has no Sanctum token yet -- see AuthController::login)
     * and always responds identically whether or not the address is
     * registered/already verified, so it can't be used to enumerate
     * accounts. Rate limited via the 'throttle' route middleware.
     */
    public function resend(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if ($user && ! $user->hasVerifiedEmail()) {
            SafeMailer::notify($user, new VerifyEmail);
        }

        return response()->json([
            'message' => 'If that email is registered and unverified, a new verification link has been sent.',
        ]);
    }

    /**
     * Render the shared branded return page (reused from the PayMongo return
     * flow) with a Go-to-Login call to action -- so every verify() outcome,
     * success or failure, lands the user somewhere useful.
     */
    private function result(string $status, string $title, string $headline, string $message, ?string $email = null)
    {
        $frontend = rtrim(config('app.frontend_url'), '/');
        $loginUrl = $frontend.'/login'.($email ? '?resend_email='.urlencode($email) : '');

        return response()->view('paymongo-return', [
            'status' => $status,
            'title' => $title,
            'headline' => $headline,
            'message' => $message,
            'primary_label' => 'Go to Login',
            'primary_url' => $loginUrl,
            'secondary_label' => 'Back to AbaiMarket',
            'secondary_url' => $frontend.'/',
        ]);
    }
}
