<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderConfirmedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order)
    {
        $this->order->loadMissing(['buyer', 'listing', 'sellerProfile']);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Order Confirmed -- Order #{$this->order->order_number}",
        );
    }

    public function content(): Content
    {
        $order = $this->order;
        $frontend = rtrim(config('app.frontend_url'), '/');

        return new Content(
            view: 'emails.orders.order-confirmed',
            with: [
                'subject' => "Order Confirmed -- Order #{$order->order_number}",
                'eyebrow' => 'Order Update',
                'headline' => 'Your seller has confirmed your order',
                'preheader' => "Order #{$order->order_number} is confirmed and being prepared.",
                'buyerName' => $order->buyer?->name ?? 'there',
                'rows' => [
                    ['Order Number', $order->order_number],
                    ['Seller', $order->sellerProfile?->hatchery_name ?? 'Unknown seller'],
                    ['Item', $order->listing?->species ?? 'Fingerlings'],
                    ['Quantity', number_format($order->quantity).' '.($order->listing?->unit_label_plural ?? 'pcs')],
                    ['Order Status', 'Confirmed'],
                ],
                'ctaLabel' => 'View My Order',
                'ctaUrl' => "{$frontend}/buyer/dashboard?tab=orders&order={$order->order_number}",
            ],
        );
    }
}
