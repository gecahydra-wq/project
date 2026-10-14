<?php

namespace App\Support;

use App\Http\Controllers\Api\MessageController;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Sender identity for every automatic in-app message (auto-completed orders
 * and payouts, out-for-delivery notices), so they never appear to come from
 * a real person's Super Admin account.
 *
 * The account has no usable password, the login endpoints refuse role
 * 'system', and every user-facing list, count and contact picker excludes
 * it. Replying to a system thread is rejected too
 * (MessageController::store) -- the UI offers "Message the Super Admin" and
 * "Contact Support" buttons instead.
 */
class SystemMessenger
{
    public const EMAIL = 'system@abaimarket.com';

    /** The app logo, shown as the system account's avatar. */
    public const AVATAR = '/logo-mark.png';

    /**
     * The one system account. Created on the fly if it is ever missing (a
     * wiped database, a seeder run on an older schema), so sending a message
     * can never fail on a dangling row -- the migration that first inserted
     * it uses this same firstOrCreate, so neither can duplicate the other.
     */
    public static function user(): User
    {
        return User::firstOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => 'AbaiMarket',
                'password' => Hash::make(Str::random(64)),
                'role' => 'system',
                'status' => 'active',
                'profile_picture' => self::AVATAR,
                'email_verified_at' => now(),
            ]
        );
    }

    /**
     * One automatic message to $receiver, with the same bell notification a
     * normal message gets (one per conversation -- see
     * MessageController::notifyReceiver).
     *
     * @param  array<string, mixed>  $extra  Extra message columns, e.g. withdrawal_type/withdrawal_id.
     */
    public static function send(User $receiver, string $body, string $type, array $extra = []): Message
    {
        $sender = self::user();

        $message = Message::create([
            'sender_id' => $sender->id,
            'receiver_id' => $receiver->id,
            'body' => $body,
            'message_type' => $type,
        ] + $extra);

        MessageController::notifyReceiver($sender, $receiver, $body);

        return $message;
    }
}
