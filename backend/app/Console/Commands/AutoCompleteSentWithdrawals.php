<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\MessageController;
use App\Models\LguWithdrawalRequest;
use App\Models\Message;
use App\Models\WithdrawalRequest;
use App\Support\WithdrawalNotifications;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AutoCompleteSentWithdrawals extends Command
{
    protected $signature = 'withdrawals:auto-complete-sent';

    protected $description = 'Complete sent seller and LGU withdrawals after the receipt confirmation window expires';

    public function handle(): int
    {
        $minutes = max(1, (int) config('payouts.auto_complete_after_minutes', 1440));
        $cutoff = Carbon::now()->subMinutes($minutes);

        $sellerIds = WithdrawalRequest::where('status', 'sent')
            ->whereNull('recipient_confirmed_at')
            ->where('sent_at', '<=', $cutoff)
            ->pluck('id');
        $lguIds = LguWithdrawalRequest::where('status', 'sent')
            ->whereNull('recipient_confirmed_at')
            ->where('sent_at', '<=', $cutoff)
            ->pluck('id');

        $completed = 0;
        foreach ($sellerIds as $id) {
            $completed += $this->completeSeller((int) $id, $cutoff) ? 1 : 0;
        }
        foreach ($lguIds as $id) {
            $completed += $this->completeLgu((int) $id, $cutoff) ? 1 : 0;
        }

        $this->info("Automatically completed {$completed} withdrawal(s) after {$minutes} minute(s) without a receipt confirmation.");

        return self::SUCCESS;
    }

    private function completeSeller(int $id, Carbon $cutoff): bool
    {
        $recipient = null;
        $sender = null;
        $withdrawal = DB::transaction(function () use ($id, $cutoff, &$recipient, &$sender) {
            $request = WithdrawalRequest::with(['sellerProfile.user', 'sentBy'])
                ->lockForUpdate()
                ->find($id);

            if (! $request || $request->status !== 'sent' || $request->recipient_confirmed_at || $request->sent_at?->gt($cutoff)) {
                return null;
            }

            $recipient = $request->sellerProfile?->user;
            $sender = $request->sentBy;
            if (! $recipient) {
                return null;
            }

            $completedAt = Carbon::now();
            $request->update(['status' => 'paid', 'paid_at' => $completedAt, 'reviewed_at' => $completedAt, 'auto_completed_at' => $completedAt]);
            $body = sprintf(
                'Our records show your withdrawal payout of ₱%s was sent. It was marked paid after the %d-minute receipt confirmation window expired. If you have not received it, reply here to message the Super Admin or contact Support.',
                number_format($request->net_amount, 2),
                max(1, (int) config('payouts.auto_complete_after_minutes', 1440))
            );
            if ($sender) {
                Message::create([
                    'sender_id' => $sender->id,
                    'receiver_id' => $recipient->id,
                    'body' => $body,
                    'message_type' => 'payout_auto_completed',
                    'withdrawal_type' => 'seller',
                    'withdrawal_id' => $request->id,
                ]);
                MessageController::notifyReceiver($sender, $recipient, $body);
            }
            return $request->fresh();
        });

        if (! $withdrawal) {
            return false;
        }

        WithdrawalNotifications::sellerConfirmedReceived($withdrawal, automatic: true);

        return true;
    }

    private function completeLgu(int $id, Carbon $cutoff): bool
    {
        $recipient = null;
        $sender = null;
        $withdrawal = DB::transaction(function () use ($id, $cutoff, &$recipient, &$sender) {
            $request = LguWithdrawalRequest::with(['municipality', 'requestedBy', 'sentBy'])
                ->lockForUpdate()
                ->find($id);

            if (! $request || $request->status !== 'sent' || $request->recipient_confirmed_at || $request->sent_at?->gt($cutoff)) {
                return null;
            }

            $recipient = $request->requestedBy;
            $sender = $request->sentBy;
            if (! $recipient) {
                return null;
            }

            $completedAt = Carbon::now();
            $request->update(['status' => 'paid', 'paid_at' => $completedAt, 'reviewed_at' => $completedAt, 'auto_completed_at' => $completedAt]);
            $body = sprintf(
                'Our records show your municipality\'s withdrawal payout of ₱%s was sent. It was marked paid after the %d-minute receipt confirmation window expired. If your municipality has not received it, reply here to message the Super Admin or contact Support.',
                number_format((float) $request->amount, 2),
                max(1, (int) config('payouts.auto_complete_after_minutes', 1440))
            );
            if ($sender) {
                Message::create([
                    'sender_id' => $sender->id,
                    'receiver_id' => $recipient->id,
                    'body' => $body,
                    'message_type' => 'payout_auto_completed',
                    'withdrawal_type' => 'lgu',
                    'withdrawal_id' => $request->id,
                ]);
                MessageController::notifyReceiver($sender, $recipient, $body);
            }
            return $request->fresh();
        });

        if (! $withdrawal) {
            return false;
        }

        WithdrawalNotifications::lguConfirmedReceived($withdrawal, automatic: true);

        return true;
    }
}
