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
 * Emails the person who opened a support ticket -- see App\Support\SupportTickets.
 * `kind` is "received" (confirmation when they submit), "reply" (a staff
 * response) or "closed". Sent to the ticket's contact email. Shares the
 * generic details email template.
 */
class SupportTicketUpdatedMail extends Mailable
{
    use Queueable, SerializesModels;

    private const COPY = [
        'received' => [
            'subject' => 'We Received Your Support Ticket %s',
            'headline' => 'We received your support ticket',
            'intro' => 'Thanks for contacting AbaiMarket. Your ticket is in our support queue. This is not real-time support: our team reviews tickets as soon as they can and will reply to this email address. They may also message you on the ticket to clarify your issue, so check My Tickets in Help & Support from time to time.',
            'status' => 'Open',
        ],
        'reply' => [
            'subject' => 'New Reply on Support Ticket %s',
            'headline' => 'Support replied to your ticket',
            'intro' => 'Our support team replied to your ticket. To reply or clarify, open it under My Tickets in Help & Support.',
            'status' => 'Answered',
        ],
        'closed' => [
            'subject' => 'Support Ticket %s Closed',
            'headline' => 'Your support ticket was closed',
            'intro' => 'Your support ticket has been closed. If you still need help, open a new ticket from Help & Support.',
            'status' => 'Closed',
        ],
    ];

    public function __construct(public SupportTicket $ticket, public ?SupportTicketMessage $reply, public string $kind)
    {
        $this->ticket->loadMissing('user');
        $this->reply?->loadMissing('author');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: sprintf(self::COPY[$this->kind]['subject'], $this->ticket->ticket_number));
    }

    public function content(): Content
    {
        $ticket = $this->ticket;
        $copy = self::COPY[$this->kind];
        $frontend = rtrim(config('app.frontend_url'), '/');
        $dashboard = $ticket->user_role === 'seller' ? 'seller' : 'buyer';

        $rows = [
            ['Ticket', $ticket->ticket_number],
            ['Subject', $ticket->subject],
            ['Status', $copy['status']],
        ];
        if ($this->reply) {
            $rows[] = [
                $this->reply->author ? SupportTickets::staffLabel($this->reply->author) : 'Support',
                Str::limit($this->reply->body, 2000),
            ];
        }

        return new Content(
            view: 'emails.wallet.withdrawal-released',
            with: [
                'subject' => $this->envelope()->subject,
                'eyebrow' => 'Help & Support',
                'headline' => $copy['headline'],
                'preheader' => $ticket->subject,
                'recipientName' => $ticket->first_name ?: ($ticket->user?->name ?? 'there'),
                'introLine' => $copy['intro'],
                'rows' => $rows,
                'closingLine' => 'Thanks for using AbaiMarket!',
                'ctaLabel' => 'View My Tickets',
                'ctaUrl' => "{$frontend}/{$dashboard}/dashboard?tab=support&ticket={$ticket->id}",
            ],
        );
    }
}
