<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Support\ImageUploader;
use App\Support\SupportTickets;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Help & Support tickets. Buyers and Sellers open them and follow their own;
 * the LGU Admin (own municipality) and the Super Admin (everything) can both
 * answer them -- whoever picks one up. See App\Support\SupportTickets.
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
                ->withCount('messages')
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

        $ticket = SupportTickets::open($user, $data['category'], $data['subject'], $data['body'], $order, $request->file('attachment'));

        return response()->json($this->detail($ticket), 201);
    }

    /** One ticket and its thread -- for its owner, or staff who can see it. */
    public function show(Request $request, SupportTicket $ticket)
    {
        abort_unless(SupportTickets::canView($request->user(), $ticket), 403, 'You cannot view this ticket.');

        return response()->json($this->detail($ticket));
    }

    public function reply(Request $request, SupportTicket $ticket)
    {
        $user = $request->user();
        abort_unless(SupportTickets::canView($user, $ticket), 403, 'You cannot reply to this ticket.');

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'attachment' => SupportTickets::ATTACHMENT_RULES,
        ]);

        SupportTickets::reply($ticket, $user, $data['body'], $request->file('attachment'));

        return response()->json($this->detail($ticket->fresh()), 201);
    }

    /** Edit your own message, within the same window as the Messages tab. */
    public function updateMessage(Request $request, SupportTicket $ticket, SupportTicketMessage $message)
    {
        $this->authorizeOwnMessage($request, $ticket, $message, 'edit');

        abort_if(
            $message->created_at->diffInMinutes(now()) > SupportTicketMessage::EDIT_WINDOW_MINUTES,
            422,
            'Messages can only be edited within '.SupportTicketMessage::EDIT_WINDOW_MINUTES.' minutes of sending.'
        );

        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        $message->update(['body' => $data['body'], 'edited_at' => now()]);

        return response()->json($this->detail($ticket->fresh()));
    }

    /** Delete your own message. Blanked in place, and its screenshot removed. */
    public function destroyMessage(Request $request, SupportTicket $ticket, SupportTicketMessage $message)
    {
        $this->authorizeOwnMessage($request, $ticket, $message, 'delete');

        ImageUploader::delete($message->attachment_url);
        $message->update(['body' => 'This message was deleted.', 'attachment_url' => null, 'deleted_at' => now()]);

        return response()->json($this->detail($ticket->fresh()));
    }

    /** LGU Admin / Super Admin: the tickets they answer. */
    public function staffIndex(Request $request)
    {
        return response()->json(SupportTickets::staffQuery($request->user())->get());
    }

    public function resolve(Request $request, SupportTicket $ticket)
    {
        $user = $request->user();
        abort_unless(SupportTickets::canView($user, $ticket), 403, 'You cannot resolve this ticket.');
        abort_if($ticket->status === 'resolved', 422, 'This ticket is already resolved.');

        $data = $request->validate(['note' => ['nullable', 'string', 'max:5000']]);

        SupportTickets::resolve($ticket, $user, $data['note'] ?? null);

        return response()->json($this->detail($ticket->fresh()));
    }

    private function detail(SupportTicket $ticket): SupportTicket
    {
        return $ticket->load([
            'user:id,name,role,email,profile_picture',
            'municipality:id,name',
            'order:id,order_number',
            'resolver:id,name',
            'messages.author:id,name,role,profile_picture',
        ]);
    }

    private function authorizeOwnMessage(Request $request, SupportTicket $ticket, SupportTicketMessage $message, string $verb): void
    {
        abort_unless($message->support_ticket_id === $ticket->id, 404);
        abort_unless(SupportTickets::canView($request->user(), $ticket), 403, "You cannot {$verb} messages on this ticket.");
        abort_unless($message->user_id === $request->user()->id, 403, "You can only {$verb} your own messages.");
        abort_if($message->deleted_at !== null, 422, 'This message has been deleted.');
    }

    private function ownsOrder(Request $request, Order $order): bool
    {
        $user = $request->user();

        return $user->role === 'buyer'
            ? $order->buyer_id === $user->id
            : $order->sellerProfile?->user_id === $user->id;
    }
}
