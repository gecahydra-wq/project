<?php

namespace App\Mail;

use App\Models\Settlement;
use App\Support\SellerWallet;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent when an LGU Admin approves a completed order's earnings (see
 * LguController::approveEarnings). This is NOT a payout -- it only moves the
 * seller's share from Pending Balance to Available Balance. The seller
 * still has to request a withdrawal separately, and the Super Admin still
 * has to release it (see WithdrawalReleasedMail).
 *
 * Deliberately shows only the Seller Share, never the gross order amount,
 * the LGU Share, or the Platform Share -- a seller must never be able to
 * infer another party's cut from their own transactional email.
 */
class SellerEarningsApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Settlement $settlement)
    {
        $this->settlement->loadMissing(['order.buyer', 'order.listing', 'sellerProfile.user']);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Seller Earnings Approved',
        );
    }

    public function content(): Content
    {
        $settlement = $this->settlement;
        $order = $settlement->order;
        $seller = $settlement->sellerProfile;
        $frontend = rtrim(config('app.frontend_url'), '/');

        // Reuses the exact same balance math as the Wallet page (see
        // SellerController::walletSummary) so this email never disagrees
        // with what the seller sees in-app.
        $wallet = $seller ? SellerWallet::summary($seller) : null;

        $rows = [
            ['Order Number', $order?->order_number ?? 'N/A'],
            ['Buyer', $order?->buyer?->name ?? 'Unknown buyer'],
            ['Listing', $order?->listing?->species ?? 'Fingerlings'],
            ['Your Earnings', '₱'.number_format((float) $settlement->seller_share, 2)],
            ['Approval Date', $settlement->settled_at->format('M d, Y g:i A')],
        ];

        if ($wallet) {
            $rows[] = ['Updated Available Balance', '₱'.number_format($wallet['available_balance'], 2)];
        }

        return new Content(
            view: 'emails.wallet.earnings-approved',
            with: [
                'subject' => 'Seller Earnings Approved',
                'eyebrow' => 'Earnings Update',
                'headline' => 'Your order earnings have been approved',
                'preheader' => 'The completed order payment has been approved.',
                'sellerName' => $seller?->hatchery_name ?? ($seller?->user?->name ?? 'there'),
                'sellerPercent' => rtrim(rtrim(number_format((float) $settlement->seller_percent, 2), '0'), '.'),
                'rows' => $rows,
                'ctaLabel' => 'View Wallet',
                'ctaUrl' => "{$frontend}/seller/dashboard?tab=wallet",
            ],
        );
    }
}
