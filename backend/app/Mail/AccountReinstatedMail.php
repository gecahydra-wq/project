<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent whenever App\Support\AccountModeration reinstates a previously
 * suspended account, for any role. Reuses the same
 * emails.account.status-changed view as AccountSuspendedMail with no
 * restriction list, since a reinstated account has none.
 */
class AccountReinstatedMail extends Mailable
{
    use Queueable, SerializesModels;

    private const ROLE_LABELS = [
        'buyer' => 'Buyer',
        'seller' => 'Seller',
        'lgu_admin' => 'LGU Admin',
    ];

    public function __construct(
        public User $account,
        public string $role,
        public User $moderator,
        public string $reason,
        public ?string $notes = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your AbaiMarket Account Has Been Reinstated',
        );
    }

    public function content(): Content
    {
        $roleLabel = self::ROLE_LABELS[$this->role] ?? 'Account';

        $rows = [
            ['Role', $roleLabel],
            ['Reason', $this->reason],
            ['Date', now()->format('M d, Y g:i A')],
            ['Administrator', $this->moderator->name],
        ];

        if ($this->notes) {
            $rows[] = ['Additional Notes', $this->notes];
        }

        return new Content(
            view: 'emails.account.status-changed',
            with: [
                'subject' => 'Your AbaiMarket Account Has Been Reinstated',
                'eyebrow' => 'Account Update',
                'headline' => 'Your account has been reinstated',
                'preheader' => 'Your AbaiMarket account has been reinstated.',
                'recipientName' => $this->account->name,
                'introLine' => "Good news -- your AbaiMarket {$roleLabel} account has been reinstated and full access has been restored.",
                'restrictions' => [],
                'rows' => $rows,
                'appealLine' => 'If you have any questions about this decision, please contact AbaiMarket support.',
            ],
        );
    }
}
