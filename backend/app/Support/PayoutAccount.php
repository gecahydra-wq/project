<?php

namespace App\Support;

use Illuminate\Validation\Rule;

/**
 * Validation for where a withdrawal is paid out to. Shared by the seller
 * (SellerController::requestWithdrawal) and LGU (LguController::requestWithdrawal)
 * withdrawal endpoints so both accept exactly the same account numbers.
 *
 * GCash and Maya pay out to a Philippine mobile number: 11 digits starting
 * with 09 (e.g. 09954757102). A bank transfer needs a Philippine bank account
 * number: digits only, 10 to 16 long, which covers the major local banks.
 *
 * Every withdrawal, seller or LGU, must be at least MIN_AMOUNT pesos.
 *
 * A bank transfer also names the bank (bank_name). The form offers BANKS and
 * an "Other" option where the user types the name, so the server accepts any
 * bank name up to 100 characters rather than only the listed ones.
 *
 * The frontend mirrors these patterns, the minimum and the messages
 * (withdrawalFormIssue in frontend/src/App.jsx); keep the two in sync.
 */
class PayoutAccount
{
    public const METHODS = ['gcash', 'maya', 'bank_transfer'];

    /** Philippine banks offered in the withdrawal form (frontend PH_BANKS mirrors this list). */
    public const BANKS = [
        'BDO Unibank', 'Bank of the Philippine Islands (BPI)', 'Metrobank', 'Land Bank of the Philippines',
        'Philippine National Bank (PNB)', 'China Bank', 'Security Bank', 'UnionBank of the Philippines',
        'RCBC', 'EastWest Bank', 'Development Bank of the Philippines (DBP)', 'PSBank',
        'Asia United Bank (AUB)', 'Bank of Commerce', 'Philippine Veterans Bank', 'Maybank Philippines',
        'CIMB Bank Philippines', 'PBCom', 'Sterling Bank of Asia', 'GoTyme Bank', 'Tonik Digital Bank',
        'UNO Digital Bank', 'Maya Bank', 'BDO Network Bank', 'Card Bank',
    ];

    public const BANK_NAME_MESSAGE = 'Choose your bank.';

    public const MOBILE_PATTERN = '/^09\d{9}$/';

    public const BANK_PATTERN = '/^\d{10,16}$/';

    public const MOBILE_MESSAGE = 'Enter a valid 11-digit mobile number starting with 09 (e.g. 09954757102).';

    public const BANK_MESSAGE = 'Enter a valid bank account number (10 to 16 digits, numbers only).';

    public const AMOUNT_MESSAGE = 'Please enter a valid amount.';

    /** Smallest withdrawal a seller or an LGU may request, in pesos. */
    public const MIN_AMOUNT = 100;

    public const MIN_AMOUNT_MESSAGE = 'The minimum withdrawal is ₱100.00.';

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(?string $method): array
    {
        $pattern = $method === 'bank_transfer' ? self::BANK_PATTERN : self::MOBILE_PATTERN;

        return [
            'method' => ['required', Rule::in(self::METHODS)],
            'account_name' => ['required', 'string'],
            'account_number' => ['required', 'string', 'regex:'.$pattern],
            'bank_name' => [Rule::requiredIf($method === 'bank_transfer'), 'nullable', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:'.self::MIN_AMOUNT],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(?string $method): array
    {
        return [
            'account_number.regex' => $method === 'bank_transfer' ? self::BANK_MESSAGE : self::MOBILE_MESSAGE,
            'bank_name.required' => self::BANK_NAME_MESSAGE,
            'amount.numeric' => self::AMOUNT_MESSAGE,
            'amount.min' => self::MIN_AMOUNT_MESSAGE,
        ];
    }

    /** The bank, only for a bank transfer -- GCash / Maya never store one. */
    public static function bankFor(array $data): ?string
    {
        return ($data['method'] ?? null) === 'bank_transfer' ? trim((string) ($data['bank_name'] ?? '')) ?: null : null;
    }

    /** "GCash", "Maya", or "Bank Transfer (BDO Unibank)" -- for emails and notifications. */
    public static function methodLabel(?string $method, ?string $bankName = null): string
    {
        $label = ['gcash' => 'GCash', 'maya' => 'Maya', 'bank_transfer' => 'Bank Transfer'][$method] ?? (string) $method;

        return $method === 'bank_transfer' && $bankName ? "{$label} ({$bankName})" : $label;
    }
}
