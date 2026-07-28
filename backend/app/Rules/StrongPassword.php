<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The single source of truth for AbaiMarket's password policy. Applied wherever
 * a user chooses a password -- self-registration, changing an existing password,
 * and Super-Admin provisioning of LGU admins -- so the rules can never drift
 * apart between endpoints.
 *
 * Policy: 8-64 characters, at least one uppercase letter, one lowercase letter,
 * one number, and one special character, with NO whitespace anywhere. Every
 * common symbol (! @ # $ % ^ & * ( ) - _ + = [ ] { } ; : ' " < > , . ? / \ | ` ~)
 * counts as a special character and is allowed -- only whitespace is blocked.
 *
 * The frontend mirrors these exact checks and messages in validatePassword()
 * (frontend/src/App.jsx); keep the two in sync when changing the policy.
 */
class StrongPassword implements ValidationRule
{
    /**
     * Fail with one clear, user-facing message per unmet requirement, so the
     * user sees precisely what to fix rather than a generic error.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $value = is_string($value) ? $value : '';

        // Whitespace first: a spaced password also skews the length/character
        // checks below, so reject it up front and let the user clear the spaces.
        if (preg_match('/\s/', $value)) {
            $fail('Password cannot contain spaces.');

            return;
        }

        if (mb_strlen($value) < 8) {
            $fail('Password must be at least 8 characters.');
        }

        if (mb_strlen($value) > 64) {
            $fail('Password must be at most 64 characters.');
        }

        if (! preg_match('/[A-Z]/', $value)) {
            $fail('Password must contain an uppercase letter.');
        }

        if (! preg_match('/[a-z]/', $value)) {
            $fail('Password must contain a lowercase letter.');
        }

        if (! preg_match('/[0-9]/', $value)) {
            $fail('Password must contain a number.');
        }

        // Any non-alphanumeric character counts as special. Whitespace is
        // already excluded above, so this can only match real symbols.
        if (! preg_match('/[^A-Za-z0-9]/', $value)) {
            $fail('Password must contain a special character.');
        }
    }
}
