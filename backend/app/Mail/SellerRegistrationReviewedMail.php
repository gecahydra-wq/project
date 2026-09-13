<?php

namespace App\Mail;

use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells a seller their hatchery registration was approved or rejected -- see
 * App\Support\SellerApproval. Without it the decision only appeared as an
 * in-app notification, which a seller who can't list yet has little reason to
 * log in and see. Shares the generic details email template.
 */
class SellerRegistrationReviewedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public SellerProfile $seller, public User $reviewer, public string $role)
    {
        $this->seller->loadMissing(['user', 'municipality']);
    }

    private function approved(): bool
    {
        return $this->seller->approval_status === 'approved';
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->approved() ? 'Your AbaiMarket Seller Registration Is Approved' : 'Your AbaiMarket Seller Registration Was Not Approved',
        );
    }

    public function content(): Content
    {
        $seller = $this->seller;
        $frontend = rtrim(config('app.frontend_url'), '/');
        $reviewedBy = $this->role === 'lgu_admin' ? 'LGU Admin' : 'Super Admin';

        $rows = [
            ['Hatchery', $seller->hatchery_name],
            ['Municipality', $seller->municipality?->name ?? '--'],
            ['Reviewed By', $reviewedBy],
            ['Status', $this->approved() ? 'Approved' : 'Rejected'],
        ];
        if (! $this->approved()) {
            $rows[] = ['Reason', $seller->registration_rejection_reason ?? '--'];
        }

        return new Content(
            view: 'emails.wallet.withdrawal-released',
            with: [
                'subject' => $this->envelope()->subject,
                'eyebrow' => 'Seller Registration',
                'headline' => $this->approved() ? 'Your seller registration is approved' : 'Your seller registration was not approved',
                'preheader' => $this->approved() ? 'You can now create listings on AbaiMarket.' : 'Your hatchery registration needs attention.',
                'recipientName' => $seller->user?->name ?? $seller->hatchery_name,
                'introLine' => $this->approved()
                    ? 'Your hatchery registration has been reviewed and approved. Your account is now verified and you can start creating listings.'
                    : 'Your hatchery registration was reviewed and could not be approved yet. You can update your hatchery profile and contact your LGU to have it reviewed again.',
                'rows' => $rows,
                'closingLine' => 'Thanks for joining AbaiMarket!',
                'ctaLabel' => $this->approved() ? 'Create a Listing' : 'Open Seller Dashboard',
                'ctaUrl' => "{$frontend}/seller/dashboard",
            ],
        );
    }
}
