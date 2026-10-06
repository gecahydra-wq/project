<?php

namespace App\Support;

use App\Models\AppNotification;
use App\Models\Dispute;
use App\Models\LguWithdrawalRequest;
use App\Models\Order;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Filing and resolving appeals against a rejection.
 *
 * Three unrelated things can be rejected -- an order's earnings review, a
 * seller's withdrawal, an LGU's withdrawal -- and all three need the same
 * treatment: the rejected party explains, and the reviewer either accepts
 * (undoing the rejection and putting the item back in their queue) or rejects
 * the explanation (the rejection stands). Keeping that in one place is what
 * stops the three flows from drifting apart.
 *
 * Accepting is deliberately NOT the same as approving. It returns the item to
 * the reviewable state it was in before the rejection -- the reviewer still has
 * to decide it on its merits. Money never moves here.
 */
class DisputeResolution
{
    /**
     * Whether $subject is in a state that can be appealed at all.
     *
     * Only a rejection can be disputed: there is nothing to answer while an
     * item is still pending, and an approved or paid one has no grievance
     * attached to it.
     */
    public static function isRejected(Model $subject): bool
    {
        return match (true) {
            $subject instanceof Order => $subject->lgu_review_status === 'rejected',
            $subject instanceof WithdrawalRequest, $subject instanceof LguWithdrawalRequest => $subject->status === 'rejected',
            default => false,
        };
    }

    /** The reason the reviewer gave, so the appeal form can quote it back. */
    public static function rejectionReason(Model $subject): ?string
    {
        return match (true) {
            $subject instanceof Order => $subject->lgu_review_reason,
            $subject instanceof WithdrawalRequest, $subject instanceof LguWithdrawalRequest => $subject->rejection_reason,
            default => null,
        };
    }

    public static function openDisputeFor(Model $subject): ?Dispute
    {
        return $subject->morphMany(Dispute::class, 'disputable')->open()->latest()->first();
    }

    /**
     * File an appeal. Refuses a second one while the first is still open --
     * otherwise a seller could bury the reviewer in duplicates -- but allows a
     * fresh one after a rejected appeal, since they may have new information.
     */
    public static function file(Model $subject, User $actor, string $reason): Dispute
    {
        abort_unless(self::isRejected($subject), 422, 'Only a rejected item can be disputed.');
        abort_if((bool) self::openDisputeFor($subject), 422, 'You already have an open dispute for this. Wait for it to be reviewed.');

        // A rejected withdrawal holds its money only while it can still be
        // disputed: once, within WithdrawalRejection::DISPUTE_DAYS.
        if ($subject instanceof WithdrawalRequest || $subject instanceof LguWithdrawalRequest) {
            $blocker = WithdrawalRejection::disputeBlocker($subject);
            abort_if($blocker !== null, 422, (string) $blocker);
        }

        $dispute = Dispute::create([
            'disputable_type' => $subject->getMorphClass(),
            'disputable_id' => $subject->getKey(),
            'filed_by' => $actor->id,
            'reason' => $reason,
            'status' => Dispute::OPEN,
        ]);

        foreach (self::reviewersFor($subject) as $reviewer) {
            AppNotification::firstOrCreate([
                'user_id' => $reviewer->id,
                'type' => "dispute_filed:{$dispute->id}",
            ], [
                'title' => 'Dispute filed',
                'body' => sprintf('%s disputed a rejected %s and has explained their side. Review it and accept or reject the explanation.', $actor->name, self::label($subject)),
            ]);
        }

        ActivityLog::record([
            'actor_id' => $actor->id,
            'actor_role' => $actor->role,
            'action' => 'dispute_filed',
            'municipality_id' => self::municipalityFor($subject),
            'reference_type' => self::referenceType($subject),
            'reference_number' => self::referenceNumber($subject),
            'description' => sprintf('Disputed a rejected %s.', self::label($subject)),
        ]);

        return $dispute;
    }

    /**
     * Accept the explanation: undo the rejection so the item is reviewable
     * again. The reviewer still has to make the real decision afterwards --
     * this only puts it back in front of them.
     */
    public static function accept(Dispute $dispute, User $actor, ?string $note = null): Dispute
    {
        return DB::transaction(function () use ($dispute, $actor, $note) {
            abort_unless($dispute->status === Dispute::OPEN, 422, 'This dispute has already been resolved.');

            $subject = $dispute->disputable;
            abort_if(! $subject, 404, 'The disputed item no longer exists.');

            self::reopen($subject, $actor);

            $dispute->update([
                'status' => Dispute::ACCEPTED,
                'resolution_note' => $note,
                'resolved_by' => $actor->id,
                'resolved_at' => now(),
            ]);

            self::notifyFiler($dispute, $subject, 'Dispute accepted', sprintf(
                'Your explanation was accepted. The rejected %s has been reopened and is back under review.%s',
                self::label($subject),
                $note ? " Note: {$note}" : ''
            ));

            self::log($dispute, $subject, $actor, 'dispute_accepted', 'Accepted a dispute and reopened the rejected %s.');

            return $dispute->fresh();
        });
    }

    /** Reject the explanation: the original rejection stands, unchanged. */
    public static function reject(Dispute $dispute, User $actor, string $note): Dispute
    {
        abort_unless($dispute->status === Dispute::OPEN, 422, 'This dispute has already been resolved.');

        $subject = $dispute->disputable;
        abort_if(! $subject, 404, 'The disputed item no longer exists.');

        $dispute->update([
            'status' => Dispute::REJECTED,
            'resolution_note' => $note,
            'resolved_by' => $actor->id,
            'resolved_at' => now(),
        ]);

        // A withdrawal's one dispute has been decided, so the rejection is
        // final and the held amount goes back to the owner's Available Balance.
        $released = '';
        if (($subject instanceof WithdrawalRequest || $subject instanceof LguWithdrawalRequest) && $subject->status === WithdrawalRejection::ON_HOLD) {
            WithdrawalRejection::finalize($subject, $actor, 'Rejected a dispute; the withdrawal rejection is final and the amount returned to Available Balance.');
            $released = sprintf(' The ₱%s is back in your Available Balance.', number_format((float) $subject->amount, 2));
        }

        self::notifyFiler($dispute, $subject, 'Dispute rejected', sprintf(
            'Your explanation was reviewed and the original decision on your %s stands. Reason: %s%s',
            self::label($subject),
            $note,
            $released
        ));

        self::log($dispute, $subject, $actor, 'dispute_rejected', 'Rejected a dispute; the original decision on the %s stands.');

        return $dispute->fresh();
    }

    /**
     * Return the subject to the state it was in before the rejection.
     *
     * For an order this mirrors LguController::clearReviewStatus -- the payment
     * has been sitting in 'paid_held' throughout and is untouched here; only
     * approveEarnings ever releases it. For a withdrawal, 'pending' is the
     * state a fresh request starts in, so it lands back in the payout queue
     * with its rejection reason cleared.
     */
    private static function reopen(Model $subject, User $actor): void
    {
        if ($subject instanceof Order) {
            $subject->update([
                'lgu_review_status' => null,
                'lgu_review_reason' => null,
                'lgu_reviewed_at' => now(),
                'lgu_reviewed_by' => $actor->id,
            ]);

            return;
        }

        if ($subject instanceof WithdrawalRequest || $subject instanceof LguWithdrawalRequest) {
            $subject->update([
                'status' => 'pending',
                'rejection_reason' => null,
                'reviewed_at' => null,
            ]);

            return;
        }

        abort(422, 'This item cannot be reopened.');
    }

    /** Who decides this appeal. */
    private static function reviewersFor(Model $subject)
    {
        // An LGU's own withdrawal is rejected by the Super Admin, so the Super
        // Admin hears the appeal. Everything else is rejected by the seller's
        // LGU -- with the Super Admin included as the fallback reviewer for a
        // municipality whose LGU Admin is suspended or absent.
        if ($subject instanceof LguWithdrawalRequest) {
            return User::where('role', 'super_admin')->get();
        }

        $municipalityId = self::municipalityFor($subject);

        return User::where('role', 'super_admin')
            ->orWhere(fn ($q) => $q->where('role', 'lgu_admin')->where('municipality_id', $municipalityId)->where('status', '!=', 'disabled'))
            ->get();
    }

    private static function municipalityFor(Model $subject): ?int
    {
        if ($subject instanceof Order) {
            return $subject->loadMissing('sellerProfile')->sellerProfile?->municipality_id;
        }

        if ($subject instanceof WithdrawalRequest) {
            return $subject->loadMissing('sellerProfile')->sellerProfile?->municipality_id;
        }

        if ($subject instanceof LguWithdrawalRequest) {
            return $subject->municipality_id;
        }

        return null;
    }

    private static function label(Model $subject): string
    {
        return match (true) {
            $subject instanceof Order => 'earnings approval',
            $subject instanceof WithdrawalRequest, $subject instanceof LguWithdrawalRequest => 'withdrawal request',
            default => 'item',
        };
    }

    private static function referenceType(Model $subject): ?string
    {
        return $subject instanceof Order ? 'ORD' : null;
    }

    private static function referenceNumber(Model $subject): ?string
    {
        return $subject instanceof Order ? $subject->order_number : null;
    }

    private static function notifyFiler(Dispute $dispute, Model $subject, string $title, string $body): void
    {
        AppNotification::firstOrCreate([
            'user_id' => $dispute->filed_by,
            'type' => "dispute_resolved:{$dispute->id}",
        ], [
            'title' => $title,
            'body' => $body,
        ]);
    }

    private static function log(Dispute $dispute, Model $subject, User $actor, string $action, string $description): void
    {
        ActivityLog::record([
            'actor_id' => $actor->id,
            'actor_role' => $actor->role,
            'action' => $action,
            'target_user_id' => $dispute->filed_by,
            'municipality_id' => self::municipalityFor($subject),
            'reference_type' => self::referenceType($subject),
            'reference_number' => self::referenceNumber($subject),
            'description' => sprintf($description, self::label($subject)),
        ]);
    }
}
