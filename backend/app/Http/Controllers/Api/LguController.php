<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\ListingApprovedMail;
use App\Mail\ListingRejectedMail;
use App\Mail\SellerEarningsApprovedMail;
use App\Models\AppNotification;
use App\Models\BuyerRating;
use App\Models\FingerlingListing;
use App\Models\LguWithdrawalRequest;
use App\Models\MockPayment;
use App\Models\Order;
use App\Models\SellerNotice;
use App\Models\SellerProfile;
use App\Models\Review;
use App\Models\UserReport;
use App\Models\Settlement;
use App\Models\User;
use App\Support\AccountModeration;
use App\Support\ActivityLog;
use App\Support\CommissionCalculator;
use App\Support\ImageUploader;
use App\Support\ListingModeration;
use App\Support\LguWallet;
use App\Support\OrderTransactionPresenter;
use App\Support\ReviewModeration;
use App\Support\RevenueReport;
use App\Support\SafeMailer;
use App\Support\SellerApproval;
use App\Support\UserReports;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LguController extends Controller
{
    public function dashboard(Request $request)
    {
        $municipalityId = $request->user()->municipality_id;
        $sellerQuery = SellerProfile::query()->when($municipalityId, fn ($q) => $q->where('municipality_id', $municipalityId));
        $listingQuery = FingerlingListing::query()->when($municipalityId, fn ($q) => $q->where('municipality_id', $municipalityId));

        return response()->json([
            'registered_sellers' => $sellerQuery->count(),
            'active_listings' => (clone $listingQuery)->where('approval_status', 'approved')->count(),
            'pending_approvals' => (clone $listingQuery)->where('approval_status', 'pending')->with(['sellerProfile', 'municipality'])->get(),
            // Seller registrations awaiting review in this municipality
            // (App\Support\SellerApproval).
            'pending_seller_registrations' => (clone $sellerQuery)->where('approval_status', SellerApproval::PENDING)->count(),
            // Open complaints and Notices to Explain for this municipality.
            'open_user_reports' => UserReport::where('municipality_id', $municipalityId)
                ->whereNotIn('status', UserReport::CLOSED_STATUSES)
                ->count(),
            'open_seller_notices' => SellerNotice::where('municipality_id', $municipalityId)
                ->whereIn('status', SellerNotice::OPEN_STATUSES)
                ->count(),
            'notifications' => AppNotification::where('user_id', $request->user()->id)->whereNull('read_at')->latest()->get(),
            // Municipality Revenue -- the LGU's own settled share only, never
            // the platform's cut or another municipality's. Includes
            // available_balance/total_withdrawn from App\Support\LguWallet;
            // see LguController::wallet() for the full LGU Wallet page.
            'municipality_revenue' => $municipalityId ? RevenueReport::municipalityCards($municipalityId) : null,
        ]);
    }

    public function markNotificationRead(Request $request, AppNotification $notification)
    {
        if ($notification->user_id !== $request->user()->id) {
            return response()->json(['message' => 'You can only update your own notifications.'], 403);
        }

        $notification->update(['read_at' => now()]);

        return response()->json($notification);
    }

    /**
     * Profile picture management for the LGU Admin -- the only editable part of
     * their profile (they have no public profile info to maintain, unlike a
     * seller). Mirrors BuyerController/SellerController picture handling.
     */
    public function uploadProfilePicture(Request $request)
    {
        $request->validate(['photo' => array_merge(['required'], ImageUploader::validationRules())]);

        $user = $request->user();
        ImageUploader::delete($user->profile_picture);
        $user->update(['profile_picture' => ImageUploader::store($request->file('photo'), 'profile-pictures/lgu')]);

        return response()->json($user->fresh());
    }

    public function removeProfilePicture(Request $request)
    {
        $user = $request->user();
        ImageUploader::delete($user->profile_picture);
        $user->update(['profile_picture' => null]);

        return response()->json($user->fresh());
    }

    /**
     * Remove a buyer review of a seller that isn't fair to either party --
     * scoped to sellers in this LGU's municipality. Recomputes the seller's
     * rating and logs the removal (see App\Support\ReviewModeration).
     */
    public function destroyReview(Request $request, Review $review)
    {
        $review->loadMissing('sellerProfile');
        abort_if($review->sellerProfile?->municipality_id !== $request->user()->municipality_id, 403, 'You can only remove reviews for sellers in your municipality.');

        ReviewModeration::deleteReview($review, $request->user());

        return response()->json(['message' => 'Review removed.']);
    }

    /**
     * Remove a seller's rating of a buyer -- same municipality scoping (by the
     * rating seller's municipality). Recomputes the buyer's rating and logs it.
     */
    public function destroyBuyerRating(Request $request, BuyerRating $rating)
    {
        $rating->loadMissing('sellerProfile');
        abort_if($rating->sellerProfile?->municipality_id !== $request->user()->municipality_id, 403, 'You can only remove ratings for sellers in your municipality.');

        ReviewModeration::deleteBuyerRating($rating, $request->user());

        return response()->json(['message' => 'Rating removed.']);
    }

    /**
     * Same-municipality listings are always viewable (any status) for the
     * Listing Management/Approvals flow. Listings outside the LGU's
     * municipality are viewable read-only, but only once approved -- this is
     * what lets an LGU admin browse the platform-wide Marketplace (which only
     * ever surfaces approved listings) without exposing another
     * municipality's pending/rejected moderation queue. Mutating endpoints
     * (approve/reject/archive/destroy) remain strictly same-municipality only.
     */
    public function show(Request $request, FingerlingListing $listing)
    {
        $sameMunicipality = $listing->municipality_id === $request->user()->municipality_id;

        if (! $sameMunicipality && $listing->approval_status !== 'approved') {
            return response()->json(['message' => 'LGU admins can only review non-approved listings in their municipality.'], 403);
        }

        return response()->json($listing->load(['sellerProfile.user', 'municipality', 'media']));
    }

    /**
     * All listings for the LGU's municipality, regardless of approval status --
     * used by the Listing Management page, which (unlike the Approvals tab)
     * must also surface already-approved and archived listings.
     */
    public function listings(Request $request)
    {
        return response()->json(
            FingerlingListing::where('municipality_id', $request->user()->municipality_id)
                ->with(['sellerProfile.user', 'municipality', 'media'])
                ->latest()
                ->get()
        );
    }

    public function archiveListing(Request $request, FingerlingListing $listing)
    {
        if ($listing->municipality_id !== $request->user()->municipality_id) {
            return response()->json(['message' => 'LGU admins can only archive listings in their municipality.'], 403);
        }

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $listing->load('sellerProfile');
        $listing->update(['approval_status' => 'archived', 'rejection_reason' => $data['reason'] ?? null]);
        ListingModeration::notifySellerOfRemoval($listing, 'archived', $data['reason'] ?? null, $request->user());

        ActivityLog::record([
            'actor_id' => $request->user()->id,
            'actor_role' => 'lgu_admin',
            'action' => 'listing_archived',
            'target_user_id' => $listing->sellerProfile?->user_id,
            'municipality_id' => $listing->municipality_id,
            'description' => "Archived listing \"{$listing->title}\" for {$listing->sellerProfile?->hatchery_name}".($data['reason'] ?? null ? " -- {$data['reason']}" : '').'.',
        ]);

        return response()->json($listing->fresh(['sellerProfile', 'municipality']));
    }

    public function destroyListing(Request $request, FingerlingListing $listing)
    {
        if ($listing->municipality_id !== $request->user()->municipality_id) {
            return response()->json(['message' => 'LGU admins can only delete listings in their municipality.'], 403);
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        if ($listing->orders()->exists()) {
            return response()->json(['message' => 'This listing has existing orders and cannot be deleted. Archive it instead to remove it from the marketplace.'], 422);
        }

        $listing->load('sellerProfile');
        ListingModeration::notifySellerOfRemoval($listing, 'deleted', $data['reason'], $request->user());
        $listing->delete();

        return response()->json(['message' => 'Listing deleted.']);
    }

    public function approveListing(Request $request, FingerlingListing $listing)
    {
        if ($listing->municipality_id !== $request->user()->municipality_id) {
            return response()->json(['message' => 'LGU admins can only approve listings in their municipality.'], 403);
        }

        $listing->update(['approval_status' => 'approved', 'rejection_reason' => null]);
        $listing->load(['sellerProfile.user', 'municipality']);
        SafeMailer::send($listing->sellerProfile?->user?->email, new ListingApprovedMail($listing));

        ActivityLog::record([
            'actor_id' => $request->user()->id,
            'actor_role' => 'lgu_admin',
            'action' => 'listing_approved',
            'target_user_id' => $listing->sellerProfile?->user_id,
            'municipality_id' => $listing->municipality_id,
            'description' => "Approved listing \"{$listing->title}\" for {$listing->sellerProfile?->hatchery_name}.",
        ]);

        return response()->json($listing);
    }

    public function rejectListing(Request $request, FingerlingListing $listing)
    {
        if ($listing->municipality_id !== $request->user()->municipality_id) {
            return response()->json(['message' => 'LGU admins can only reject listings in their municipality.'], 403);
        }

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $listing->update(['approval_status' => 'rejected', 'rejection_reason' => $data['reason'] ?? null]);
        $listing->load(['sellerProfile.user', 'municipality']);
        SafeMailer::send($listing->sellerProfile?->user?->email, new ListingRejectedMail($listing));

        ActivityLog::record([
            'actor_id' => $request->user()->id,
            'actor_role' => 'lgu_admin',
            'action' => 'listing_rejected',
            'target_user_id' => $listing->sellerProfile?->user_id,
            'municipality_id' => $listing->municipality_id,
            'description' => "Rejected listing \"{$listing->title}\" for {$listing->sellerProfile?->hatchery_name}".($data['reason'] ?? null ? " -- {$data['reason']}" : '').'.',
        ]);

        return response()->json($listing);
    }

    /**
     * User Reports concerning this municipality -- complaints a Buyer filed
     * about one of its Sellers, or one of its Sellers filed about a Buyer. The
     * Super Admin sees the same reports unscoped (SuperAdminController).
     */
    public function userReports(Request $request)
    {
        return response()->json(UserReports::query($request->user()->municipality_id)->get());
    }

    public function updateUserReport(Request $request, UserReport $report)
    {
        if ($report->municipality_id !== $request->user()->municipality_id) {
            return response()->json(['message' => 'LGU admins can only manage reports in their municipality.'], 403);
        }

        $data = $request->validate([
            'status' => ['required', Rule::in(UserReport::STATUSES)],
            'resolution_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json(UserReports::updateStatus($report, $request->user(), $data['status'], $data['resolution_notes'] ?? null));
    }

    /**
     * Notices to Explain raised against sellers in this municipality -- today
     * always from the automatic low-rating check (App\Support\SellerReputation).
     * The LGU reads the seller's explanation here and decides what to do; the
     * notice itself never suspends anyone.
     */
    public function sellerNotices(Request $request)
    {
        return response()->json(
            SellerNotice::with(['sellerProfile.user', 'sellerProfile.municipality', 'reviewer'])
                ->where('municipality_id', $request->user()->municipality_id)
                ->latest()
                ->get()
        );
    }

    public function updateSellerNotice(Request $request, SellerNotice $notice)
    {
        if ($notice->municipality_id !== $request->user()->municipality_id) {
            return response()->json(['message' => 'LGU admins can only manage notices in their municipality.'], 403);
        }

        $data = $request->validate([
            'status' => ['required', Rule::in(SellerNotice::STATUSES)],
            'lgu_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $notice->update([
            'status' => $data['status'],
            'lgu_notes' => $data['lgu_notes'] ?? $notice->lgu_notes,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        $notice->load('sellerProfile');

        if ($notice->sellerProfile?->user_id) {
            AppNotification::create([
                'user_id' => $notice->sellerProfile->user_id,
                'type' => 'seller_notice_updated',
                'title' => 'Notice to Explain Updated',
                'body' => sprintf(
                    'Your LGU marked your Notice to Explain as %s.%s',
                    str_replace('_', ' ', $data['status']),
                    ! empty($data['lgu_notes']) ? " Notes: {$data['lgu_notes']}" : ''
                ),
            ]);
        }

        ActivityLog::record([
            'actor_id' => $request->user()->id,
            'actor_role' => 'lgu_admin',
            'action' => 'seller_notice_updated',
            'target_user_id' => $notice->sellerProfile?->user_id,
            'municipality_id' => $notice->municipality_id,
            'description' => sprintf(
                'Notice to Explain for %s marked %s.',
                $notice->sellerProfile?->hatchery_name ?? 'seller',
                str_replace('_', ' ', $data['status'])
            ),
        ]);

        return response()->json($notice->fresh(['sellerProfile.user', 'reviewer']));
    }

    /**
     * Seller registrations awaiting review in this LGU's municipality -- the
     * LGU Admin is the usual reviewer for their own sellers. Rejected
     * registrations stay listed here so the decision can be reconsidered.
     */
    public function sellerRegistrations(Request $request)
    {
        return response()->json(
            SellerProfile::with(['user', 'municipality'])
                ->where('municipality_id', $request->user()->municipality_id)
                ->whereIn('approval_status', [SellerApproval::PENDING, SellerApproval::REJECTED])
                ->latest()
                ->get()
                ->each->makeVisible(SellerProfile::REVIEW_FIELDS)
        );
    }

    /**
     * Approve a seller registration in this LGU's municipality. One approval
     * is all it takes -- the seller becomes verified and can list immediately
     * (see App\Support\SellerApproval). The Super Admin has the same power
     * platform-wide as a fallback, but this is the normal path.
     */
    public function approveSellerRegistration(Request $request, SellerProfile $seller)
    {
        if ($seller->municipality_id !== $request->user()->municipality_id) {
            return response()->json(['message' => 'LGU admins can only review sellers in their municipality.'], 403);
        }

        return response()->json(SellerApproval::approve($seller, $request->user(), 'lgu_admin'));
    }

    public function rejectSellerRegistration(Request $request, SellerProfile $seller)
    {
        if ($seller->municipality_id !== $request->user()->municipality_id) {
            return response()->json(['message' => 'LGU admins can only review sellers in their municipality.'], 403);
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        return response()->json(SellerApproval::reject($seller, $request->user(), 'lgu_admin', $data['reason']));
    }

    /**
     * Backwards-compatible alias for the old one-click "Verify" action, which
     * is now the registration approval.
     */
    public function verifySeller(Request $request, SellerProfile $seller)
    {
        return $this->approveSellerRegistration($request, $seller);
    }

    public function sellers(Request $request)
    {
        return response()->json(
            SellerProfile::with('user')
                ->where('municipality_id', $request->user()->municipality_id)
                ->latest()
                ->get()
        );
    }

    /**
     * reason/notes are optional (backward compatible with the existing
     * frontend, which sends neither) -- see App\Support\AccountModeration,
     * which also underlies the Super Admin's global seller moderation.
     */
    public function suspendSeller(Request $request, SellerProfile $seller)
    {
        if ($seller->municipality_id !== $request->user()->municipality_id) {
            return response()->json(['message' => 'LGU admins can only suspend sellers in their municipality.'], 403);
        }

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $seller = AccountModeration::suspendSeller($seller, $request->user(), $data['reason'] ?? null, $data['notes'] ?? null);

        return response()->json($seller);
    }

    public function reinstateSeller(Request $request, SellerProfile $seller)
    {
        if ($seller->municipality_id !== $request->user()->municipality_id) {
            return response()->json(['message' => 'LGU admins can only reinstate sellers in their municipality.'], 403);
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $seller = AccountModeration::reinstateSeller($seller, $request->user(), $data['reason'], $data['notes'] ?? null);

        return response()->json($seller);
    }

    public function users(Request $request)
    {
        $municipalityId = $request->user()->municipality_id;

        return response()->json([
            'buyers' => User::where('role', 'buyer')->where('municipality_id', $municipalityId)->with('buyerProfile:user_id,rating,ratings_count')->get(),
            'sellers' => User::where('role', 'seller')->where('municipality_id', $municipalityId)->get(),
        ]);
    }

    /**
     * Rejected orders are terminal (see rejectEarnings) and drop out of this
     * queue permanently; On-hold orders (see holdEarnings) stay visible so
     * the LGU can still see and eventually clear them -- see
     * App\Support\OrderTimeline for how the frontend surfaces that state.
     */
    /**
     * Global Activity Log, forcibly scoped to the admin's own municipality --
     * see App\Support\ActivityLog::query for the unified read path across
     * every event source.
     */
    public function activityLog(Request $request)
    {
        return response()->json(ActivityLog::query([
            'search' => $request->query('search'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'user_id' => $request->query('user_id'),
            'category' => $request->query('category'),
            'action' => $request->query('action'),
            'per_page' => $request->query('per_page', 25),
            'page' => $request->query('page', 1),
            'municipality_id' => $request->user()->municipality_id,
        ]));
    }

    public function activityLogActions()
    {
        return response()->json(ActivityLog::actionTypes());
    }

    public function activityLogCategories()
    {
        return response()->json(ActivityLog::categoryOptions());
    }

    public function pendingEarnings(Request $request)
    {
        $municipalityId = $request->user()->municipality_id;

        return response()->json(
            MockPayment::where('status', 'paid_held')
                ->whereHas('order', fn ($q) => $q
                    ->where('status', 'completed')
                    ->where(fn ($q3) => $q3->whereNull('lgu_review_status')->orWhere('lgu_review_status', '!=', 'rejected'))
                    ->whereHas('sellerProfile', fn ($q2) => $q2->where('municipality_id', $municipalityId)))
                ->with(['order.sellerProfile.user', 'order.buyer', 'order.listing'])
                ->latest()
                ->get()
        );
    }

    /**
     * Transactions this LGU has rejected, whose money is still held.
     *
     * Deliberately a separate list from pendingEarnings() rather than rows
     * mixed into it: "awaiting approval" drives counts on both this dashboard
     * and the Super Admin's, and a rejected order is not awaiting anything.
     * But it can't simply disappear either -- its payment stays 'paid_held'
     * (see rejectEarnings), so this is the only place that held money is still
     * visible and actionable, via reopenRejectedEarnings().
     */
    public function rejectedEarnings(Request $request)
    {
        $municipalityId = $request->user()->municipality_id;

        return response()->json(
            MockPayment::where('status', 'paid_held')
                ->whereHas('order', fn ($q) => $q
                    ->where('status', 'completed')
                    ->where('lgu_review_status', 'rejected')
                    ->whereHas('sellerProfile', fn ($q2) => $q2->where('municipality_id', $municipalityId)))
                ->with(['order.sellerProfile.user', 'order.buyer', 'order.listing', 'order.reviewedBy'])
                ->latest()
                ->get()
        );
    }

    /**
     * Full transaction detail behind the earnings-approval queue, so an LGU
     * Admin can review everything about the order (not just the summary
     * line) before approving/rejecting/holding it -- see
     * App\Support\OrderTransactionPresenter.
     */
    public function showOrder(Request $request, Order $order)
    {
        abort_if($order->sellerProfile?->municipality_id !== $request->user()->municipality_id, 403, 'You can only view orders for sellers in your municipality.');

        return response()->json(OrderTransactionPresenter::present($order, 'lgu_admin'));
    }

    public function approveEarnings(Request $request, MockPayment $payment)
    {
        $payment->load(['order.sellerProfile.user', 'order.buyer', 'order.listing']);
        $order = $payment->order;

        abort_if(! $order || $order->sellerProfile?->municipality_id !== $request->user()->municipality_id, 403, 'You can only approve earnings for sellers in your municipality.');
        abort_unless($order->status === 'completed', 422, 'Only completed (delivered) orders are eligible for earnings approval.');
        abort_unless($payment->status === 'paid_held', 422, 'This payment is not awaiting approval.');
        abort_if($order->lgu_review_status === 'on_hold', 422, 'This order is on hold for investigation. Clear the hold before approving.');
        abort_if($order->lgu_review_status === 'rejected', 422, 'This order was rejected and cannot be approved.');

        // The revenue split uses the fixed marketplace commission (see
        // App\Support\CommissionCalculator) and is frozen onto the
        // settlement row at approval time -- see App\Models\Settlement.
        $split = CommissionCalculator::split((float) $payment->amount);
        $settledAt = Carbon::now();

        $settlement = DB::transaction(function () use ($payment, $order, $split, $settledAt, $request) {
            $payment->update(['status' => 'released', 'released_at' => $settledAt]);

            return Settlement::create([
                'order_id' => $order->id,
                'payment_id' => $payment->id,
                'seller_profile_id' => $order->seller_profile_id,
                'municipality_id' => $order->sellerProfile->municipality_id,
                'approved_by' => $request->user()->id,
                'gross_amount' => $payment->amount,
                'seller_share' => $split['seller_share'],
                'lgu_share' => $split['lgu_share'],
                'platform_share' => $split['platform_share'],
                'seller_percent' => $split['seller_percent'],
                'lgu_percent' => $split['lgu_percent'],
                'platform_percent' => $split['platform_percent'],
                'status' => 'settled',
                'settled_at' => $settledAt,
            ]);
        });

        AppNotification::firstOrCreate([
            'user_id' => $order->sellerProfile->user_id,
            'type' => 'earnings_approved',
            'title' => 'Earnings Approved',
            'body' => sprintf(
                'Your LGU has approved order #%s. Your seller earnings of ₱%s have moved from your Pending Balance to your Available Balance -- this is not yet a payout, you can request a withdrawal any time.',
                $order->order_number,
                number_format($split['seller_share'], 2)
            ),
        ]);

        AppNotification::where('type', "earnings_pending_approval:{$payment->id}")
            ->whereNull('read_at')
            ->update(['read_at' => $settledAt]);

        // Earnings approval only unlocks the wallet balance -- it is not a
        // payout, so this is a distinct email from WithdrawalReleasedMail
        // (which only ever fires from SuperAdminController::markWithdrawalPaid).
        SafeMailer::send($order->sellerProfile->user?->email, new SellerEarningsApprovedMail($settlement));

        return response()->json($payment->fresh());
    }

    /**
     * Puts a completed order's earnings approval on hold pending
     * investigation -- blocks approveEarnings() until clearHold() is called,
     * without touching payment.status or any revenue-sharing math. Reuses
     * the exact same eligibility guard as approveEarnings().
     */
    public function holdEarnings(Request $request, MockPayment $payment)
    {
        $order = $this->eligibleOrderForReview($request, $payment);

        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $order->update([
            'lgu_review_status' => 'on_hold',
            'lgu_review_reason' => $data['reason'],
            'lgu_reviewed_at' => now(),
            'lgu_reviewed_by' => $request->user()->id,
        ]);

        $this->notifySellerOfReview($order, 'earnings_on_hold', 'Earnings Under Investigation', sprintf(
            'Your LGU has placed order #%s on hold pending investigation and has not yet approved your earnings. Reason: %s',
            $order->order_number,
            $data['reason']
        ));

        return response()->json(OrderTransactionPresenter::present($order->fresh(), 'lgu_admin'));
    }

    public function clearHold(Request $request, MockPayment $payment)
    {
        $order = $this->eligibleOrderForReview($request, $payment, requireHeld: true);

        $this->clearReviewStatus($order, $request->user());

        return response()->json(OrderTransactionPresenter::present($order->fresh(), 'lgu_admin'));
    }

    /**
     * Undo a rejection, returning the order to the earnings-approval queue so
     * it can be approved (or rejected again) after all.
     *
     * Without this a rejection was a dead end: the payment stays 'paid_held'
     * forever (see rejectEarnings), rejected orders are filtered out of
     * pendingEarnings(), and eligibleOrderForReview() refuses an
     * already-rejected order -- so nothing, in any role, could ever resolve
     * the held escrow. This is the rejection counterpart of clearHold() above
     * and shares its mechanics; only the precondition differs.
     */
    public function reopenRejectedEarnings(Request $request, MockPayment $payment)
    {
        $order = $this->eligibleOrderForReview($request, $payment, requireRejected: true);

        $this->clearReviewStatus($order, $request->user());

        $this->notifySellerOfReview($order, 'earnings_reopened', 'Earnings Under Review Again', sprintf(
            'Your LGU has reopened the rejected earnings review for order #%s. It is back in their approval queue, and your projected earnings for it have been restored to your Pending Balance.',
            $order->order_number
        ));

        return response()->json(OrderTransactionPresenter::present($order->fresh(), 'lgu_admin'));
    }

    /**
     * Return an order to the neutral, reviewable state -- shared by clearHold()
     * and reopenRejectedEarnings(), which differ only in what they're undoing.
     * Never touches payment.status: the money has been sitting in 'paid_held'
     * throughout, and only approveEarnings() ever releases it.
     */
    private function clearReviewStatus(Order $order, User $actor): void
    {
        $order->update([
            'lgu_review_status' => null,
            'lgu_review_reason' => null,
            'lgu_reviewed_at' => now(),
            'lgu_reviewed_by' => $actor->id,
        ]);
    }

    /**
     * Terminal: declines to approve this order's earnings. Deliberately
     * leaves payment.status untouched (still 'paid_held') since resolving
     * the held escrow itself -- e.g. a refund flow -- is outside the scope
     * of Order Tracking/Lookup; order.lgu_review_status is the source of
     * truth that this transaction was rejected. No Settlement is created, so
     * no revenue is distributed.
     */
    public function rejectEarnings(Request $request, MockPayment $payment)
    {
        $order = $this->eligibleOrderForReview($request, $payment);

        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $order->update([
            'lgu_review_status' => 'rejected',
            'lgu_review_reason' => $data['reason'],
            'lgu_reviewed_at' => now(),
            'lgu_reviewed_by' => $request->user()->id,
        ]);

        $this->notifySellerOfReview($order, 'earnings_rejected', 'Earnings Rejected', sprintf(
            'Your LGU has rejected earnings approval for order #%s. Reason: %s',
            $order->order_number,
            $data['reason']
        ));

        return response()->json(OrderTransactionPresenter::present($order->fresh(), 'lgu_admin'));
    }

    /**
     * The shared eligibility gate for every earnings-review action. The
     * municipality/completed/paid_held checks are common to all of them; the
     * flags select which review state the specific action requires:
     *
     *  - default        -- must not already be rejected (approve/hold/reject)
     *  - requireHeld    -- must currently be on hold (clearHold)
     *  - requireRejected-- must currently be rejected (reopenRejectedEarnings)
     */
    private function eligibleOrderForReview(Request $request, MockPayment $payment, bool $requireHeld = false, bool $requireRejected = false): Order
    {
        $payment->load('order.sellerProfile');
        $order = $payment->order;

        abort_if(! $order || $order->sellerProfile?->municipality_id !== $request->user()->municipality_id, 403, 'You can only review earnings for sellers in your municipality.');
        abort_unless($order->status === 'completed', 422, 'Only completed (delivered) orders are eligible for earnings review.');
        abort_unless($payment->status === 'paid_held', 422, 'This payment is not awaiting approval.');

        if ($requireHeld) {
            abort_unless($order->lgu_review_status === 'on_hold', 422, 'This order is not currently on hold.');
        } elseif ($requireRejected) {
            abort_unless($order->lgu_review_status === 'rejected', 422, 'This order is not currently rejected.');
        } else {
            abort_if($order->lgu_review_status === 'rejected', 422, 'This order was already rejected. Reopen it first if it should be reviewed again.');
        }

        return $order;
    }

    private function notifySellerOfReview(Order $order, string $type, string $title, string $body): void
    {
        $order->loadMissing('sellerProfile');
        if (! $order->sellerProfile?->user_id) {
            return;
        }

        AppNotification::firstOrCreate([
            'user_id' => $order->sellerProfile->user_id,
            'type' => "{$type}:{$order->id}",
            'title' => $title,
            'body' => $body,
        ]);
    }

    /**
     * The LGU Wallet page -- same architecture as SellerController::wallet(),
     * scoped to the admin's own municipality rather than a single profile.
     * Every LGU admin assigned to a municipality sees the same shared wallet
     * and withdrawal history, since the revenue belongs to the municipality.
     */
    public function wallet(Request $request)
    {
        $municipalityId = $request->user()->municipality_id;

        return response()->json([
            ...LguWallet::summary($municipalityId),
            'revenue_history' => Settlement::where('municipality_id', $municipalityId)
                ->with(['order.buyer', 'order.listing', 'sellerProfile'])
                ->latest('settled_at')
                ->get(),
            'withdrawal_requests' => LguWithdrawalRequest::where('municipality_id', $municipalityId)
                ->with('requestedBy')
                ->latest()
                ->get(),
        ]);
    }

    /**
     * Reuses the exact same validation shape as
     * SellerController::requestWithdrawal() (amount bounds against the live
     * available balance, same field set) -- see that method for the
     * seller-side equivalent this mirrors.
     */
    public function requestWithdrawal(Request $request)
    {
        $municipalityId = $request->user()->municipality_id;

        $data = $request->validate([
            'method' => ['required', Rule::in(['gcash', 'maya', 'bank_transfer'])],
            'account_name' => ['required', 'string'],
            'account_number' => ['required', 'string'],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $alreadyPending = LguWithdrawalRequest::where('municipality_id', $municipalityId)
            ->whereIn('status', ['pending', 'approved'])
            ->exists();
        abort_if($alreadyPending, 422, 'Your municipality already has a withdrawal request awaiting payment. Wait for it to be resolved before submitting another.');

        if ($data['amount'] > LguWallet::availableBalance($municipalityId)) {
            return response()->json(['message' => 'Withdrawal amount exceeds your available balance.'], 422);
        }

        $withdrawal = LguWithdrawalRequest::create([
            'municipality_id' => $municipalityId,
            'requested_by' => $request->user()->id,
            'method' => $data['method'],
            'account_name' => $data['account_name'],
            'account_number' => $data['account_number'],
            'amount' => $data['amount'],
            'status' => 'pending',
        ]);

        return response()->json($withdrawal, 201);
    }
}
