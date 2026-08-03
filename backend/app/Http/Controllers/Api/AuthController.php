<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BuyerProfile;
use App\Models\SellerProfile;
use App\Models\User;
use App\Support\AuthValidation;
use App\Support\SafeMailer;
use App\Support\SellerApproval;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Email/password authentication for the marketplace. Only Buyers and Sellers
 * can self-register here; LGU Admins are provisioned by a Super Admin and the
 * Super Admin is seeded, so neither is created through this endpoint.
 *
 * Auth flow: register -> verify email -> login. A Sanctum bearer token is
 * issued ONLY at successful login (never at registration), which is what keeps
 * unverified accounts from accessing the app. Google sign-in is handled
 * separately by GoogleAuthController.
 */
class AuthController extends Controller
{
    /**
     * Register a Buyer or Seller. Also provisions the matching profile row --
     * a BuyerProfile, or a SellerProfile that starts in the registration
     * approval queue and must be approved by an LGU Admin (or the Super Admin)
     * before it can list (App\Support\SellerApproval). Sends the
     * email-verification link but deliberately returns NO token, so the new
     * account can't be used until verified.
     *
     * @throws \Illuminate\Validation\ValidationException  On invalid input or a
     *         duplicate email. municipality_id is required for sellers only.
     */
    public function register(Request $request)
    {
        // Email and password rules come from the shared AuthValidation helper so
        // registration validates identically to login, change-password, and
        // Super-Admin LGU-admin creation.
        $data = $request->validate([
            'name' => ['required', 'string'],
            'email' => AuthValidation::emailRules(unique: true),
            'password' => AuthValidation::passwordRules(),
            'role' => ['required', Rule::in(['buyer', 'seller'])],
            'municipality_id' => [Rule::requiredIf(fn () => $request->input('role') === 'seller'), 'nullable', 'exists:municipalities,id'],
            'phone' => ['nullable', 'string'],
        ], AuthValidation::messages());

        $user = User::create($data);

        if ($user->role === 'buyer') {
            BuyerProfile::create([
                'user_id' => $user->id,
                'municipality_id' => $user->municipality_id,
            ]);
        }

        if ($user->role === 'seller') {
            SellerProfile::create([
                'user_id' => $user->id,
                'municipality_id' => $user->municipality_id,
                'hatchery_name' => $user->name,
                'description' => 'New hatchery profile pending LGU verification.',
                'status' => 'pending',
                // Enters the registration review queue. Listing creation stays
                // blocked until an LGU Admin (or the Super Admin) approves --
                // see App\Support\SellerApproval.
                'approval_status' => SellerApproval::PENDING,
            ]);
        }

        // No token yet -- a freshly registered account is unverified, and
        // unverified accounts must not be able to log in (see login()
        // below). The user still gets their record back so the frontend can
        // show a "check your email" screen addressed to them by name/email.
        // Sent via SafeMailer so a broken mail transport never turns a
        // successful registration into a 500 error.
        SafeMailer::notify($user, new VerifyEmail);

        return response()->json([
            'message' => 'Registration successful. Please check your email to verify your account before logging in.',
            'user' => $user,
        ], 201);
    }

    /**
     * Authenticate and issue a Sanctum token. Enforces three gates in order:
     * valid credentials, a verified email, and account standing -- a
     * LGU-suspended seller or a Super-Admin-disabled LGU admin is refused with
     * a 403 explaining who to contact. Note a *suspended buyer* is intentionally
     * allowed to log in (they're only blocked from transacting; see the guards
     * in OrderController/MessageController/ReviewController).
     *
     * @return \Illuminate\Http\JsonResponse  422 on bad credentials, 403 when
     *         unverified/suspended/disabled, otherwise the user + token.
     */
    public function login(Request $request)
    {
        // Trim accidental leading/trailing spaces the user may have typed or
        // pasted around their password (the global TrimStrings middleware skips
        // password fields). A valid stored password never contains whitespace,
        // so this only ever helps -- and any *internal* space is then rejected
        // below with a friendly message instead of a confusing "invalid".
        if (is_string($request->input('password'))) {
            $request->merge(['password' => trim($request->input('password'))]);
        }

        // Same shared email rules as registration (trimmed, valid, no spaces);
        // password is only checked for presence and whitespace here, never for
        // strength, so existing accounts can always sign in.
        $data = $request->validate([
            'email' => AuthValidation::emailRules(),
            'password' => AuthValidation::loginPasswordRules(),
        ], AuthValidation::messages());

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 422);
        }

        if (! $user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Please verify your email address before logging in.',
                'unverified' => true,
                'email' => $user->email,
            ], 403);
        }

        if ($user->role === 'seller') {
            $sellerProfile = SellerProfile::where('user_id', $user->id)->first();
            if ($sellerProfile?->status === 'suspended') {
                return response()->json(['message' => 'This seller account has been suspended by the LGU. Contact your local government unit for assistance.'], 403);
            }
        }

        if ($user->role === 'lgu_admin' && $user->status === 'disabled') {
            return response()->json(['message' => 'This LGU admin account has been disabled by the Super Admin.'], 403);
        }

        return response()->json([
            'user' => $user,
            'token' => $user->createToken('fishmarket')->plainTextToken,
        ]);
    }

    /** Return the currently authenticated user (used to rehydrate the SPA session). */
    public function me(Request $request)
    {
        return $request->user();
    }

    /** Revoke only the token used for THIS request, leaving the user's other sessions intact. */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    /**
     * Change the signed-in user's password after re-confirming the current one.
     * Available to every role (see the `auth` route group).
     *
     * @throws \Illuminate\Validation\ValidationException  When the new password
     *         fails validation. Returns 422 if the current password is wrong.
     */
    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required'],
            // New password: same shared policy as registration.
            'password' => AuthValidation::passwordRules(),
        ], AuthValidation::messages());

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        $user->update(['password' => $data['password']]);

        return response()->json(['message' => 'Password updated.']);
    }
}
