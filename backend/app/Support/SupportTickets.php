<?php

namespace App\Support;

use App\Mail\SupportTicketUpdatedMail;
use App\Models\AppNotification;
use App\Models\Order;
use App\Models\SellerProfile;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

/**
 * Opening, answering and closing support tickets -- the one place that
 * decides who a ticket belongs to, who may see it, and who hears about it.
 *
 * Every ticket is shared. It belongs to one municipality -- the linked
 * order's seller's, otherwise the user's own -- and that municipality's LGU
 * Admins and every Super Admin can see it, are notified about it, and can
 * answer it. Whoever picks it up replies; nothing routes it to one of them.
 */
class SupportTickets
{
    public const ATTACHMENT_RULES = ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:10240'];

    public static function open(User $user, string $category, string $subject, string $body, ?Order $order = null, ?UploadedFile $attachment = null): SupportTicket
    {
        $ticket = SupportTicket::create([
            'user_id' => $user->id,
            'user_role' => $user->role,
            'category' => $category,
            'subject' => $subject,
            'municipality_id' => self::municipalityFor($user, $order),
            'order_id' => $order?->id,
            'status' => 'open',
            'last_activity_at' => now(),
        ]);
        $ticket->update(['ticket_number' => sprintf('SUP-%06d', $ticket->id)]);

        self::addMessage($ticket, $user, $body, $attachment);

        self::notifyStaff($ticket, 'support_ticket_opened', 'New Support Ticket', sprintf(
            '%s %s opened %s (%s): %s',
            ucfirst($user->role),
            $user->name,
            $ticket->ticket_number,
            SupportTicket::CATEGORIES[$category]['label'],
            $subject
        ));

        ActivityLog::record([
            'actor_id' => $user->id,
            'actor_role' => $user->role,
            'action' => 'support_ticket_opened',
            'municipality_id' => $ticket->municipality_id,
            'reference_type' => 'SUP',
            'reference_number' => $ticket->ticket_number,
            'description' => sprintf('%s opened support ticket %s -- %s.', $user->name, $ticket->ticket_number, $subject),
        ]);

        return $ticket;
    }

    /**
     * Add a reply. The ticket's status follows whoever spoke last: the user
     * replying puts it back in the staff queue (reopening it if resolved), a
     * staff reply means it is waiting on the user.
     */
    public static function reply(SupportTicket $ticket, User $author, string $body, ?UploadedFile $attachment = null): SupportTicketMessage
    {
        $message = self::addMessage($ticket, $author, $body, $attachment);
        $fromOwner = $author->id === $ticket->user_id;

        $ticket->update([
            'status' => $fromOwner ? 'open' : 'answered',
            'last_activity_at' => now(),
            'resolved_by' => $fromOwner ? null : $ticket->resolved_by,
            'resolved_at' => $fromOwner ? null : $ticket->resolved_at,
        ]);

        if ($fromOwner) {
            self::notifyStaff($ticket, 'support_ticket_reply', 'Support Ticket Reply', sprintf(
                '%s replied on %s: %s',
                $author->name,
                $ticket->ticket_number,
                $ticket->subject
            ));
        } else {
            self::notifyOwner($ticket, 'support_ticket_reply', 'Support Replied to Your Ticket', sprintf(
                '%s replied on %s: %s',
                self::staffLabel($author),
                $ticket->ticket_number,
                $ticket->subject
            ));
            SafeMailer::send($ticket->user?->email, new SupportTicketUpdatedMail($ticket, $message, false));
        }

        return $message;
    }

    /** Staff close a ticket. The user can still reply, which reopens it. */
    public static function resolve(SupportTicket $ticket, User $staff, ?string $note = null): SupportTicket
    {
        $message = $note ? self::addMessage($ticket, $staff, $note) : null;

        $ticket->update([
            'status' => 'resolved',
            'resolved_by' => $staff->id,
            'resolved_at' => now(),
            'last_activity_at' => now(),
        ]);

        self::notifyOwner($ticket, 'support_ticket_resolved', 'Your Support Ticket Was Resolved', sprintf(
            '%s marked %s as resolved. Reply on the ticket if you still need help.',
            self::staffLabel($staff),
            $ticket->ticket_number
        ));
        SafeMailer::send($ticket->user?->email, new SupportTicketUpdatedMail($ticket, $message, true));

        ActivityLog::record([
            'actor_id' => $staff->id,
            'actor_role' => $staff->role,
            'action' => 'support_ticket_resolved',
            'target_user_id' => $ticket->user_id,
            'municipality_id' => $ticket->municipality_id,
            'reference_type' => 'SUP',
            'reference_number' => $ticket->ticket_number,
            'description' => sprintf('Support ticket %s marked resolved.', $ticket->ticket_number),
        ]);

        return $ticket;
    }

    public static function canView(User $user, SupportTicket $ticket): bool
    {
        return match ($user->role) {
            'super_admin' => true,
            'lgu_admin' => $ticket->municipality_id !== null && $ticket->municipality_id === $user->municipality_id,
            default => $ticket->user_id === $user->id,
        };
    }

    /** Staff list: one LGU's municipality, or everything for the Super Admin. */
    public static function staffQuery(User $staff): Builder
    {
        return SupportTicket::with(['user:id,name,role,email,profile_picture', 'municipality:id,name', 'order:id,order_number', 'resolver:id,name'])
            ->withCount('messages')
            ->when($staff->role === 'lgu_admin', fn ($q) => $q->where('municipality_id', $staff->municipality_id))
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id');
    }

    public static function staffLabel(User $staff): string
    {
        return $staff->role === 'lgu_admin' ? 'Your LGU' : 'AbaiMarket Support';
    }

    /** The order's seller decides the municipality; otherwise the user's own. */
    private static function municipalityFor(User $user, ?Order $order): ?int
    {
        if ($order) {
            $id = $order->loadMissing('sellerProfile')->sellerProfile?->municipality_id;
            if ($id) {
                return $id;
            }
        }

        if ($user->role === 'seller') {
            return SellerProfile::where('user_id', $user->id)->value('municipality_id') ?? $user->municipality_id;
        }

        return $user->municipality_id;
    }

    private static function addMessage(SupportTicket $ticket, User $author, string $body, ?UploadedFile $attachment = null): SupportTicketMessage
    {
        return SupportTicketMessage::create([
            'support_ticket_id' => $ticket->id,
            'user_id' => $author->id,
            'author_role' => $author->role,
            'body' => $body,
            'attachment_url' => $attachment ? ImageUploader::store($attachment, 'support-attachments') : null,
        ]);
    }

    /** Everyone who can answer: the municipality's active LGU Admins and every Super Admin. */
    private static function staffRecipients(SupportTicket $ticket): Collection
    {
        return User::query()
            ->where(fn ($q) => $q
                ->where('role', 'super_admin')
                ->orWhere(fn ($q2) => $q2
                    ->where('role', 'lgu_admin')
                    ->where('status', 'active')
                    ->whereNotNull('municipality_id')
                    ->where('municipality_id', $ticket->municipality_id)))
            ->get();
    }

    private static function notifyStaff(SupportTicket $ticket, string $type, string $title, string $body): void
    {
        foreach (self::staffRecipients($ticket) as $recipient) {
            AppNotification::create(['user_id' => $recipient->id, 'type' => $type, 'title' => $title, 'body' => $body]);
        }
    }

    private static function notifyOwner(SupportTicket $ticket, string $type, string $title, string $body): void
    {
        AppNotification::create(['user_id' => $ticket->user_id, 'type' => $type, 'title' => $title, 'body' => $body]);
    }
}
