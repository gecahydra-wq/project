<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\SupportTicket;
use App\Support\SupportTickets;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Help & Support tickets. Buyers and Sellers send one through the contact
 * form, read their past tickets and the staff responses, and reply to clarify
 * until a ticket is closed; the LGU Admin (own municipality) and the Super
 * Admin (everything) can both answer them -- whoever picks one up. Responses
 * are emailed; it is not real-time support, and internal notes are never
 * shown to the user. See App\Support\SupportTickets.
 */
class SupportTicketController extends Controller
{
    /** The topics the caller may pick from. */
    public function categories(Request $request)
    {
        return response()->json(['categories' => SupportTicket::categoriesFor($request->user()->role)]);
    }

    /** Buyer/Seller: their own tickets, most recently active first. */
    public function mine(Request $request)
    {
        return response()->json(
            SupportTicket::with(['order:id,order_number'])
                ->withCount(['messages' => fn ($q) => $q->where('is_internal', false)])
                ->where('user_id', $request->user()->id)
                ->orderByDesc('last_activity_at')
                ->orderByDesc('id')
                ->get()
        );
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $allowed = collect(SupportTicket::categoriesFor($user->role))->pluck('value')->all();

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'contact_email' => ['required', 'email', 'max:255'],
            'category' => ['required', 'string', Rule::in($allowed)],
            'subject' => ['required', 'string', 'min:5', 'max:150'],
            'body' => ['required', 'string', 'min:10', 'max:5000'],
            'order_number' => ['nullable', 'string', 'max:50'],
            'attachment' => SupportTickets::ATTACHMENT_RULES,
        ]);

        // An order can only be attached when the user was part of it --
        // otherwise a ticket could point at someone else's transaction.
        $order = null;
        if (! empty($data['order_number'])) {
            $order = Order::with('sellerProfile')->where('order_number', trim($data['order_number']))->first();
            if (! $order || ! $this->ownsOrder($request, $order)) {
                return response()->json([
                    'message' => 'We could not find that order on your account.',
                    'errors' => ['order_number' => ['We could not find that order on your account.']],
                ], 422);
            }
        }

        $contact = [
            'first_name' => trim($data['first_name']),
            'last_name' => isset($data['last_name']) ? trim($data['last_name']) : null,
            'contact_email' => trim($data['contact_email']),
        ];

        $ticket = SupportTickets::open($user, $contact, $data['category'], $data['subject'], $data['body'], $order, $request->file('attachment'));

        return response()->json($this->detail($ticket, $user), 201);
    }

    /** One ticket and its thread -- for its owner, or staff who can see it. */
    public function show(Request $request, SupportTicket $ticket)
    {
        abort_unless(SupportTickets::canView($request->user(), $ticket), 403, 'You cannot view this ticket.');

        return response()->json($this->detail($ticket, $request->user()));
    }

    /**
     * The ticket's owner replies to clarify something. Support has to message
     * them first (an internal note does not count); after that they can reply
     * as often as they like until the ticket is closed.
     */
    public function reply(Request $request, SupportTicket $ticket)
    {
        $user = $request->user();
        abort_unless($ticket->user_id === $user->id, 403, 'You can only reply to your own tickets.');
        abort_if($ticket->status === 'resolved', 422, 'This ticket is closed. Send a new ticket if you still need help.');
        abort_unless(SupportTickets::staffHasMessaged($ticket), 422, 'You can reply once support has messaged you on this ticket.');

        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        SupportTickets::reply($ticket, $user, $data['body']);

        return response()->json($this->detail($ticket->fresh(), $user), 201);
    }

    /**
     * LGU Admin / Super Admin: respond to the user (emailed to them) or, with
     * `internal`, leave a note only staff can see.
     */
    public function respond(Request $request, SupportTicket $ticket)
    {
        $user = $request->user();
        abort_unless(SupportTickets::canAnswer($user, $ticket), 403, $this->cannotAnswerMessage($ticket));
        abort_if($ticket->status === 'resolved', 422, 'This ticket is closed. It can no longer be replied to.');

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'internal' => ['sometimes', 'boolean'],
        ]);

        SupportTickets::respond($ticket, $user, $data['body'], (bool) ($data['internal'] ?? false));

        return response()->json($this->detail($ticket->fresh(), $user), 201);
    }

    /** LGU Admin / Super Admin: the tickets they answer. */
    public function staffIndex(Request $request)
    {
        return response()->json(SupportTickets::staffQuery($request->user())->get());
    }

    public function resolve(Request $request, SupportTicket $ticket)
    {
        $user = $request->user();
        abort_unless(SupportTickets::canAnswer($user, $ticket), 403, $this->cannotAnswerMessage($ticket));
        abort_if($ticket->status === 'resolved', 422, 'This ticket is already resolved.');

        $data = $request->validate(['note' => ['nullable', 'string', 'max:5000']]);

        SupportTickets::resolve($ticket, $user, $data['note'] ?? null);

        return response()->json($this->detail($ticket->fresh(), $user));
    }

    /** The ticket with its messages. Internal notes are left out for the ticket's owner. */
    private function detail(SupportTicket $ticket, $viewer): SupportTicket
    {
        $staff = SupportTickets::isStaff($viewer);

        return $ticket->load([
            'user:id,name,role,email,profile_picture',
            'municipality:id,name',
            'order:id,order_number',
            'resolver:id,name',
            'messages' => fn ($q) => $q->when(! $staff, fn ($q2) => $q2->where('is_internal', false)),
            'messages.author:id,name,role,profile_picture',
        ]);
    }

    private function cannotAnswerMessage(SupportTicket $ticket): string
    {
        return $ticket->super_admin_only
            ? 'Only the Super Admin can answer '.SupportTicket::CATEGORIES[$ticket->category]['label'].' tickets.'
            : 'You cannot answer this ticket.';
    }

    private function ownsOrder(Request $request, Order $order): bool
    {
        $user = $request->user();

        return $user->role === 'buyer'
            ? $order->buyer_id === $user->id
            : $order->sellerProfile?->user_id === $user->id;
    }
}
