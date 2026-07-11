<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent whenever App\Support\AccountModeration suspends an account, for any
 * role. The restriction list and login-access line are role-aware -- a
 * suspended Buyer can still log in and browse, a suspended Seller or LGU
 * Admin cannot log in at all.
 */
class AccountSuspendedMail extends Mailable
{
    use Queueable, SerializesModels;

    private const ROLE_LABELS = [
        'buyer' => 'Buyer',
        'seller' => 'Seller',
        'lgu_admin' => 'LGU Admin',
    ];

    private const RESTRICTIONS = [
        'buyer' => [
            'Place orders',
            'Make payments',
            'Send messages',
            'Leave reviews',
            'Contact sellers',
            'Access marketplace purchasing features',
        ],
        'seller' => [
            'Log in to your account',
            'Create listings',
            'Edit or publish listings',
            'Receive new orders',
            'Request withdrawals',
        ],
        'lgu_admin' => [
            'Log in to your account',
            'Approve sellers',
            'Approve seller earnings',
            'Moderate sellers',
            'Access LGU administration',
        ],
    ];

    public function __construct(
        public User $account,
        public string $role,
        public User $moderator,
        public ?string $reason = null,
        public ?string $notes = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your FishMarket Account Has Been Suspended',
        );
    }

    public function content(): Content
    {
        $roleLabel = self::ROLE_LABELS[$this->role] ?? 'Account';

        $rows = [
            ['Role', $roleLabel],
            ['Reason', $this->reason ?? 'Not specified'],
            ['Date', now()->format('M d, Y g:i A')],
            ['Administrator', $this->moderator->name],
        ];

        if ($this->notes) {
            $rows[] = ['Additional Notes', $this->notes];
        }

        return new Content(
            view: 'emails.account.status-changed',
            with: [
                'subject' => 'Your FishMarket Account Has Been Suspended',
                'eyebrow' => 'Account Update',
                'headline' => 'Your account has been suspended',
                'preheader' => 'Your FishMarket account has been suspended.',
                'recipientName' => $this->account->name,
                'introLine' => "Your FishMarket {$roleLabel} account has been suspended by an administrator.",
                'restrictions' => self::RESTRICTIONS[$this->role] ?? [],
                'rows' => $rows,
                'appealLine' => 'If you believe this was a mistake, or would like to appeal this decision, please contact FishMarket support and reference the date and reason above.',
            ],
        );
    }
}
