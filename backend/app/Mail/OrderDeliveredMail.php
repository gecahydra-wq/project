<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderDeliveredMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order)
    {
        $this->order->loadMissing(['buyer', 'listing', 'sellerProfile']);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Order Delivered -- Order #{$this->order->order_number}",
        );
    }

    public function content(): Content
    {
        $order = $this->order;
        $frontend = rtrim(config('app.frontend_url'), '/');

        return new Content(
            view: 'emails.orders.order-delivered',
            with: [
                'subject' => "Order Delivered -- Order #{$order->order_number}",
                'eyebrow' => 'Order Update',
                'headline' => 'Your order has been delivered',
                'preheader' => "Order #{$order->order_number} is marked as delivered.",
                'buyerName' => $order->buyer?->name ?? 'there',
                'rows' => [
                    ['Order Number', $order->order_number],
                    ['Seller', $order->sellerProfile?->hatchery_name ?? 'Unknown seller'],
                    ['Delivery Status', 'Delivered'],
                ],
                'ctaLabel' => 'Leave a Review',
                'ctaUrl' => "{$frontend}/buyer/dashboard?tab=orders&order={$order->order_number}",
            ],
        );
    }
}
