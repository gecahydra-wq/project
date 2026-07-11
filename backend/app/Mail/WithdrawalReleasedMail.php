<?php

namespace App\Mail;

use App\Models\WithdrawalRequest;
use App\Support\SellerWallet;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WithdrawalReleasedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public WithdrawalRequest $withdrawal)
    {
        $this->withdrawal->loadMissing('sellerProfile.user');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Withdrawal Has Been Successfully Processed',
        );
    }

    public function content(): Content
    {
        $withdrawal = $this->withdrawal;
        $seller = $withdrawal->sellerProfile;
        $frontend = rtrim(config('app.frontend_url'), '/');

        // Reuses the exact same balance math as the Wallet page (see
        // SellerController::walletSummary) so this email never disagrees
        // with what the seller sees in-app.
        $wallet = $seller ? SellerWallet::summary($seller) : null;

        $rows = [
            ['Reference Number', 'WD-'.str_pad((string) $withdrawal->id, 6, '0', STR_PAD_LEFT)],
            ['Requested Amount', '₱'.number_format((float) $withdrawal->amount, 2)],
            ['Platform Payout Fee', '₱'.number_format((float) $withdrawal->platform_fee, 2)],
            ['Amount Received', '₱'.number_format($withdrawal->net_amount, 2)],
            ['Method', ucfirst(str_replace('_', ' ', $withdrawal->method))],
            ['Release Date', ($withdrawal->paid_at ?? now())->format('M d, Y g:i A')],
            ['Status', 'Paid'],
        ];

        if ($wallet) {
            $rows[] = ['Available Balance', '₱'.number_format($wallet['available_balance'], 2)];
            $rows[] = ['Withdrawn Amount (Total)', '₱'.number_format($wallet['withdrawn_amount'], 2)];
        }

        return new Content(
            view: 'emails.wallet.withdrawal-released',
            with: [
                'subject' => 'Your Withdrawal Has Been Successfully Processed',
                'eyebrow' => 'Withdrawal Update',
                'headline' => 'Your withdrawal has been successfully processed',
                'preheader' => 'Your withdrawal has been paid out.',
                'recipientName' => $seller?->hatchery_name ?? ($seller?->user?->name ?? 'there'),
                'rows' => $rows,
                'ctaLabel' => 'View Wallet',
                'ctaUrl' => "{$frontend}/seller/dashboard?tab=wallet",
            ],
        );
    }
}
