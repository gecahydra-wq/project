<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    private const EDIT_WINDOW_MINUTES = 15;

    protected function assertBuyerSellerPair(User $a, User $b): void
    {
        $pair = [$a->role, $b->role];
        abort_unless(in_array($pair, [['buyer', 'seller'], ['seller', 'buyer']], true), 422, 'Messaging is only available between buyers and sellers.');
    }

    public function threads(Request $request)
    {
        $userId = $request->user()->id;

        $messages = Message::where('sender_id', $userId)
            ->orWhere('receiver_id', $userId)
            ->with(['sender', 'receiver'])
            ->latest()
            ->get();

        $threads = $messages
            ->groupBy(fn ($message) => $message->sender_id === $userId ? $message->receiver_id : $message->sender_id)
            ->map(function ($group) use ($userId) {
                $last = $group->first();
                $counterpart = $last->sender_id === $userId ? $last->receiver : $last->sender;

                return [
                    'user' => $counterpart?->only(['id', 'name', 'role', 'profile_picture']),
                    'last_message' => $last,
                    'unread_count' => $group->where('receiver_id', $userId)->whereNull('read_at')->count(),
                ];
            })
            ->values();

        return response()->json($threads);
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
            'user' => $user->only(['id', 'name', 'role', 'profile_picture']),
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
        $receiver = User::findOrFail($data['receiver_id']);
        $this->assertBuyerSellerPair($sender, $receiver);

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
