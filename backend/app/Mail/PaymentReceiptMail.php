<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order)
    {
        $this->order->loadMissing(['buyer', 'listing', 'sellerProfile', 'payment']);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Payment Received -- Order #{$this->order->order_number}",
        );
    }

    public function content(): Content
    {
        $order = $this->order;
        $payment = $order->payment;

        $frontend = rtrim(config('app.frontend_url'), '/');

        return new Content(
            view: 'emails.orders.payment-receipt',
            with: [
                'subject' => "Payment Received -- Order #{$order->order_number}",
                'eyebrow' => 'Payment Receipt',
                'headline' => 'Your payment was successful',
                'preheader' => "We've received your payment for order #{$order->order_number}.",
                'buyerName' => $order->buyer?->name ?? 'there',
                'rows' => [
                    ['Order Number', $order->order_number],
                    ['Seller', $order->sellerProfile?->hatchery_name ?? 'Unknown seller'],
                    ['Item', $order->listing?->species ?? 'Fingerlings'],
                    ['Quantity', number_format($order->quantity).' pcs'],
                    ['Unit Price', '₱'.number_format((float) $order->unit_price, 2)],
                    ['Total Amount Paid', '₱'.number_format((float) $order->total_amount, 2)],
                    ['Payment Method', 'PayMongo'],
                    ['Transaction Reference', $payment?->provider_reference ?? $order->order_number],
                    ['Payment Date', ($payment?->updated_at ?? $order->updated_at)?->format('M d, Y g:i A')],
                    ['Payment Status', 'Paid -- Held in Escrow'],
                ],
                'ctaLabel' => 'View My Order',
                'ctaUrl' => "{$frontend}/buyer/dashboard?tab=orders&order={$order->order_number}",
            ],
        );
    }
}
