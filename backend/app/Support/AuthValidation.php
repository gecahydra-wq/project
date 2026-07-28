<?php

namespace App\Support;

use App\Rules\StrongPassword;
use Illuminate\Validation\Rule;

/**
 * The single source of truth for how AbaiMarket validates account credentials.
 * Every authentication and account-creation flow -- buyer/seller registration,
 * login, change password, and Super-Admin provisioning of LGU admins -- pulls
 * its email and password rules (and messages) from here, so validation is
 * byte-for-byte identical everywhere and can never drift between endpoints.
 *
 * Leading/trailing whitespace on the email is already removed globally by
 * Laravel's TrimStrings middleware before these rules run; the not_regex guard
 * then rejects any *internal* space that survives the trim. Passwords are in
 * TrimStrings' except-list (never auto-trimmed), so flows that need trimming do
 * it explicitly -- see AuthController::login().
 *
 * The frontend mirrors these exact checks and messages (validateEmail /
 * validatePassword in frontend/src/App.jsx); keep the two in sync.
 */
class AuthValidation
{
    /**
     * Rules for an email field. Trimmed by middleware, must be a valid address,
     * and must contain no whitespace. Pass $unique to also require the address
     * not already exist in the users table (registration / LGU-admin creation).
     *
     * @param  int|null  $ignoreUserId  User id to exclude from the uniqueness
     *                                  check (e.g. when updating an account).
     * @return array<int, mixed>
     */
    public static function emailRules(bool $unique = false, ?int $ignoreUserId = null): array
    {
        $rules = ['required', 'string', 'email', 'not_regex:/\s/'];

        if ($unique) {
            $rules[] = $ignoreUserId
                ? Rule::unique('users', 'email')->ignore($ignoreUserId)
                : Rule::unique('users', 'email');
        }

        return $rules;
    }

    /**
     * Rules for a password field being *set* (registration, change password,
     * LGU-admin creation): the full StrongPassword policy -- 8-64 chars with
     * upper/lower/number/symbol and no whitespace.
     *
     * @return array<int, mixed>
     */
    public static function passwordRules(): array
    {
        return ['required', 'string', new StrongPassword];
    }

    /**
     * Rules for a password field being *checked* at login. Strength is NOT
     * re-validated (existing accounts must still be able to sign in) -- we only
     * require a value and reject any whitespace, since no stored password can
     * contain any. Callers should trim() the value first (see login()).
     *
     * @return array<int, mixed>
     */
    public static function loginPasswordRules(): array
    {
        return ['required', 'string', 'not_regex:/\s/'];
    }

    /**
     * Friendly, user-facing messages shared by every auth flow, so a validation
     * failure never surfaces as a generic framework/server error.
     *
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'email.email' => 'Please enter a valid email address.',
            'email.not_regex' => 'Email address must not contain spaces.',
            'email.unique' => 'This email address is already registered.',
            'password.not_regex' => 'Password cannot contain spaces.',
        ];
    }
}
