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
 * A ticket comes from the logged-in contact form. It is not real-time
 * support: staff respond when they can, by email (the response also shows on
 * the user's My Tickets). Once staff have messaged the user, the user can
 * reply to clarify as often as they like, until the ticket is closed. Staff may also leave internal notes the user never sees.
 *
 * Every ticket is shared. A seller's ticket, or any ticket about an order,
 * belongs to one municipality (the seller's) and that municipality's LGU
 * Admins and every Super Admin can see it. Buyers have no municipality --
 * they can be anywhere in Cebu -- so a buyer's ticket without an order
 * belongs to none, and every LGU Admin can see it. Whoever picks it up replies,
 * except for Super Admin-only topics (SupportTicket::CATEGORIES): those only
 * the Super Admin can answer, so the LGU can read them but is not notified.
 */
class SupportTickets
{
    public const ATTACHMENT_RULES = ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:10240'];

    /** @param  array{first_name: string, last_name: ?string, contact_email: string}  $contact */
    public static function open(User $user, array $contact, string $category, string $subject, string $body, ?Order $order = null, ?UploadedFile $attachment = null): SupportTicket
    {
        $ticket = SupportTicket::create([
            'user_id' => $user->id,
            'user_role' => $user->role,
            'first_name' => $contact['first_name'],
            'last_name' => $contact['last_name'] ?? null,
            'contact_email' => $contact['contact_email'],
            'category' => $category,
            'subject' => $subject,
            'municipality_id' => self::municipalityFor($user, $order),
            'order_id' => $order?->id,
            'status' => 'open',
            'last_activity_at' => now(),
        ]);
        $ticket->update(['ticket_number' => sprintf('SUP-%06d', $ticket->id)]);

        self::addMessage($ticket, $user, $body, false, $attachment);

        SafeMailer::send($ticket->contact_email, new SupportTicketUpdatedMail($ticket, null, 'received'));

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
     * Staff respond to a ticket. The response is emailed to the ticket's
     * contact email and shows on the user's My Tickets; the ticket is then
     * "answered". An internal note is for staff only: no email, no
     * notification, no status change.
     */
    public static function respond(SupportTicket $ticket, User $staff, string $body, bool $internal = false): SupportTicketMessage
    {
        $message = self::addMessage($ticket, $staff, $body, $internal);

        if ($internal) {
            $ticket->update(['last_activity_at' => now()]);

            return $message;
        }

        $ticket->update([
            'status' => 'answered',
            'last_activity_at' => now(),
            'resolved_by' => null,
            'resolved_at' => null,
        ]);

        self::notifyOwner($ticket, 'support_ticket_reply', 'Support Replied to Your Ticket', sprintf(
            '%s replied on %s: %s. A copy was sent to your email.',
            self::staffLabel($staff),
            $ticket->ticket_number,
            $ticket->subject
        ));
        SafeMailer::send($ticket->contact_email ?: $ticket->user?->email, new SupportTicketUpdatedMail($ticket, $message, 'reply'));

        return $message;
    }

    /** Staff close a ticket. The user opens a new one if they still need help. */
    public static function resolve(SupportTicket $ticket, User $staff, ?string $note = null): SupportTicket
    {
        $message = $note ? self::addMessage($ticket, $staff, $note) : null;

        $ticket->update([
            'status' => 'resolved',
            'resolved_by' => $staff->id,
            'resolved_at' => now(),
            'last_activity_at' => now(),
        ]);

        self::notifyOwner($ticket, 'support_ticket_resolved', 'Your Support Ticket Was Closed', sprintf(
            '%s closed %s. Open a new ticket if you still need help.',
            self::staffLabel($staff),
            $ticket->ticket_number
        ));
        SafeMailer::send($ticket->contact_email ?: $ticket->user?->email, new SupportTicketUpdatedMail($ticket, $message, 'closed'));

        ActivityLog::record([
            'actor_id' => $staff->id,
            'actor_role' => $staff->role,
            'action' => 'support_ticket_resolved',
            'target_user_id' => $ticket->user_id,
            'municipality_id' => $ticket->municipality_id,
            'reference_type' => 'SUP',
            'reference_number' => $ticket->ticket_number,
            'description' => sprintf('Support ticket %s closed.', $ticket->ticket_number),
        ]);

        return $ticket;
    }

    /**
     * The ticket's owner replies, e.g. to clarify something staff asked. Only
     * allowed once staff have messaged them (the controller checks). The
     * ticket goes back to "open" (waiting on staff) and the staff who can
     * answer it are notified.
     */
    public static function reply(SupportTicket $ticket, User $owner, string $body): SupportTicketMessage
    {
        $message = self::addMessage($ticket, $owner, $body);

        $ticket->update(['status' => 'open', 'last_activity_at' => now()]);

        self::notifyStaff($ticket, 'support_ticket_reply', 'Support Ticket Reply', sprintf(
            '%s replied on %s: %s',
            $owner->name,
            $ticket->ticket_number,
            $ticket->subject
        ));

        return $message;
    }

    /** Has staff sent the user a message (not an internal note) on this ticket? */
    public static function staffHasMessaged(SupportTicket $ticket): bool
    {
        return $ticket->messages()
            ->where('is_internal', false)
            ->whereIn('author_role', ['lgu_admin', 'super_admin'])
            ->exists();
    }

    public static function isStaff(User $user): bool
    {
        return in_array($user->role, ['lgu_admin', 'super_admin'], true);
    }

    /** Can this staff member respond to, note on, or close the ticket? */
    public static function canAnswer(User $user, SupportTicket $ticket): bool
    {
        return match ($user->role) {
            'super_admin' => true,
            'lgu_admin' => self::canView($user, $ticket) && ! $ticket->super_admin_only,
            default => false,
        };
    }

    public static function canView(User $user, SupportTicket $ticket): bool
    {
        return match ($user->role) {
            'super_admin' => true,
            'lgu_admin' => $ticket->municipality_id === null || $ticket->municipality_id === $user->municipality_id,
            default => $ticket->user_id === $user->id,
        };
    }

    /** Staff list: one LGU's municipality, or everything for the Super Admin. */
    public static function staffQuery(User $staff): Builder
    {
        return SupportTicket::with(['user:id,name,role,email,profile_picture', 'municipality:id,name', 'order:id,order_number', 'resolver:id,name'])
            ->withCount('messages')
            // An LGU sees its municipality's tickets plus buyer tickets tied to no municipality.
            ->when($staff->role === 'lgu_admin', fn ($q) => $q->where(fn ($q2) => $q2
                ->where('municipality_id', $staff->municipality_id)
                ->orWhereNull('municipality_id')))
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id');
    }

    public static function staffLabel(User $staff): string
    {
        return $staff->role === 'lgu_admin' ? 'Your LGU' : 'AbaiMarket Support';
    }

    /**
     * The order's seller decides the municipality; otherwise a seller's own.
     * A buyer's ticket without an order belongs to no municipality, so every
     * LGU sees it (a buyer's profile municipality is optional and not used).
     */
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

        return null;
    }

    private static function addMessage(SupportTicket $ticket, User $author, string $body, bool $internal = false, ?UploadedFile $attachment = null): SupportTicketMessage
    {
        return SupportTicketMessage::create([
            'support_ticket_id' => $ticket->id,
            'user_id' => $author->id,
            'author_role' => $author->role,
            'body' => $body,
            'is_internal' => $internal,
            'attachment_url' => $attachment ? ImageUploader::store($attachment, 'support-attachments') : null,
        ]);
    }

    /**
     * Everyone who can answer: every Super Admin, plus -- unless the topic is
     * Super Admin-only -- the active LGU Admins of the ticket's municipality,
     * or every active LGU Admin when it belongs to none.
     */
    private static function staffRecipients(SupportTicket $ticket): Collection
    {
        $includeLgu = ! $ticket->super_admin_only;

        return User::query()
            ->where(fn ($q) => $q
                ->where('role', 'super_admin')
                ->when($includeLgu, fn ($q1) => $q1->orWhere(fn ($q2) => $q2
                    ->where('role', 'lgu_admin')
                    ->where('status', 'active')
                    ->whereNotNull('municipality_id')
                    ->when($ticket->municipality_id !== null, fn ($q3) => $q3->where('municipality_id', $ticket->municipality_id)))))
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
