<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    private const EDIT_WINDOW_MINUTES = 15;

    /**
     * Which role pairs may message each other, direction-agnostic (a reply
     * swaps sender/receiver through this same check). LGU admins are further
     * restricted to their own municipality against buyers/sellers -- Super
     * Admin is exempt from that restriction entirely.
     */
    private const ALLOWED_ROLE_PAIRS = [
        ['buyer', 'seller'],
        ['buyer', 'lgu_admin'],
        ['lgu_admin', 'seller'],
        ['buyer', 'super_admin'],
        ['seller', 'super_admin'],
        ['lgu_admin', 'super_admin'],
    ];

    protected function assertCanMessage(User $sender, User $receiver): void
    {
        $pair = [$sender->role, $receiver->role];
        sort($pair);
        abort_unless(in_array($pair, self::ALLOWED_ROLE_PAIRS, true), 422, 'You are not allowed to message this user.');

        $lguParty = $sender->role === 'lgu_admin' ? $sender : ($receiver->role === 'lgu_admin' ? $receiver : null);
        if ($lguParty) {
            $otherParty = $lguParty->id === $sender->id ? $receiver : $sender;
            abort_unless(
                $otherParty->role === 'super_admin' || $otherParty->municipality_id === $lguParty->municipality_id,
                403,
                'LGU admins can only message users within their own municipality.'
            );
        }
    }

    public function threads(Request $request)
    {
        $userId = $request->user()->id;

        $messages = Message::where('sender_id', $userId)
            ->orWhere('receiver_id', $userId)
            ->with(['sender', 'receiver'])
            ->latest()
            ->get();

        $grouped = $messages->groupBy(fn ($message) => $message->sender_id === $userId ? $message->receiver_id : $message->sender_id);

        // Resolve every seller counterpart's hatchery profile id in ONE query
        // rather than one per thread -- see counterpartPayload() for why the
        // id is needed at all.
        $counterparts = $grouped->map(fn ($group) => $group->first()->sender_id === $userId ? $group->first()->receiver : $group->first()->sender);
        $sellerProfileIds = self::sellerProfileIdsFor($counterparts->filter());

        $threads = $grouped
            ->map(function ($group) use ($userId, $sellerProfileIds) {
                $last = $group->first();
                $counterpart = $last->sender_id === $userId ? $last->receiver : $last->sender;

                return [
                    'user' => self::counterpartPayload($counterpart, $sellerProfileIds),
                    'last_message' => $last,
                    'unread_count' => $group->where('receiver_id', $userId)->whereNull('read_at')->count(),
                ];
            })
            ->values();

        return response()->json($threads);
    }

    /**
     * The person on the other side of a conversation, as the messages UI needs
     * them. A seller counterpart also carries seller_profile_id: their public
     * hatchery page is addressed by seller_profiles.id, not users.id, so
     * without it the buyer's "View Profile" button has no route to link to.
     *
     * @param  array<int,int>  $sellerProfileIds  user_id => seller_profile_id
     */
    private static function counterpartPayload(?User $user, array $sellerProfileIds = []): ?array
    {
        if (! $user) {
            return null;
        }

        $payload = $user->only(['id', 'name', 'role', 'profile_picture']);

        if ($user->role === 'seller') {
            $payload['seller_profile_id'] = $sellerProfileIds[$user->id]
                ?? SellerProfile::where('user_id', $user->id)->value('id');
        }

        return $payload;
    }

    /** @return array<int,int> user_id => seller_profile_id */
    private static function sellerProfileIdsFor($users): array
    {
        $sellerUserIds = collect($users)->where('role', 'seller')->pluck('id');

        if ($sellerUserIds->isEmpty()) {
            return [];
        }

        return SellerProfile::whereIn('user_id', $sellerUserIds)
            ->pluck('id', 'user_id')
            ->all();
    }

    public function thread(Request $request, User $user)
    {
        $userId = $request->user()->id;

        $messages = Message::where(function ($q) use ($userId, $user) {
            $q->where('sender_id', $userId)->where('receiver_id', $user->id);
        })->orWhere(function ($q) use ($userId, $user) {
            $q->where('sender_id', $user->id)->where('receiver_id', $userId);
        })->oldest()->get();

        return response()->json([
            'user' => self::counterpartPayload($user),
            'messages' => $messages,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'receiver_id' => ['required', 'exists:users,id'],
            'body' => ['required', 'string'],
        ]);

        $sender = $request->user();
        abort_if($sender->role === 'buyer' && $sender->status === 'suspended', 403, 'Your account has been suspended and cannot send messages. Contact support for assistance.');

        $receiver = User::findOrFail($data['receiver_id']);
        $this->assertCanMessage($sender, $receiver);

        $message = Message::create([
            'sender_id' => $sender->id,
            'receiver_id' => $receiver->id,
            'body' => $data['body'],
        ]);

        return response()->json($message->load(['sender', 'receiver']), 201);
    }

    public function markThreadRead(Request $request, User $user)
    {
        Message::where('sender_id', $user->id)
            ->where('receiver_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'Marked as read.']);
    }

    public function update(Request $request, Message $message)
    {
        if ($message->sender_id !== $request->user()->id) {
            return response()->json(['message' => 'You can only edit your own messages.'], 403);
        }

        if ($message->deleted_at) {
            return response()->json(['message' => 'This message has been deleted and can no longer be edited.'], 422);
        }

        if ($message->created_at->diffInMinutes(now()) > self::EDIT_WINDOW_MINUTES) {
            return response()->json(['message' => 'Messages can only be edited within '.self::EDIT_WINDOW_MINUTES.' minutes of sending.'], 422);
        }

        $data = $request->validate([
            'body' => ['required', 'string'],
        ]);

        $message->update([
            'body' => $data['body'],
            'edited_at' => now(),
        ]);

        return response()->json($message->fresh(['sender', 'receiver']));
    }

    public function destroy(Request $request, Message $message)
    {
        if ($message->sender_id !== $request->user()->id) {
            return response()->json(['message' => 'You can only delete your own messages.'], 403);
        }

        if ($message->deleted_at) {
            return response()->json(['message' => 'This message has already been deleted.'], 422);
        }

        $message->update([
            'body' => 'This message was deleted.',
            'deleted_at' => now(),
        ]);

        return response()->json($message->fresh(['sender', 'receiver']));
    }
}
