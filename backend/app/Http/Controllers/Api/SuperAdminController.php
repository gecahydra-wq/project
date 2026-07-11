<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\ListingApprovedMail;
use App\Mail\ListingRejectedMail;
use App\Mail\LguWithdrawalApprovedMail;
use App\Mail\LguWithdrawalReleasedMail;
use App\Mail\WithdrawalReleasedMail;
use App\Models\AppNotification;
use App\Models\BuyerRating;
use App\Models\FingerlingListing;
use App\Models\LguWithdrawalRequest;
use App\Models\MockPayment;
use App\Models\ModerationLog;
use App\Models\Order;
use App\Models\SellerProfile;
use App\Models\Municipality;
use App\Models\Review;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Support\AccountModeration;
use App\Support\ActivityLog;
use App\Support\ListingModeration;
use App\Support\ImageUploader;
use App\Support\OrderTransactionPresenter;
use App\Support\ReviewModeration;
use App\Support\RevenueReport;
use App\Support\SafeMailer;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class SuperAdminController extends Controller
{
    public function dashboard()
    {
        return response()->json([
            'lgu_admins' => User::where('role', 'lgu_admin')->count(),
            'total_sellers' => SellerProfile::count(),
            'transactions' => Order::with(['listing', 'payment', 'sellerProfile.user'])->latest()->get(),
            // Marketplace Revenue -- platform-wide, from settled orders only.
            'platform_revenue' => RevenueReport::platformCards(),
            // Executive at-a-glance figures -- today's activity + top performers,
            // computed in one place (RevenueReport::executiveSnapshot) so the
            // dashboard cards can never disagree with the Reports charts.
            'executive' => RevenueReport::executiveSnapshot(),
            // Approval queues awaiting action. Pending Seller Approvals are
            // seller accounts not yet verified by their LGU; Pending LGU
            // Approvals are settled-but-held payments awaiting LGU earnings
            // verification (the "LGU verifies transaction" step), platform-wide.
            'pending_seller_approvals' => SellerProfile::where('verified', false)->where('status', '!=', 'suspended')->count(),
            'pending_lgu_approvals' => MockPayment::where('status', 'paid_held')
                ->whereHas('order', fn ($q) => $q
                    ->where('status', 'completed')
                    ->where(fn ($q2) => $q2->whereNull('lgu_review_status')->orWhere('lgu_review_status', '!=', 'rejected')))
                ->count(),
            'pending_listing_approvals' => FingerlingListing::where('approval_status', 'pending')->count(),
            // Recent Activity -- the platform-wide audit trail's most recent
            // entries, reusing the exact same read path as the Activity Log tab
            // (App\Support\ActivityLog::query) so both stay consistent.
            'recent_activity' => ActivityLog::query(['per_page' => 8, 'page' => 1])['data'],
            // Payout queue snapshot -- Seller and LGU withdrawals are tracked
            // in separate tables (see WithdrawalRequest / LguWithdrawalRequest)
            // so they're counted separately here too, never merged into one
            // ambiguous figure.
            'pending_seller_withdrawals' => WithdrawalRequest::whereIn('status', ['pending', 'approved'])->count(),
            'pending_lgu_withdrawals' => LguWithdrawalRequest::whereIn('status', ['pending', 'approved'])->count(),
            'completed_seller_withdrawals' => WithdrawalRequest::where('status', 'paid')->count(),
            'completed_lgu_withdrawals' => LguWithdrawalRequest::where('status', 'paid')->count(),
            // Account Moderation -- platform-wide, every role. "Active" is
            // simply "not suspended" for Buyers/Sellers, and "not disabled"
            // for LGU Admins.
            'active_buyers' => User::where('role', 'buyer')->where('status', '!=', 'suspended')->count(),
            'suspended_buyers' => User::where('role', 'buyer')->where('status', 'suspended')->count(),
            'active_sellers' => SellerProfile::where('status', '!=', 'suspended')->count(),
            'suspended_sellers' => SellerProfile::where('status', 'suspended')->count(),
            'active_lgu_admins' => User::where('role', 'lgu_admin')->where('status', '!=', 'disabled')->count(),
            'suspended_lgu_admins' => User::where('role', 'lgu_admin')->where('status', 'disabled')->count(),
            'recent_moderation_actions' => ModerationLog::with(['user', 'moderator'])->latest()->take(10)->get(),
        ]);
    }

    /**
     * Global Order Lookup -- unscoped, full transaction detail for any order
     * platform-wide (see App\Support\OrderTransactionPresenter). Lets the
     * Super Admin investigate a single transaction by Order Number without
     * navigating through the municipality/seller/buyer hierarchy.
     */
    public function showOrder(Order $order)
    {
        return response()->json(OrderTransactionPresenter::present($order, 'super_admin'));
    }

    /**
     * Global Activity Log, platform-wide -- optionally filtered to one
     * municipality. See App\Support\ActivityLog::query for the unified read
     * path across every event source (this is a different, broader view
     * than moderationLog() below, which stays unchanged).
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
            'municipality_id' => $request->query('municipality_id'),
            'per_page' => $request->query('per_page', 25),
            'page' => $request->query('page', 1),
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

    /**
     * Full moderation audit trail -- every suspend/reinstate action across
     * every role, most recent first. Filterable by role and/or action so
     * the Moderation Log page can answer "show me suspended sellers" etc.
     * without a separate endpoint per role.
     */
    public function moderationLog(Request $request)
    {
        $query = ModerationLog::with(['user', 'moderator']);

        if ($request->filled('role')) {
            $query->where('role', $request->query('role'));
        }
        if ($request->filled('action')) {
            $query->where('action', $request->query('action'));
        }

        return response()->json($query->latest()->get());
    }

    public function withdrawals()
    {
        return response()->json(
            WithdrawalRequest::with('sellerProfile.user')->latest()->get()
        );
    }

    /**
     * LGU revenue withdrawals -- managed exactly like Seller withdrawals
     * (see withdrawals()/approveWithdrawal()/rejectWithdrawal()/
     * markWithdrawalPaid() above), just against the municipality-scoped
     * LguWithdrawalRequest table instead of the seller-scoped one.
     */
    public function lguWithdrawals()
    {
        return response()->json(
            LguWithdrawalRequest::with(['municipality', 'requestedBy'])->latest()->get()
        );
    }

    public function approveLguWithdrawal(LguWithdrawalRequest $withdrawal)
    {
        abort_unless($withdrawal->status === 'pending', 422, 'Only pending withdrawal requests can be approved.');

        $withdrawal->update(['status' => 'approved', 'reviewed_at' => Carbon::now()]);
        $withdrawal->load('requestedBy', 'municipality');

        if ($withdrawal->requested_by) {
            AppNotification::firstOrCreate([
                'user_id' => $withdrawal->requested_by,
                'type' => 'lgu_withdrawal_approved',
                'title' => 'LGU Withdrawal Approved',
                'body' => sprintf(
                    'Your withdrawal request of ₱%s for %s via %s has been approved and is being processed.',
                    number_format((float) $withdrawal->amount, 2),
                    $withdrawal->municipality?->name ?? 'your municipality',
                    $withdrawal->method
                ),
            ]);
        }

        // Distinct from LguWithdrawalReleasedMail (fired only from
        // markLguWithdrawalPaid) -- this one confirms the request was
        // accepted and is being processed, not that funds have moved yet.
        SafeMailer::send($withdrawal->requestedBy?->email, new LguWithdrawalApprovedMail($withdrawal));

        return response()->json($withdrawal->fresh());
    }

    public function rejectLguWithdrawal(Request $request, LguWithdrawalRequest $withdrawal)
    {
        abort_if(in_array($withdrawal->status, ['paid', 'rejected'], true), 422, 'This withdrawal request has already been finalized.');

        $data = $request->validate([
            'reason' => ['required', 'string'],
        ]);

        $withdrawal->update([
            'status' => 'rejected',
            'rejection_reason' => $data['reason'],
            'reviewed_at' => Carbon::now(),
        ]);
        $withdrawal->load('requestedBy', 'municipality');

        if ($withdrawal->requested_by) {
            AppNotification::firstOrCreate([
                'user_id' => $withdrawal->requested_by,
                'type' => 'lgu_withdrawal_rejected',
                'title' => 'LGU Withdrawal Rejected',
                'body' => sprintf(
                    'Your withdrawal request of ₱%s for %s via %s was rejected. Reason: %s',
                    number_format((float) $withdrawal->amount, 2),
                    $withdrawal->municipality?->name ?? 'your municipality',
                    $withdrawal->method,
                    $data['reason']
                ),
            ]);
        }

        return response()->json($withdrawal->fresh());
    }

    public function markLguWithdrawalPaid(LguWithdrawalRequest $withdrawal)
    {
        abort_unless($withdrawal->status === 'approved', 422, 'Only approved withdrawal requests can be marked as paid.');

        $paidAt = Carbon::now();
        $withdrawal->update(['status' => 'paid', 'paid_at' => $paidAt, 'reviewed_at' => $paidAt]);
        $withdrawal->load('requestedBy', 'municipality');

        if ($withdrawal->requested_by) {
            AppNotification::firstOrCreate([
                'user_id' => $withdrawal->requested_by,
                'type' => 'lgu_withdrawal_paid',
                'title' => 'LGU Withdrawal Completed',
                'body' => sprintf(
                    'Your withdrawal request of ₱%s for %s via %s has been paid out on %s.',
                    number_format((float) $withdrawal->amount, 2),
                    $withdrawal->municipality?->name ?? 'your municipality',
                    $withdrawal->method,
                    $paidAt->format('M d, Y')
                ),
            ]);
        }

        // Distinct from LguWithdrawalApprovedMail (fired in approveLguWithdrawal
        // above) -- this one confirms the payout actually happened, never on
        // submission or mere approval.
        SafeMailer::send($withdrawal->requestedBy?->email, new LguWithdrawalReleasedMail($withdrawal));

        return response()->json($withdrawal->fresh());
    }

    public function approveWithdrawal(WithdrawalRequest $withdrawal)
    {
        abort_unless($withdrawal->status === 'pending', 422, 'Only pending withdrawal requests can be approved.');

        $withdrawal->update(['status' => 'approved', 'reviewed_at' => Carbon::now()]);
        $withdrawal->load('sellerProfile');

        AppNotification::firstOrCreate([
            'user_id' => $withdrawal->sellerProfile->user_id,
            'type' => 'withdrawal_approved',
            'title' => 'Withdrawal Approved',
            'body' => sprintf(
                'Your withdrawal request of ₱%s via %s has been approved and is being processed.',
                number_format((float) $withdrawal->amount, 2),
                $withdrawal->method
            ),
        ]);

        return response()->json($withdrawal->fresh());
    }

    public function rejectWithdrawal(Request $request, WithdrawalRequest $withdrawal)
    {
        abort_if(in_array($withdrawal->status, ['paid', 'rejected'], true), 422, 'This withdrawal request has already been finalized.');

        $data = $request->validate([
            'reason' => ['required', 'string'],
        ]);

        $withdrawal->update([
            'status' => 'rejected',
            'rejection_reason' => $data['reason'],
            'reviewed_at' => Carbon::now(),
        ]);
        $withdrawal->load('sellerProfile');

        AppNotification::firstOrCreate([
            'user_id' => $withdrawal->sellerProfile->user_id,
            'type' => 'withdrawal_rejected',
            'title' => 'Withdrawal Rejected',
            'body' => sprintf(
                'Your withdrawal request of ₱%s via %s was rejected. Reason: %s',
                number_format((float) $withdrawal->amount, 2),
                $withdrawal->method,
                $data['reason']
            ),
        ]);

        return response()->json($withdrawal->fresh());
    }

    public function markWithdrawalPaid(WithdrawalRequest $withdrawal)
    {
        abort_unless($withdrawal->status === 'approved', 422, 'Only approved withdrawal requests can be marked as paid.');

        $paidAt = Carbon::now();
        $withdrawal->update(['status' => 'paid', 'paid_at' => $paidAt, 'reviewed_at' => $paidAt]);
        $withdrawal->load('sellerProfile.user');

        AppNotification::firstOrCreate([
            'user_id' => $withdrawal->sellerProfile->user_id,
            'type' => 'withdrawal_paid',
            'title' => 'Withdrawal Completed',
            'body' => sprintf(
                'Your withdrawal request of ₱%s via %s has been paid out on %s. A ₱%s platform payout fee was deducted -- you received ₱%s.',
                number_format((float) $withdrawal->amount, 2),
                $withdrawal->method,
                $paidAt->format('M d, Y'),
                number_format((float) $withdrawal->platform_fee, 2),
                number_format($withdrawal->net_amount, 2)
            ),
        ]);

        SafeMailer::send($withdrawal->sellerProfile->user?->email, new WithdrawalReleasedMail($withdrawal));

        return response()->json($withdrawal->fresh());
    }

    public function storeLguAdmin(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'min:8'],
            'municipality_id' => ['required', 'exists:municipalities,id'],
            'phone' => ['nullable', 'string'],
        ]);

        $admin = User::create([
            ...$data,
            'role' => 'lgu_admin',
            // Provisioned directly by the Super Admin, not self-registered --
            // there's no email-ownership to prove, so this account is active
            // immediately rather than gated behind the verify-email flow.
            'email_verified_at' => now(),
        ]);

        ActivityLog::record([
            'actor_id' => $request->user()->id,
            'actor_role' => 'super_admin',
            'action' => 'lgu_admin_created',
            'target_user_id' => $admin->id,
            'municipality_id' => $admin->municipality_id,
            'description' => "Created LGU Admin account for {$admin->name}.",
        ]);

        return response()->json($admin, 201);
    }

    public function updateLguAdmin(Request $request, User $admin)
    {
        abort_unless($admin->role === 'lgu_admin', 404);

        $data = $request->validate([
            'name' => ['sometimes', 'string'],
            'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($admin->id)],
            'municipality_id' => ['sometimes', 'exists:municipalities,id'],
            'phone' => ['nullable', 'string'],
        ]);

        $admin->update($data);

        ActivityLog::record([
            'actor_id' => $request->user()->id,
            'actor_role' => 'super_admin',
            'action' => 'lgu_admin_updated',
            'target_user_id' => $admin->id,
            'municipality_id' => $admin->municipality_id,
            'description' => "Updated LGU Admin account for {$admin->name}.",
        ]);

        return response()->json($admin);
    }

    /**
     * Minimal municipality creation -- municipalities were previously only
     * ever seeded (see the Cordova migration); this gives Super Admin a
     * runtime way to add one, purely additive to the existing read-only
     * Municipalities tab (PlatformController::municipalities/sellers stay
     * unchanged).
     */
    public function storeMunicipality(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', Rule::unique('municipalities', 'name')],
            'province' => ['nullable', 'string'],
        ]);

        $municipality = Municipality::create($data);

        ActivityLog::record([
            'actor_id' => $request->user()->id,
            'actor_role' => 'super_admin',
            'action' => 'municipality_created',
            'municipality_id' => $municipality->id,
            'description' => "Created municipality {$municipality->name}.",
        ]);

        return response()->json($municipality, 201);
    }

    /**
     * "Disable" is this app's original name for suspending an LGU Admin --
     * kept as the route/method name for backward compatibility, now also
     * recording a ModerationLog entry and sending AccountSuspendedMail, same
     * as Buyer/Seller suspension. reason/notes are optional so existing
     * callers that send neither keep working unchanged.
     */
    public function disableLguAdmin(Request $request, User $admin)
    {
        abort_unless($admin->role === 'lgu_admin', 404);
        abort_if($admin->id === $request->user()->id, 422, 'You cannot suspend your own account.');

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $admin = AccountModeration::suspendLguAdmin($admin, $request->user(), $data['reason'] ?? null, $data['notes'] ?? null);

        return response()->json($admin);
    }

    public function enableLguAdmin(Request $request, User $admin)
    {
        abort_unless($admin->role === 'lgu_admin', 404);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $admin = AccountModeration::reinstateLguAdmin($admin, $request->user(), $data['reason'], $data['notes'] ?? null);

        return response()->json($admin);
    }

    /**
     * Suspend/reinstate a Buyer account platform-wide -- see
     * App\Support\AccountModeration. Unlike Sellers and LGU Admins, a
     * suspended Buyer is NOT logged out (their tokens are untouched): they
     * can still log in and browse, they just can't place orders, pay,
     * message, or review -- see the guards in OrderController,
     * MessageController, and ReviewController.
     */
    public function suspendBuyer(Request $request, User $user)
    {
        abort_unless($user->role === 'buyer', 404);
        abort_if($user->id === $request->user()->id, 422, 'You cannot suspend your own account.');

        $data = $request->validate([
            'reason' => ['required', Rule::in([
                'Fraudulent Orders', 'Fake Payments', 'Chargeback Abuse', 'Harassment',
                'Spam', 'Multiple Fake Accounts', 'Marketplace Policy Violation', 'Other',
            ])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = AccountModeration::suspendBuyer($user, $request->user(), $data['reason'], $data['notes'] ?? null);

        return response()->json($user);
    }

    public function reinstateBuyer(Request $request, User $user)
    {
        abort_unless($user->role === 'buyer', 404);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = AccountModeration::reinstateBuyer($user, $request->user(), $data['reason'], $data['notes'] ?? null);

        return response()->json($user);
    }

    /**
     * Suspend/reinstate ANY Seller platform-wide, regardless of
     * municipality -- unlike LguController::suspendSeller/reinstateSeller,
     * which stay strictly scoped to the LGU admin's own municipality. Both
     * paths share the exact same underlying mechanics (see
     * App\Support\AccountModeration::suspendSeller/reinstateSeller).
     */
    public function suspendSeller(Request $request, SellerProfile $seller)
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $seller = AccountModeration::suspendSeller($seller, $request->user(), $data['reason'] ?? null, $data['notes'] ?? null);

        return response()->json($seller);
    }

    public function reinstateSeller(Request $request, SellerProfile $seller)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $seller = AccountModeration::reinstateSeller($seller, $request->user(), $data['reason'], $data['notes'] ?? null);

        return response()->json($seller);
    }

    public function users()
    {
        return response()->json([
            'buyers' => User::where('role', 'buyer')->with('buyerProfile:user_id,rating,ratings_count')->get(),
        ]);
    }

    /**
     * Remove a buyer review of a seller (unfair/abusive), platform-wide.
     * Recomputes the seller's rating and logs it -- see App\Support\ReviewModeration.
     */
    public function destroyReview(Request $request, Review $review)
    {
        ReviewModeration::deleteReview($review, $request->user());

        return response()->json(['message' => 'Review removed.']);
    }

    /**
     * Remove a seller's rating of a buyer (unfair/abusive), platform-wide.
     * Recomputes the buyer's rating and logs it.
     */
    public function destroyBuyerRating(Request $request, BuyerRating $rating)
    {
        ReviewModeration::deleteBuyerRating($rating, $request->user());

        return response()->json(['message' => 'Rating removed.']);
    }

    /**
     * Profile picture management for the Super Admin -- the only editable part
     * of their profile (no public profile info to maintain). Mirrors the
     * Buyer/Seller/LGU picture handling.
     */
    public function uploadProfilePicture(Request $request)
    {
        $request->validate(['photo' => array_merge(['required'], ImageUploader::validationRules())]);

        $user = $request->user();
        ImageUploader::delete($user->profile_picture);
        $user->update(['profile_picture' => ImageUploader::store($request->file('photo'), 'profile-pictures/super-admins')]);

        return response()->json($user->fresh());
    }

    public function removeProfilePicture(Request $request)
    {
        $user = $request->user();
        ImageUploader::delete($user->profile_picture);
        $user->update(['profile_picture' => null]);

        return response()->json($user->fresh());
    }

    public function notifications(Request $request)
    {
        return response()->json(
            AppNotification::where('user_id', $request->user()->id)
                ->whereNull('read_at')
                ->latest()
                ->get()
        );
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
     * All listings platform-wide, regardless of municipality or approval
     * status -- Super Admin's Listing Management is global, unlike the LGU's
     * municipality-scoped equivalent.
     */
    public function listings()
    {
        return response()->json(
            FingerlingListing::with(['sellerProfile.user', 'municipality', 'media'])
                ->latest()
                ->get()
        );
    }

    public function showListing(FingerlingListing $listing)
    {
        return response()->json($listing->load(['sellerProfile.user', 'municipality', 'media']));
    }

    public function updateListing(Request $request, FingerlingListing $listing)
    {
        $data = $request->validate([
            'species' => ['sometimes', 'string'],
            'scientific_name' => ['nullable', 'string'],
            'title' => ['sometimes', 'string'],
            'description' => ['nullable', 'string'],
            'quantity' => ['sometimes', 'integer', 'min:0'],
            'price_per_piece' => ['sometimes', 'numeric', 'min:0.01'],
            'average_size' => ['nullable', 'string'],
            'availability_status' => ['nullable', 'string'],
        ]);

        $listing->update($data);

        return response()->json($listing->fresh(['sellerProfile', 'municipality', 'media']));
    }

    public function approveListing(Request $request, FingerlingListing $listing)
    {
        $listing->update(['approval_status' => 'approved', 'rejection_reason' => null]);
        $listing->load(['sellerProfile.user', 'municipality']);
        SafeMailer::send($listing->sellerProfile?->user?->email, new ListingApprovedMail($listing));

        ActivityLog::record([
            'actor_id' => $request->user()->id,
            'actor_role' => 'super_admin',
            'action' => 'listing_approved',
            'target_user_id' => $listing->sellerProfile?->user_id,
            'municipality_id' => $listing->municipality_id,
            'description' => "Approved listing \"{$listing->title}\" for {$listing->sellerProfile?->hatchery_name}.",
        ]);

        return response()->json($listing);
    }

    public function rejectListing(Request $request, FingerlingListing $listing)
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $listing->update(['approval_status' => 'rejected', 'rejection_reason' => $data['reason'] ?? null]);
        $listing->load(['sellerProfile.user', 'municipality']);
        SafeMailer::send($listing->sellerProfile?->user?->email, new ListingRejectedMail($listing));

        ActivityLog::record([
            'actor_id' => $request->user()->id,
            'actor_role' => 'super_admin',
            'action' => 'listing_rejected',
            'target_user_id' => $listing->sellerProfile?->user_id,
            'municipality_id' => $listing->municipality_id,
            'description' => "Rejected listing \"{$listing->title}\" for {$listing->sellerProfile?->hatchery_name}".($data['reason'] ?? null ? " -- {$data['reason']}" : '').'.',
        ]);

        return response()->json($listing);
    }

    public function archiveListing(Request $request, FingerlingListing $listing)
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $listing->load('sellerProfile');
        $listing->update(['approval_status' => 'archived', 'rejection_reason' => $data['reason'] ?? null]);
        ListingModeration::notifySellerOfRemoval($listing, 'archived', $data['reason'] ?? null, $request->user());

        ActivityLog::record([
            'actor_id' => $request->user()->id,
            'actor_role' => 'super_admin',
            'action' => 'listing_archived',
            'target_user_id' => $listing->sellerProfile?->user_id,
            'municipality_id' => $listing->municipality_id,
            'description' => "Archived listing \"{$listing->title}\" for {$listing->sellerProfile?->hatchery_name}".($data['reason'] ?? null ? " -- {$data['reason']}" : '').'.',
        ]);

        return response()->json($listing->fresh(['sellerProfile', 'municipality']));
    }

    public function destroyListing(Request $request, FingerlingListing $listing)
    {
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
}
