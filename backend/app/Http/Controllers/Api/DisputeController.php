<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dispute;
use App\Models\LguWithdrawalRequest;
use App\Models\Order;
use App\Models\SellerProfile;
use App\Models\WithdrawalRequest;
use App\Support\DisputeResolution;
use Illuminate\Http\Request;

/**
 * Appeals against a rejected earnings review or a rejected withdrawal.
 *
 * Filing is scoped to the party the rejection was against; reviewing is scoped
 * to whoever made it. See App\Support\DisputeResolution for what accepting one
 * actually does -- in short, it reopens the item rather than approving it.
 */
class DisputeController extends Controller
{
    /** Seller: dispute a rejected earnings review on one of their orders. */
    public function disputeEarnings(Request $request, Order $order)
    {
        $seller = SellerProfile::where('user_id', $request->user()->id)->firstOrFail();
        abort_if($order->seller_profile_id !== $seller->id, 403, 'You can only dispute your own orders.');

        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return response()->json(DisputeResolution::file($order, $request->user(), $data['reason']), 201);
    }

    /** Seller: dispute a rejected withdrawal request of their own. */
    public function disputeWithdrawal(Request $request, WithdrawalRequest $withdrawal)
    {
        $seller = SellerProfile::where('user_id', $request->user()->id)->firstOrFail();
        abort_if($withdrawal->seller_profile_id !== $seller->id, 403, 'You can only dispute your own withdrawal requests.');

        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return response()->json(DisputeResolution::file($withdrawal, $request->user(), $data['reason']), 201);
    }

    /**
     * LGU Admin: dispute a rejected withdrawal of their municipality's own
     * earnings. The Super Admin rejected it, so the Super Admin hears this.
     */
    public function disputeLguWithdrawal(Request $request, LguWithdrawalRequest $withdrawal)
    {
        abort_if($withdrawal->municipality_id !== $request->user()->municipality_id, 403, 'You can only dispute your own municipality\'s withdrawal requests.');

        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return response()->json(DisputeResolution::file($withdrawal, $request->user(), $data['reason']), 201);
    }

    /** Reviewer: every dispute they are responsible for, newest first. */
    public function index(Request $request)
    {
        $disputes = Dispute::with(['filedBy:id,name,role,profile_picture', 'resolvedBy:id,name', 'disputable'])
            ->latest()
            ->get()
            ->filter(fn (Dispute $dispute) => $this->canReview($request, $dispute))
            ->values()
            // A dispute card that names only the filer forces the reviewer to
            // go and look up what it is about. These two fields say which
            // rejection is being answered, without the UI having to know how
            // each subject type identifies itself.
            ->map(function (Dispute $dispute) {
                $subject = $dispute->disputable;

                $dispute->setAttribute('subject_label', match (true) {
                    $subject instanceof Order => 'Rejected earnings approval',
                    $subject instanceof WithdrawalRequest => 'Rejected withdrawal',
                    $subject instanceof LguWithdrawalRequest => 'Rejected LGU withdrawal',
                    default => 'Rejected item',
                });

                $dispute->setAttribute('subject_reference', match (true) {
                    $subject instanceof Order => 'Order #'.$subject->order_number,
                    $subject instanceof WithdrawalRequest,
                    $subject instanceof LguWithdrawalRequest => '₱'.number_format((float) $subject->amount, 2),
                    default => null,
                });

                // The reason the reviewer originally gave, so the appeal can be
                // read against what it is answering rather than in isolation.
                $dispute->setAttribute('subject_rejection_reason', DisputeResolution::rejectionReason($subject));

                return $dispute;
            });

        return response()->json($disputes);
    }

    public function accept(Request $request, Dispute $dispute)
    {
        abort_unless($this->canReview($request, $dispute), 403, 'You cannot review this dispute.');

        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        return response()->json(DisputeResolution::accept($dispute, $request->user(), $data['note'] ?? null));
    }

    public function reject(Request $request, Dispute $dispute)
    {
        abort_unless($this->canReview($request, $dispute), 403, 'You cannot review this dispute.');

        // A reason is required to reject, but optional to accept: telling
        // someone their appeal failed without saying why is how the original
        // rejection became disputable in the first place.
        $data = $request->validate(['note' => ['required', 'string', 'max:2000']]);

        return response()->json(DisputeResolution::reject($dispute, $request->user(), $data['note']));
    }

    /**
     * The Super Admin reviews everything -- they are the fallback for a
     * municipality with no active LGU Admin, and the only reviewer for an LGU's
     * own withdrawal. An LGU Admin reviews disputes from their own
     * municipality's sellers, and never their own municipality's withdrawal
     * (they filed it).
     */
    private function canReview(Request $request, Dispute $dispute): bool
    {
        $user = $request->user();

        if ($user->role === 'super_admin') {
            return true;
        }

        if ($user->role !== 'lgu_admin' || $dispute->disputable instanceof LguWithdrawalRequest) {
            return false;
        }

        $subject = $dispute->disputable;

        if ($subject instanceof Order) {
            return $subject->loadMissing('sellerProfile')->sellerProfile?->municipality_id === $user->municipality_id;
        }

        if ($subject instanceof WithdrawalRequest) {
            return $subject->loadMissing('sellerProfile')->sellerProfile?->municipality_id === $user->municipality_id;
        }

        return false;
    }
}
