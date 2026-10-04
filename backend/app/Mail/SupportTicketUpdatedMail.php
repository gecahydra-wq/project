<?php

namespace App\Mail;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Support\SupportTickets;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Tells a buyer or seller that support replied to, or resolved, their ticket
 * -- see App\Support\SupportTickets. Shares the generic details email template.
 */
class SupportTicketUpdatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public SupportTicket $ticket, public ?SupportTicketMessage $reply, public bool $resolved)
    {
        $this->ticket->loadMissing('user');
        $this->reply?->loadMissing('author');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->resolved
                ? "Support Ticket {$this->ticket->ticket_number} Resolved"
                : "New Reply on Support Ticket {$this->ticket->ticket_number}",
        );
    }

    public function content(): Content
    {
        $ticket = $this->ticket;
        $frontend = rtrim(config('app.frontend_url'), '/');
        $dashboard = $ticket->user_role === 'seller' ? 'seller' : 'buyer';

        $rows = [
            ['Ticket', $ticket->ticket_number],
            ['Subject', $ticket->subject],
            ['Status', $this->resolved ? 'Resolved' : 'Answered'],
        ];
        if ($this->reply) {
            $rows[] = [
                $this->reply->author ? SupportTickets::staffLabel($this->reply->author) : 'Support',
                Str::limit($this->reply->body, 500),
            ];
        }

        return new Content(
            view: 'emails.wallet.withdrawal-released',
            with: [
                'subject' => $this->envelope()->subject,
                'eyebrow' => 'Help & Support',
                'headline' => $this->resolved ? 'Your support ticket was resolved' : 'Support replied to your ticket',
                'preheader' => $ticket->subject,
                'recipientName' => $ticket->user?->name ?? 'there',
                'introLine' => $this->resolved
                    ? 'Your support ticket has been marked resolved. If you still need help, just reply on the ticket and it will reopen.'
                    : 'There is a new reply on your support ticket. Open AbaiMarket to read it and reply.',
                'rows' => $rows,
                'closingLine' => 'Thanks for using AbaiMarket!',
                'ctaLabel' => 'Open Ticket',
                'ctaUrl' => "{$frontend}/{$dashboard}/dashboard?tab=support&ticket={$ticket->id}",
            ],
        );
    }
}
