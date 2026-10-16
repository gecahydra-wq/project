<?php

namespace App\Support;

use App\Models\ActivityLogEntry;
use App\Models\AppNotification;
use App\Models\MockPayment;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\Settlement;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Post-completion refund requests: a buyer, having already received an order,
 * asks for their money back, and a Super Admin decides.
 *
 * This is a separate flow from cancellation refunds on purpose. A cancellation
 * refund is queued by the app the moment an order is wound down -- no person
 * decides anything (see App\Support\OrderCancellation). Here a person asks and
 * a person answers, which is why the request is its own table with its own
 * status and its own decider, and why the only thing the two flows share is
 * the money step at the very end (OrderCancellation::queueRefund).
 *
 * Three rules shape everything below:
 *
 *  - Eligibility is DERIVED, never stored. Whether an order is still inside its
 *    window is orders.completed_at plus config('orders.refund_request_window_days')
 *    -- no deadline column, no 'expired' flag. So a request that is past its
 *    deadline is still an ordinary pending request; it is only no longer
 *    creatable. That is what lets the scheduler close windows without ever
 *    touching a pending request.
 *
 *  - The amount is never taken from the client. It is the payment's own amount,
 *    the one figure a buyer cannot influence from the request form.
 *
 *  - Money is only marked refunded inside the existing, already-tested refund
 *    path. Nothing here flips a payment to 'refunded' itself.
 */
class RefundRequests
{
    public const EVIDENCE_DIRECTORY = 'refund-evidence';

    public const MAX_EVIDENCE_PHOTOS = 5;

    public const MAX_REASON_LENGTH = 2000;

    /**
     * Payment states a completed order's money can still be in when a refund is
     * approved: 'paid_held' (captured, still in escrow because the LGU has not
     * approved the earnings) or 'released' (the LGU approved, and a Settlement
     * now credits the seller). Anything else -- 'refund_pending', 'refunded',
     * 'pending', 'cancelled', 'failed' -- is not a state this flow may refund.
     */
    public const REFUNDABLE_PAYMENT_STATUSES = ['paid_held', 'released'];

    public static function windowDays(): int
    {
        return max(1, (int) config('orders.refund_request_window_days', 7));
    }

    /** The instant the request window closes for $order, or null if it never completed. */
    public static function windowClosesAt(Order $order): ?Carbon
    {
        return $order->completed_at?->copy()->addDays(self::windowDays());
    }

    /**
     * Whether a request may be filed for $order right now, and if not, why.
     *
     * Returns null when the order is requestable. Otherwise a short reason the
     * caller can turn into a 422 message: 'not_completed', 'window_closed',
     * 'already_requested', 'no_payment', 'not_refundable'. Ownership is NOT
     * checked here -- that belongs to the controller, which knows the caller.
     */
    public static function blockReason(Order $order): ?string
    {
        if ($order->status !== 'completed' || ! $order->completed_at) {
            return 'not_completed';
        }

        // Answered before the window: a buyer who already asked is told so,
        // rather than being told their window closed as if they never had one.
        if ($order->refundRequest) {
            return 'already_requested';
        }

        if (self::windowClosesAt($order)->isPast()) {
            return 'window_closed';
        }

        $payment = $order->payment;

        if (! $payment) {
            return 'no_payment';
        }

        if (! in_array($payment->status, self::REFUNDABLE_PAYMENT_STATUSES, true)) {
            return 'not_refundable';
        }

        return null;
    }

    public static function blockMessage(string $reason): string
    {
        return match ($reason) {
            'not_completed' => 'Only an order you have already received can be refunded.',
            'window_closed' => sprintf(
                'The refund window for this order has closed. You have %d days from the day it was completed to request a refund.',
                self::windowDays()
            ),
            'already_requested' => 'A refund has already been requested for this order.',
            'not_refundable' => 'This order\'s payment is not in a state that can be refunded.',
            default => 'This order cannot be refunded.',
        };
    }

    /**
     * File a request. The caller has already established that the buyer owns
     * the order; everything about whether the request is allowed is re-checked
     * here, inside a transaction, so two taps cannot produce two requests.
     *
     * @param  array<int, UploadedFile>  $photos
     */
    public static function file(Order $order, User $buyer, string $reason, array $photos = []): RefundRequest
    {
        $reason = trim($reason);

        return DB::transaction(function () use ($order, $buyer, $reason, $photos) {
            // Re-read the order with its row locked so the window and the
            // "no request yet" check are answered against the same state the
            // insert will see.
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $locked->load(['payment', 'refundRequest']);

            $block = self::blockReason($locked);

            if ($block !== null) {
                abort(422, self::blockMessage($block));
            }

            // The unique index on refund_requests.order_id is the real duplicate
            // guard; this re-check under the lock is what turns a collision into
            // a clear 422 instead of a query exception.
            if (RefundRequest::where('order_id', $locked->id)->exists()) {
                abort(422, self::blockMessage('already_requested'));
            }

            $request = RefundRequest::create([
                'order_id' => $locked->id,
                'payment_id' => $locked->payment->id,
                'buyer_id' => $buyer->id,
                'reason' => $reason,
                'status' => RefundRequest::PENDING,
            ]);

            foreach (array_values($photos) as $position => $photo) {
                $request->media()->create([
                    'type' => ImageUploader::detectMediaType($photo) ?? 'photo',
                    'title' => 'Evidence photo',
                    'url' => ImageUploader::store($photo, self::EVIDENCE_DIRECTORY.'/'.$request->id),
                    'position' => $position,
                ]);
            }

            self::logOrderEvent($locked, $buyer, 'refund_request_filed', sprintf(
                'Refund requested by the buyer for order %s (%s). Reason: %s',
                $locked->order_number,
                self::amountLabel($locked->payment),
                $reason
            ));

            $request->loadMissing('media', 'order.listing');

            self::notifySuperAdmins($request);

            return $request;
        });
    }

    /**
     * Approve a request: reverse the settlement if the money was already
     * released, then hand the payment to the existing refund path.
     *
     * The whole decision is one locked transaction. The request row is locked
     * and its status re-read before anything happens, so a second approval
     * arriving at the same moment finds a decided row and is refused rather
     * than refunding twice. The payment is locked for the same reason: a
     * payment that is already 'refund_pending' or 'refunded' is never refunded
     * again.
     */
    public static function approve(RefundRequest $request, User $admin): RefundRequest
    {
        $order = DB::transaction(function () use ($request, $admin) {
            $locked = RefundRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                abort(422, 'This refund request has already been decided.');
            }

            $order = Order::whereKey($locked->order_id)->lockForUpdate()->firstOrFail();
            $order->load('sellerProfile');

            $payment = MockPayment::whereKey($locked->payment_id)->lockForUpdate()->firstOrFail();

            if (! in_array($payment->status, self::REFUNDABLE_PAYMENT_STATUSES, true)) {
                abort(422, 'This order\'s payment is no longer in a state that can be refunded.');
            }

            // If the earnings were already released, the seller/LGU were
            // credited by an immutable Settlement, and that credit can no
            // longer stand: the buyer is getting the money back. Reversing
            // (never deleting) the settlement takes the share back out of every
            // balance the app computes.
            $reversed = null;

            if ($payment->status === 'released') {
                $reversed = self::reverseSettlement($order, $admin, sprintf(
                    'Order %s was refunded after the buyer requested a refund.',
                    $order->order_number
                ));
            }

            $locked->update([
                'status' => RefundRequest::APPROVED,
                'decided_by' => $admin->id,
                'decided_at' => now(),
                'denial_reason' => null,
            ]);

            self::logOrderEvent($order, $admin, 'refund_request_approved', sprintf(
                'Refund approved for order %s (%s).',
                $order->order_number,
                self::amountLabel($payment)
            ));

            // The money step is the same one a cancellation uses, so the
            // "never mark money as refunded unless the refund succeeded" rule
            // lives in exactly one place. In test mode it completes at once;
            // on live keys it parks in the refund queue for an admin to send,
            // because this app has no PayMongo refunds API integration.
            OrderCancellation::queueRefund(
                $payment,
                $order,
                'refund.request_approved',
                $locked->reason,
                completed: true
            );

            self::notifyBuyer(
                $locked,
                'refund_request_decided:'.$locked->id,
                'Refund request approved',
                sprintf(
                    'Your refund request for order #%s was approved. %s',
                    $order->order_number,
                    'Your payment of '.self::amountLabel($payment).' is being returned to your original payment method.'
                )
            );

            $buyer = User::find($locked->buyer_id);
            if ($buyer) {
                $refundStatus = $payment->fresh()->status;
                $buyerMessage = $refundStatus === OrderCancellation::REFUNDED
                    ? sprintf(
                        'The Super Admin approved your refund request for order #%s. Your payment of %s has been successfully refunded.',
                        $order->order_number,
                        self::amountLabel($payment)
                    )
                    : sprintf(
                        'The Super Admin approved your refund request for order #%s. Your payment of %s is now being processed back to your original payment method. We will update the refund status when it has been sent.',
                        $order->order_number,
                        self::amountLabel($payment)
                    );

                SystemMessenger::send($buyer, $buyerMessage, 'refund_request_approved');
            }

            self::markAdminNotificationsRead($locked);

            if ($reversed) {
                self::notifyReversedEarnings($order, $reversed);
            }

            return $order;
        });

        return $request->fresh(['order', 'buyer', 'decidedBy', 'media']);
    }

    /** Deny a request. A denial has to say why -- the buyer is told, and so is the log. */
    public static function deny(RefundRequest $request, User $admin, string $reason): RefundRequest
    {
        $reason = trim($reason);

        DB::transaction(function () use ($request, $admin, $reason) {
            $locked = RefundRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                abort(422, 'This refund request has already been decided.');
            }

            $locked->update([
                'status' => RefundRequest::DENIED,
                'decided_by' => $admin->id,
                'decided_at' => now(),
                'denial_reason' => $reason,
            ]);

            $order = Order::whereKey($locked->order_id)->firstOrFail();

            self::logOrderEvent($order, $admin, 'refund_request_denied', sprintf(
                'Refund denied for order %s. Reason: %s',
                $order->order_number,
                $reason
            ));

            $buyerMessage = sprintf(
                'Your refund request for order #%s was denied. Reason: %s. If you have questions about this decision, use the options below to message the Super Admin or contact Support.',
                $order->order_number,
                $reason
            );

            self::notifyBuyer(
                $locked,
                'refund_request_decided:'.$locked->id,
                'Refund request denied',
                $buyerMessage
            );

            $buyer = User::find($locked->buyer_id);
            if ($buyer) {
                SystemMessenger::send($buyer, $buyerMessage, 'refund_request_denied');
            }

            self::markAdminNotificationsRead($locked);
        });

        return $request->fresh(['order', 'buyer', 'decidedBy', 'media']);
    }

    /** Reopen a denied request so a Super Admin can review it again. */
    public static function reopenDenied(RefundRequest $request, User $admin): RefundRequest
    {
        return DB::transaction(function () use ($request, $admin) {
            $locked = RefundRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === RefundRequest::DENIED, 422, 'Only a denied refund request can be reopened.');

            $payment = MockPayment::whereKey($locked->payment_id)->lockForUpdate()->firstOrFail();
            abort_unless(
                in_array($payment->status, self::REFUNDABLE_PAYMENT_STATUSES, true),
                422,
                'This order\'s payment is no longer in a state that can be refunded.'
            );

            $locked->update([
                'status' => RefundRequest::PENDING,
                'decided_by' => null,
                'decided_at' => null,
                'denial_reason' => null,
            ]);

            $order = Order::with('buyer')->whereKey($locked->order_id)->firstOrFail();
            self::logOrderEvent($order, $admin, 'refund_request_reopened', sprintf(
                'Reopened the denied refund request for order %s for another review.',
                $order->order_number
            ));

            $body = sprintf(
                'The Super Admin reopened your refund request for order #%s. It is back under review; no refund has been issued yet.',
                $order->order_number
            );
            AppNotification::create([
                'user_id' => $locked->buyer_id,
                'type' => 'refund_request_reopened',
                'title' => 'Refund request reopened',
                'body' => $body,
            ]);

            if ($order->buyer) {
                SystemMessenger::send($order->buyer, $body, 'refund_request_reopened');
            }

            return $locked->fresh(['order', 'buyer', 'decidedBy', 'media']);
        });
    }

    /**
     * Close the window on every completed order that is past its deadline
     * without a request, and record that fact once per order.
     *
     * Deliberately one-way and read-mostly: it writes a single ActivityLog row
     * per order and nothing else. It does not create, decide, delete or
     * otherwise touch a refund request -- a request that is still pending when
     * its window closes stays pending until a person decides it, which is the
     * whole reason eligibility is derived rather than stored.
     *
     * Idempotent by the log itself: an order that already has a
     * 'refund_request_window_expired' entry is skipped, so running this every
     * day (or twice in a row) changes nothing.
     *
     * @return int the number of orders whose window was recorded as closed
     */
    public static function closeExpiredWindows(): int
    {
        $cutoff = now()->subDays(self::windowDays());

        $candidates = Order::with('sellerProfile')
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->where('completed_at', '<=', $cutoff)
            ->whereDoesntHave('refundRequest')
            ->get();

        if ($candidates->isEmpty()) {
            return 0;
        }

        $alreadyLogged = ActivityLogEntry::where('action', 'refund_request_window_expired')
            ->whereIn('reference_number', $candidates->pluck('order_number'))
            ->pluck('reference_number')
            ->all();

        $count = 0;

        foreach ($candidates as $order) {
            if (in_array($order->order_number, $alreadyLogged, true)) {
                continue;
            }

            $closesAt = self::windowClosesAt($order);

            self::logOrderEvent($order, null, 'refund_request_window_expired', sprintf(
                'The refund window for order %s closed on %s with no refund request.',
                $order->order_number,
                $closesAt?->toDateString() ?? ''
            ));

            $count++;
        }

        return $count;
    }

    /**
     * The one shape a refund request is read in, shared by the buyer's own
     * response and the Super Admin's queue so the two views can never show
     * different versions of the same request. The amount is the payment's own
     * amount -- there is no client-supplied figure anywhere in this flow.
     */
    public static function present(RefundRequest $request): array
    {
        $order = $request->order;
        $payment = $request->payment;

        return [
            'id' => $request->id,
            'status' => $request->status,
            'reason' => $request->reason,
            'amount' => (float) ($payment?->amount ?? 0),
            'payment_status' => $payment?->status,
            'order_number' => $order?->order_number,
            'order_id' => $request->order_id,
            'buyer' => $request->buyer ? [
                'id' => $request->buyer->id,
                'name' => $request->buyer->name,
                'profile_picture' => $request->buyer->profile_picture,
            ] : null,
            'seller_profile_id' => $order?->seller_profile_id,
            'hatchery_name' => $order?->sellerProfile?->hatchery_name,
            'listing_title' => $order?->listing?->title,
            'quantity' => $order?->quantity,
            'unit_price' => (float) ($order?->unit_price ?? 0),
            'completed_at' => $order?->completed_at?->toIso8601String(),
            'window_closes_at' => $order ? self::windowClosesAt($order)?->toIso8601String() : null,
            'requested_at' => $request->created_at?->toIso8601String(),
            'decided_at' => $request->decided_at?->toIso8601String(),
            'decided_by' => $request->decidedBy?->name,
            'denial_reason' => $request->denial_reason,
            'evidence' => $request->media
                ->map(fn ($media) => ['id' => $media->id, 'url' => $media->url, 'type' => $media->type])
                ->values()
                ->all(),
        ];
    }

    /**
     * Undo a settlement so the credited shares stop counting toward anybody's
     * balance.
     *
     * A seller or LGU who already withdrew their share cannot fund the refund
     * from money they no longer hold, and that does not stop the refund: the
     * reversal goes through, and the shortfall it leaves becomes a repayment
     * due that their next settled earnings cover before anything is
     * withdrawable again (SellerWallet::repaymentDue / LguWallet::repaymentDue).
     * Nothing is clamped and no negative balance is written -- available
     * balance already floors at zero, so the shortfall is stated as its own
     * figure instead of being hidden inside it.
     *
     * Returns the settlement it reversed, or null when there was nothing to
     * reverse, so the caller only tells the seller and LGU when their money
     * actually moved.
     */
    private static function reverseSettlement(Order $order, User $admin, string $reason): ?Settlement
    {
        $settlement = Settlement::where('order_id', $order->id)->lockForUpdate()->first();

        if (! $settlement || $settlement->isReversed()) {
            return null;
        }

        // settled_at is written back untouched on purpose. It is a NOT NULL
        // TIMESTAMP, and MySQL/MariaDB give such a column an implicit
        // "DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP" when
        // explicit_defaults_for_timestamp is off (XAMPP ships it off), so an
        // UPDATE that leaves the column out silently rewrites the settlement's
        // own time with the database server's clock -- in its timezone, not
        // UTC. Every date here -- the ledger, the period filters, the revenue
        // charts -- reads settled_at, so it has to survive the reversal
        // unchanged. The query builder is used because Eloquent would drop the
        // column as "not dirty" when the value is unchanged, and the implicit
        // ON UPDATE would fire anyway.
        Settlement::whereKey($settlement->id)->update([
            'reversed_at' => now(),
            'reversed_by' => $admin->id,
            'reversal_reason' => $reason,
            'settled_at' => $settlement->getRawOriginal('settled_at'),
        ]);

        $settlement->refresh();

        self::logOrderEvent($order, $admin, 'seller_earnings_reversed', sprintf(
            'Earnings for order %s reversed for a refund: seller share %s returned.',
            $order->order_number,
            number_format((float) $settlement->seller_share, 2)
        ));

        return $settlement;
    }

    private static function notifySuperAdmins(RefundRequest $request): void
    {
        $order = $request->order;

        foreach (User::where('role', 'super_admin')->pluck('id') as $adminId) {
            AppNotification::firstOrCreate([
                'user_id' => $adminId,
                'type' => "refund_request:{$request->id}",
            ], [
                'title' => 'Refund request',
                'body' => sprintf(
                    'Order #%s (%s) -- %s requested a refund. Reason: %s Review it in the Refunds queue.',
                    $order?->order_number,
                    self::amountLabel($request->payment),
                    $request->buyer?->name ?? 'The buyer',
                    $request->reason
                ),
            ]);
        }
    }

    private static function notifyBuyer(RefundRequest $request, string $type, string $title, string $body): void
    {
        AppNotification::firstOrCreate([
            'user_id' => $request->buyer_id,
            'type' => $type,
            'title' => $title,
        ], ['body' => $body]);
    }

    /**
     * Tell the side whose earnings just went back: the seller, and every LGU
     * admin of the order's municipality. The refund is why money left their
     * balance, and a balance that drops with no explanation reads like a bug --
     * all the more so when they had already withdrawn against it, which is what
     * the shortfall sentence covers.
     *
     * Only sent when a settlement was actually reversed. A payment that never
     * left escrow credited neither of them, so there is nothing to explain.
     */
    private static function notifyReversedEarnings(Order $order, Settlement $settlement): void
    {
        $seller = $order->sellerProfile;

        if ($seller) {
            AppNotification::firstOrCreate([
                'user_id' => $seller->user_id,
                'type' => "earnings_reversed:{$order->id}",
            ], [
                'title' => 'Earnings reversed for approved refund',
                'body' => sprintf(
                    'The refund for order #%s was approved, so the seller share of %s was reversed from your recorded earnings. The buyer refund is still being processed.%s',
                    $order->order_number,
                    self::money($settlement->seller_share),
                    self::shortfallNote(SellerWallet::repaymentDue($seller))
                ),
            ]);
        }

        $lguAdmins = User::where('role', 'lgu_admin')
            ->where('municipality_id', $settlement->municipality_id)
            ->pluck('id');

        foreach ($lguAdmins as $adminId) {
            AppNotification::firstOrCreate([
                'user_id' => $adminId,
                'type' => "earnings_reversed_lgu:{$order->id}",
            ], [
                'title' => 'Earnings reversed for approved refund',
                'body' => sprintf(
                    'The refund for order #%s was approved, so the LGU share of %s was reversed from municipal revenue. The buyer refund is still being processed.%s',
                    $order->order_number,
                    self::money($settlement->lgu_share),
                    self::shortfallNote(LguWallet::repaymentDue($settlement->municipality_id))
                ),
            ]);
        }
    }

    /**
     * Names what is still owed when the reversal outran the balance it came
     * out of, so a floored available balance is explained rather than merely
     * stuck at zero.
     */
    private static function shortfallNote(float $repaymentDue): string
    {
        if ($repaymentDue <= 0) {
            return '';
        }

        return sprintf(
            ' That left the balance %s short of what has already been withdrawn, and your next settled earnings cover it before anything can be withdrawn again.',
            self::money($repaymentDue)
        );
    }

    private static function money(float|string|null $value): string
    {
        return '₱'.number_format((float) $value, 2);
    }

    private static function markAdminNotificationsRead(RefundRequest $request): void
    {
        AppNotification::where('type', "refund_request:{$request->id}")
            ->where('user_id', '!=', $request->buyer_id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    private static function logOrderEvent(Order $order, ?User $actor, string $action, string $description): void
    {
        $order->loadMissing('sellerProfile');

        ActivityLog::record([
            'actor_id' => $actor?->id,
            'actor_role' => $actor?->role ?? 'system',
            'action' => $action,
            'target_user_id' => $order->buyer_id,
            'municipality_id' => $order->sellerProfile?->municipality_id,
            'reference_type' => 'ORD',
            'reference_number' => $order->order_number,
            'description' => $description,
        ]);
    }

    private static function amountLabel(?MockPayment $payment): string
    {
        return self::money($payment?->amount ?? 0);
    }
}
