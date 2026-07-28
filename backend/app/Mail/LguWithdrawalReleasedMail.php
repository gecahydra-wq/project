<?php

namespace App\Mail;

use App\Models\LguWithdrawalRequest;
use App\Support\LguWallet;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent only when the Super Admin marks an LGU revenue withdrawal as Paid --
 * see SuperAdminController::markLguWithdrawalPaid(). Reuses the same
 * emails.wallet.withdrawal-released view as the seller's
 * WithdrawalReleasedMail (see that class), just with LGU-specific rows and
 * no platform-fee line, since LGU withdrawals aren't charged one.
 */
class LguWithdrawalReleasedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public LguWithdrawalRequest $withdrawal)
    {
        $this->withdrawal->loadMissing(['municipality', 'requestedBy']);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your LGU Revenue Withdrawal Has Been Processed',
        );
    }

    public function content(): Content
    {
        $withdrawal = $this->withdrawal;
        $municipality = $withdrawal->municipality;
        $frontend = rtrim(config('app.frontend_url'), '/');

        // Reuses the exact same balance math as the LGU Wallet page (see
        // LguController::wallet()) so this email never disagrees with what
        // the LGU sees in-app.
        $wallet = $municipality ? LguWallet::summary($municipality->id) : null;

        $rows = [
            ['Reference Number', 'LGU-WD-'.str_pad((string) $withdrawal->id, 6, '0', STR_PAD_LEFT)],
            ['Withdrawal Amount', '₱'.number_format((float) $withdrawal->amount, 2)],
            ['Method', ucfirst(str_replace('_', ' ', $withdrawal->method))],
            ['Payment Date', ($withdrawal->paid_at ?? now())->format('M d, Y g:i A')],
            ['Status', 'Paid'],
        ];

        if ($wallet) {
            $rows[] = ['Updated Available Balance', '₱'.number_format($wallet['available_balance'], 2)];
        }

        return new Content(
            view: 'emails.wallet.withdrawal-released',
            with: [
                'subject' => 'Your LGU Revenue Withdrawal Has Been Processed',
                'eyebrow' => 'Withdrawal Update',
                'headline' => 'Your LGU revenue withdrawal has been processed',
                'preheader' => 'Your LGU revenue withdrawal has been paid out.',
                'recipientName' => $withdrawal->requestedBy?->name ?? ($municipality?->name ?? 'there'),
                'rows' => $rows,
                'closingLine' => 'Thanks for using AbaiMarket!',
                'ctaLabel' => 'View LGU Wallet',
                'ctaUrl' => "{$frontend}/lgu/dashboard?tab=wallet",
            ],
        );
    }
}
