<?php

namespace App\Mail;

use App\Models\WithdrawalRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Seller counterpart of LguWithdrawalApprovedMail, sent from
 * SuperAdminController::approveWithdrawal(). "Approved" means the request was
 * accepted and is being processed -- NOT paid yet; WithdrawalReleasedMail
 * follows when the Super Admin marks it Paid.
 */
class SellerWithdrawalApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public WithdrawalRequest $withdrawal)
    {
        $this->withdrawal->loadMissing('sellerProfile.user');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Withdrawal Request Has Been Approved',
        );
    }

    public function content(): Content
    {
        $withdrawal = $this->withdrawal;
        $seller = $withdrawal->sellerProfile;
        $frontend = rtrim(config('app.frontend_url'), '/');

        $rows = [
            ['Reference Number', 'WD-'.str_pad((string) $withdrawal->id, 6, '0', STR_PAD_LEFT)],
            ['Requested Amount', '₱'.number_format((float) $withdrawal->amount, 2)],
            ['Platform Payout Fee', '₱'.number_format((float) $withdrawal->platform_fee, 2)],
            ['Amount You Will Receive', '₱'.number_format($withdrawal->net_amount, 2)],
            ['Method', ucfirst(str_replace('_', ' ', $withdrawal->method))],
            ['Approval Date', ($withdrawal->reviewed_at ?? now())->format('M d, Y g:i A')],
            ['Status', 'Approved -- Awaiting Payout'],
        ];

        return new Content(
            view: 'emails.wallet.withdrawal-released',
            with: [
                'subject' => 'Your Withdrawal Request Has Been Approved',
                'eyebrow' => 'Withdrawal Update',
                'headline' => 'Your withdrawal request has been approved',
                'preheader' => 'Your withdrawal request has been approved and is being processed.',
                'recipientName' => $seller?->hatchery_name ?? ($seller?->user?->name ?? 'there'),
                'introLine' => 'Your withdrawal request has been approved and is now being processed. You will receive a separate email once the payment has actually been made.',
                'rows' => $rows,
                'ctaLabel' => 'View Wallet',
                'ctaUrl' => "{$frontend}/seller/dashboard?tab=wallet",
            ],
        );
    }
}
