<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent when App\Support\AccountModeration permanently removes an account.
 * Unlike AccountSuspendedMail this is terminal and always sent before the
 * delete actually happens -- it's the account holder's only record of it, so
 * it deliberately restates the reason rather than pointing at a dashboard
 * they can no longer log into.
 *
 * Passes no restrictions list: the shared status-changed view frames that
 * block as "while suspended, you cannot...", which doesn't apply to an
 * account that no longer exists.
 */
class AccountRemovedMail extends Mailable
{
    use Queueable, SerializesModels;

    private const ROLE_LABELS = [
        'buyer' => 'Buyer',
        'seller' => 'Seller',
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
            subject: 'Your AbaiMarket Account Has Been Removed',
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
                'subject' => 'Your AbaiMarket Account Has Been Removed',
                'eyebrow' => 'Account Update',
                'headline' => 'Your account has been removed',
                'preheader' => 'Your AbaiMarket account has been permanently removed.',
                'recipientName' => $this->account->name,
                'introLine' => "Your AbaiMarket {$roleLabel} account has been permanently removed by an administrator. You will no longer be able to log in, and your profile is no longer on the marketplace.",
                'restrictions' => [],
                'rows' => $rows,
                'appealLine' => 'If you believe this was a mistake, or would like to appeal this decision, please contact AbaiMarket support and reference the date and reason above. You are welcome to register a new account once any issue has been resolved.',
            ],
        );
    }
}
