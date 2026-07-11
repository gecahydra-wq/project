<?php

namespace App\Mail;

use App\Models\FingerlingListing;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ListingRejectedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public FingerlingListing $listing)
    {
        $this->listing->loadMissing(['sellerProfile.user', 'municipality']);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Listing Rejected -- {$this->listing->species}",
        );
    }

    public function content(): Content
    {
        $listing = $this->listing;
        $frontend = rtrim(config('app.frontend_url'), '/');

        return new Content(
            view: 'emails.listings.listing-rejected',
            with: [
                'subject' => "Listing Rejected -- {$listing->species}",
                'eyebrow' => 'Listing Update',
                'headline' => 'Your listing needs changes',
                'preheader' => "{$listing->species} was not approved by your municipality's LGU.",
                'sellerName' => $listing->sellerProfile?->hatchery_name ?? ($listing->sellerProfile?->user?->name ?? 'there'),
                'reason' => $listing->rejection_reason ?: 'No specific reason was provided. Please review your listing details and resubmit, or reach out to your municipality\'s LGU for guidance.',
                'rows' => [
                    ['Listing', $listing->title ?: $listing->species],
                    ['Species', $listing->species],
                    ['Municipality', $listing->municipality?->name ?? 'Unknown'],
                    ['Status', 'Rejected'],
                ],
                'ctaLabel' => 'View Listings',
                'ctaUrl' => "{$frontend}/seller/dashboard?tab=listings",
            ],
        );
    }
}
