<?php

namespace App\Mail;

use App\Models\LguWithdrawalRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent when the Super Admin approves an LGU revenue withdrawal request --
 * see SuperAdminController::approveLguWithdrawal(). This is a distinct
 * event from LguWithdrawalReleasedMail: "approved" means the Super Admin
 * has accepted the request and is processing it, NOT that the money has
 * actually been paid out yet -- that's still a separate step (Mark as
 * Paid), which fires its own email. Reuses the same
 * emails.wallet.withdrawal-released view via the introLine/closingLine
 * overrides so the two emails share one template but never claim the funds
 * have already moved.
 */
class LguWithdrawalApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public LguWithdrawalRequest $withdrawal)
    {
        $this->withdrawal->loadMissing(['municipality', 'requestedBy']);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your LGU Revenue Withdrawal Request Has Been Approved',
        );
    }

    public function content(): Content
    {
        $withdrawal = $this->withdrawal;
        $municipality = $withdrawal->municipality;
        $frontend = rtrim(config('app.frontend_url'), '/');

        $rows = [
            ['Reference Number', 'LGU-WD-'.str_pad((string) $withdrawal->id, 6, '0', STR_PAD_LEFT)],
            ['Withdrawal Amount', '₱'.number_format((float) $withdrawal->amount, 2)],
            ['Method', ucfirst(str_replace('_', ' ', $withdrawal->method))],
            ['Approval Date', ($withdrawal->reviewed_at ?? now())->format('M d, Y g:i A')],
            ['Status', 'Approved -- Awaiting Payout'],
        ];

        return new Content(
            view: 'emails.wallet.withdrawal-released',
            with: [
                'subject' => 'Your LGU Revenue Withdrawal Request Has Been Approved',
                'eyebrow' => 'Withdrawal Update',
                'headline' => 'Your LGU revenue withdrawal request has been approved',
                'preheader' => 'Your LGU revenue withdrawal request has been approved and is being processed.',
                'recipientName' => $withdrawal->requestedBy?->name ?? ($municipality?->name ?? 'there'),
                'introLine' => 'Your LGU revenue withdrawal request has been approved and is now being processed. You will receive a separate email once the payment has actually been made.',
                'rows' => $rows,
                'closingLine' => 'Thanks for using AbaiMarket!',
                'ctaLabel' => 'View LGU Wallet',
                'ctaUrl' => "{$frontend}/lgu/dashboard?tab=wallet",
            ],
        );
    }
}
