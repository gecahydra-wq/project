<?php

namespace App\Mail;

use App\Models\FingerlingListing;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ListingApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public FingerlingListing $listing)
    {
        $this->listing->loadMissing(['sellerProfile.user', 'municipality']);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Listing Approved -- {$this->listing->species}",
        );
    }

    public function content(): Content
    {
        $listing = $this->listing;
        $frontend = rtrim(config('app.frontend_url'), '/');

        return new Content(
            view: 'emails.listings.listing-approved',
            with: [
                'subject' => "Listing Approved -- {$listing->species}",
                'eyebrow' => 'Listing Update',
                'headline' => 'Your listing is now live',
                'preheader' => "{$listing->species} is now visible in the AbaiMarket marketplace.",
                'sellerName' => $listing->sellerProfile?->hatchery_name ?? ($listing->sellerProfile?->user?->name ?? 'there'),
                'rows' => [
                    ['Listing', $listing->title ?: $listing->species],
                    ['Species', $listing->species],
                    ['Municipality', $listing->municipality?->name ?? 'Unknown'],
                    ['Status', 'Approved'],
                ],
                'ctaLabel' => 'View Listing',
                'ctaUrl' => "{$frontend}/seller/dashboard?tab=listings",
            ],
        );
    }
}
