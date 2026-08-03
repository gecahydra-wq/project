<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewOrderReceivedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order)
    {
        $this->order->loadMissing(['buyer', 'listing']);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "New Order Received -- Order #{$this->order->order_number}",
        );
    }

    public function content(): Content
    {
        $order = $this->order;
        $frontend = rtrim(config('app.frontend_url'), '/');

        return new Content(
            view: 'emails.orders.new-order-received',
            with: [
                'subject' => "New Order Received -- Order #{$order->order_number}",
                'eyebrow' => 'New Order',
                'headline' => 'You have a new paid order',
                'preheader' => "Order #{$order->order_number} has been paid and is ready to prepare.",
                'rows' => [
                    ['Order Number', $order->order_number],
                    ['Buyer', $order->buyer?->name ?? 'Unknown buyer'],
                    ['Item', $order->listing?->species ?? 'Fingerlings'],
                    ['Quantity', number_format($order->quantity).' '.($order->listing?->unit_label_plural ?? 'pcs')],
                    ['Total Amount', '₱'.number_format((float) $order->total_amount, 2)],
                ],
                'ctaLabel' => 'View Order',
                'ctaUrl' => "{$frontend}/seller/dashboard?tab=orders",
            ],
        );
    }
}
