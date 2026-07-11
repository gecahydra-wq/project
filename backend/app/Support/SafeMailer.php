<?php

namespace App\Support;

use Illuminate\Contracts\Mail\Mailable as MailableContract;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Every transactional email in the app goes out through here instead of a
 * bare Mail::to()->send() call. Checkout, approvals, and withdrawals must
 * never fail because SMTP is down or misconfigured -- so any exception is
 * caught and logged, never rethrown, and the caller's business action (which
 * has already happened by the time this runs) is unaffected either way.
 */
class SafeMailer
{
    /**
     * @param  string|array|null  $to  A recipient address, or null/empty to
     *                                 skip silently (e.g. a user with no
     *                                 email on file) without it counting as
     *                                 a failure worth logging.
     */
    public static function send(string|array|null $to, MailableContract $mailable): void
    {
        if (empty($to)) {
            return;
        }

        try {
            Mail::to($to)->send($mailable);
        } catch (Throwable $e) {
            Log::error('Transactional email failed to send.', [
                'mailable' => $mailable::class,
                'to' => $to,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Same guarantee as send(), for framework Notifications (e.g. Laravel's
     * built-in VerifyEmail) rather than a Mailable -- registration and
     * "resend verification email" must succeed even if the mail transport
     * is unreachable or misconfigured.
     */
    public static function notify(mixed $notifiable, Notification $notification): void
    {
        try {
            $notifiable->notify($notification);
        } catch (Throwable $e) {
            Log::error('Transactional notification failed to send.', [
                'notification' => $notification::class,
                'notifiable' => $notifiable::class,
                'id' => $notifiable->getKey() ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
