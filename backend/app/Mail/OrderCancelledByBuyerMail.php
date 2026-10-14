<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The seller's copy of a buyer cancellation (OrderController::cancel). The
 * buyer had no way to cancel at all before, so a seller learning their order
 * was gone needed to hear it from whoever knew why -- this mail carries the
 * buyer's reason when they gave one (paid cancellations always do).
 */
class OrderCancelledByBuyerMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order, public ?string $reason = null)
    {
        $this->order->loadMissing(['buyer', 'listing']);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Order Cancelled by Buyer -- Order #{$this->order->order_number}",
        );
    }

    public function content(): Content
    {
        $order = $this->order;
        $frontend = rtrim(config('app.frontend_url'), '/');

        return new Content(
            view: 'emails.orders.order-cancelled-by-buyer',
            with: [
                'subject' => "Order Cancelled by Buyer -- Order #{$this->order->order_number}",
                'eyebrow' => 'Order Update',
                'headline' => 'The buyer cancelled this order',
                'preheader' => "Order #{$order->order_number} was cancelled by the buyer.",
                'rows' => array_filter([
                    ['Order Number', $order->order_number],
                    ['Buyer', $order->buyer?->name ?? 'Unknown buyer'],
                    ['Item', $order->listing?->title ?? $order->listing?->species ?? 'Fingerlings'],
                    ['Quantity', number_format($order->quantity).' '.($order->listing?->unit_label_plural ?? 'pcs')],
                    $this->reason ? ['Reason given', $this->reason] : null,
                ]),
                'ctaLabel' => 'View Order',
                'ctaUrl' => "{$frontend}/seller/dashboard?tab=orders",
            ],
        );
    }
}
