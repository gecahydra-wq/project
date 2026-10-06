<?php

namespace App\Support;

use App\Models\AppNotification;
use App\Models\Dispute;
use App\Models\LguWithdrawalRequest;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * What a rejected withdrawal does with its money.
 *
 * A rejection is not the last word -- the seller (or LGU) may dispute it, and
 * an accepted dispute puts the very same request back to 'pending'. If the
 * rejected amount went straight back to Available, the owner could request it
 * again in the meantime, and an accepted dispute would then leave two pending
 * requests for one amount. So a rejected request keeps its money ON HOLD
 * ('rejected', still counted as reserved by SellerWallet/LguWallet) until the
 * rejection is FINAL ('rejected_final', money back in Available), which
 * happens when:
 *   - the owner accepts the rejection (acceptByOwner),
 *   - their one dispute against it is rejected (DisputeResolution::reject), or
 *   - DISPUTE_DAYS pass with no dispute filed (finalizeExpired, scheduled).
 */
class WithdrawalRejection
{
    public const ON_HOLD = 'rejected';

    public const FINAL = 'rejected_final';

    public const DISPUTE_DAYS = 7;

    /** When the owner can no longer dispute this rejection, or null if it is not on hold. */
    public static function disputeDeadline(Model $withdrawal): ?Carbon
    {
        if ($withdrawal->status !== self::ON_HOLD || ! $withdrawal->reviewed_at) {
            return null;
        }

        return $withdrawal->reviewed_at->copy()->addDays(self::DISPUTE_DAYS);
    }

    /**
     * Disputes filed against the CURRENT rejection. A reopened request that
     * is rejected again is a new decision, so earlier disputes do not count.
     */
    private static function disputesOnThisRejection(Model $withdrawal)
    {
        return Dispute::where('disputable_type', $withdrawal->getMorphClass())
            ->where('disputable_id', $withdrawal->getKey())
            ->when($withdrawal->reviewed_at, fn ($q) => $q->where('created_at', '>=', $withdrawal->reviewed_at));
    }

    /** Null when a dispute may be filed now, otherwise the reason it may not. */
    public static function disputeBlocker(Model $withdrawal): ?string
    {
        if ($withdrawal->status !== self::ON_HOLD) {
            return 'Only a rejected withdrawal that is still on hold can be disputed.';
        }

        if (self::disputesOnThisRejection($withdrawal)->exists()) {
            return 'You have already disputed this rejection. Each rejection can be disputed once.';
        }

        if (now()->greaterThan(self::disputeDeadline($withdrawal))) {
            return 'The '.self::DISPUTE_DAYS.'-day window to dispute this rejection has passed.';
        }

        return null;
    }

    /** Whether the owner may file a dispute against this rejection right now. */
    public static function canDispute(Model $withdrawal): bool
    {
        return $withdrawal->status === self::ON_HOLD && self::disputeBlocker($withdrawal) === null;
    }

    /** Whether a dispute on this rejection is waiting for a decision. */
    public static function hasOpenDispute(Model $withdrawal): bool
    {
        return $withdrawal->status === self::ON_HOLD
            && self::disputesOnThisRejection($withdrawal)->where('status', Dispute::OPEN)->exists();
    }

    /** The owner agrees with the rejection, so the held money is released now. */
    public static function acceptByOwner(Model $withdrawal, User $owner): Model
    {
        abort_unless($withdrawal->status === self::ON_HOLD, 422, 'Only a rejected withdrawal that is still on hold can be accepted.');
        abort_if(
            self::hasOpenDispute($withdrawal),
            422,
            'You have an open dispute on this rejection. Wait for it to be decided.'
        );

        self::finalize($withdrawal, $owner, 'Accepted the rejection of their withdrawal request; the amount returned to Available Balance.');

        return $withdrawal->fresh();
    }

    /**
     * Make the rejection final and release the money. $actor is null when the
     * dispute window simply ran out, so the log never names a person who did
     * not act.
     */
    public static function finalize(Model $withdrawal, ?User $actor, string $description, bool $notifyOwner = false): void
    {
        $withdrawal->update(['status' => self::FINAL]);

        ActivityLog::record([
            'actor_id' => $actor?->id,
            'actor_role' => $actor?->role,
            'action' => 'withdrawal_rejection_final',
            'municipality_id' => self::municipalityId($withdrawal),
            'reference_type' => $withdrawal instanceof LguWithdrawalRequest ? 'LGU' : 'PAY',
            'reference_number' => ($withdrawal instanceof LguWithdrawalRequest ? 'LGU-' : 'PAY-').$withdrawal->getKey(),
            'description' => $description,
        ]);

        if ($notifyOwner && ($ownerId = self::ownerId($withdrawal))) {
            AppNotification::firstOrCreate([
                'user_id' => $ownerId,
                'type' => ($withdrawal instanceof LguWithdrawalRequest ? 'lgu_withdrawal_released:' : 'withdrawal_released:').$withdrawal->getKey(),
            ], [
                'title' => 'Rejected withdrawal released',
                'body' => sprintf(
                    'No dispute was filed within %d days, so the rejection of your ₱%s withdrawal request is now final. The amount is back in your Available Balance and can be requested again.',
                    self::DISPUTE_DAYS,
                    number_format((float) $withdrawal->amount, 2)
                ),
            ]);
        }
    }

    /** Scheduled: release every hold whose dispute window has closed without a dispute. */
    public static function finalizeExpired(): int
    {
        $cutoff = now()->subDays(self::DISPUTE_DAYS);
        $count = 0;

        foreach ([WithdrawalRequest::class, LguWithdrawalRequest::class] as $model) {
            $model::where('status', self::ON_HOLD)
                ->where('reviewed_at', '<=', $cutoff)
                ->each(function (Model $withdrawal) use (&$count) {
                    // A dispute filed in time is decided by a person, never by the clock.
                    if (self::disputesOnThisRejection($withdrawal)->exists()) {
                        return;
                    }

                    self::finalize($withdrawal, null, 'The dispute window closed with no dispute; the rejected withdrawal amount returned to Available Balance.', true);
                    $count++;
                });
        }

        return $count;
    }

    private static function ownerId(Model $withdrawal): ?int
    {
        return $withdrawal instanceof LguWithdrawalRequest
            ? $withdrawal->requested_by
            : $withdrawal->loadMissing('sellerProfile')->sellerProfile?->user_id;
    }

    private static function municipalityId(Model $withdrawal): ?int
    {
        return $withdrawal instanceof LguWithdrawalRequest
            ? $withdrawal->municipality_id
            : $withdrawal->loadMissing('sellerProfile')->sellerProfile?->municipality_id;
    }
}
