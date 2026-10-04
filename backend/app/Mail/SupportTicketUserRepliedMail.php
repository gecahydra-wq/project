<?php

namespace App\Mail;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Tells an LGU Admin or Super Admin that a buyer or seller replied on a
 * support ticket they can answer -- see App\Support\SupportTickets::reply.
 * Sent alongside the in-app notification so a reply is not missed by staff
 * who are not watching the dashboard. Shares the generic details template.
 */
class SupportTicketUserRepliedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public SupportTicket $ticket, public SupportTicketMessage $reply, public User $staff)
    {
        $this->ticket->loadMissing('user');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "New Reply on Support Ticket {$this->ticket->ticket_number}");
    }

    public function content(): Content
    {
        $ticket = $this->ticket;
        $frontend = rtrim(config('app.frontend_url'), '/');
        $dashboard = $this->staff->role === 'super_admin' ? 'admin' : 'lgu';
        $sender = trim(($ticket->first_name ?? '').' '.($ticket->last_name ?? '')) ?: ($ticket->user?->name ?? 'The user');

        return new Content(
            view: 'emails.wallet.withdrawal-released',
            with: [
                'subject' => $this->envelope()->subject,
                'eyebrow' => 'Help & Support',
                'headline' => 'A user replied to a support ticket',
                'preheader' => $ticket->subject,
                'recipientName' => $this->staff->name,
                'introLine' => "{$sender} replied on a support ticket you can answer. Open it in the Support Tickets tab to respond.",
                'rows' => [
                    ['Ticket', $ticket->ticket_number],
                    ['Subject', $ticket->subject],
                    ['From', $sender.' ('.ucfirst($ticket->user_role).')'],
                    ['Reply', Str::limit($this->reply->body, 2000)],
                ],
                'closingLine' => 'Thanks for helping AbaiMarket users!',
                'ctaLabel' => 'Open Ticket',
                'ctaUrl' => "{$frontend}/{$dashboard}/dashboard?tab=support&ticket={$ticket->id}",
            ],
        );
    }
}
