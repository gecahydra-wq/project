<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BuyerRating;
use App\Models\FingerlingListing;
use App\Models\ModerationLog;
use App\Models\Municipality;
use App\Models\Order;
use App\Models\Review;
use App\Models\SellerProfile;
use App\Models\Settlement;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Support\AnalyticsPeriod;
use App\Support\ReportExporter;
use App\Support\RevenueReport;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlatformController extends Controller
{
    public function lguAdmins()
    {
        return response()->json(User::where('role', 'lgu_admin')->with('municipality')->get());
    }

    public function municipalities()
    {
        return response()->json(Municipality::orderBy('name')->get());
    }

    public function sellers()
    {
        return response()->json(
            SellerProfile::with(['user', 'municipality', 'listings'])->get()
        );
    }

    public function superReports(Request $request)
    {
        ['period' => $period, 'start' => $start, 'end' => $end, 'unit' => $unit] = AnalyticsPeriod::resolve($request->query('period'));

        $listingsInRange = fn () => FingerlingListing::whereBetween('listings.created_at', [$start, $end]);
        $sellersInRange = fn () => SellerProfile::whereBetween('seller_profiles.created_at', [$start, $end]);
        $orderRows = Order::whereBetween('orders.created_at', [$start, $end])->get(['created_at', 'total_amount']);
        $settlementsInRange = fn () => Settlement::whereBetween('settled_at', [$start, $end]);

        // Moderation report filters -- role: buyer/seller/lgu_admin, status:
        // active/suspended/reinstated. 'suspended'/'reinstated' map directly
        // onto ModerationLog::action; 'active' matches log rows whose
        // resulting_status left the account unrestricted (i.e. every
        // reinstatement, since a suspend action always results in
        // suspended/disabled).
        $moderationRole = $request->query('moderation_role');
        $moderationStatus = $request->query('moderation_status');
        $moderationLogs = ModerationLog::whereBetween('moderation_logs.created_at', [$start, $end]);
        if (in_array($moderationRole, ['buyer', 'seller', 'lgu_admin'], true)) {
            $moderationLogs->where('role', $moderationRole);
        }
        match ($moderationStatus) {
            'suspended' => $moderationLogs->where('action', 'suspended'),
            'reinstated' => $moderationLogs->where('action', 'reinstated'),
            'active' => $moderationLogs->whereIn('resulting_status', ['active', 'verified', 'pending']),
            default => null,
        };

        return response()->json([
            // Existing all-time totals -- unchanged, for backwards compatibility with the current Reports stat cards.
            'total_lgus' => User::where('role', 'lgu_admin')->distinct('municipality_id')->count('municipality_id'),
            'total_sellers' => SellerProfile::count(),
            'total_buyers' => User::where('role', 'buyer')->count(),
            'total_listings' => FingerlingListing::count(),
            'total_transactions' => Order::count(),
            // Withdrawal requests still awaiting Super Admin action (pending
            // approval, or approved but not yet marked Paid) -- NOT payments
            // awaiting LGU earnings approval, which is a separate queue (see
            // LguController::pendingEarnings) with no payout involved.
            'pending_payouts' => WithdrawalRequest::whereIn('status', ['pending', 'approved'])->count(),
            'transactions' => Order::with(['listing', 'payment'])->latest()->take(10)->get(),
            'lgu_admins' => User::where('role', 'lgu_admin')->with('municipality')->get(),

            // New: period-scoped chart data for the Reports graphs.
            'period' => $period,
            'range' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'listings_by_status' => $listingsInRange()->selectRaw('approval_status, count(*) as total')->groupBy('approval_status')->get(),
            'listings_by_species' => $listingsInRange()->selectRaw('species, count(*) as total')->groupBy('species')->orderByDesc('total')->get(),
            'sellers_by_status' => $sellersInRange()->selectRaw('status, count(*) as total')->groupBy('status')->get(),
            'orders_over_time' => AnalyticsPeriod::bucketize($start, $end, $unit, $orderRows),
            'listings_by_municipality' => $listingsInRange()
                ->join('municipalities', 'listings.municipality_id', '=', 'municipalities.id')
                ->selectRaw('municipalities.name as municipality, count(*) as total')
                ->groupBy('municipalities.name')->orderByDesc('total')->get(),
            'sellers_by_municipality' => $sellersInRange()
                ->join('municipalities', 'seller_profiles.municipality_id', '=', 'municipalities.id')
                ->selectRaw('municipalities.name as municipality, count(*) as total')
                ->groupBy('municipalities.name')->orderByDesc('total')->get(),
            'orders_by_municipality' => Order::whereBetween('orders.created_at', [$start, $end])
                ->join('seller_profiles', 'orders.seller_profile_id', '=', 'seller_profiles.id')
                ->join('municipalities', 'seller_profiles.municipality_id', '=', 'municipalities.id')
                ->selectRaw('municipalities.name as municipality, count(*) as total')
                ->groupBy('municipalities.name')->orderByDesc('total')->get(),

            // Marketplace Revenue -- platform-wide. Gross Marketplace Revenue
            // is settlement-based (total buyer payment volume). Platform
            // Revenue is deliberately realized-only: it reflects PAID
            // withdrawals, not settlements -- see RevenueReport's docblock.
            // Seller Share and LGU Share (used below) stay settlement-based,
            // since those two realize at LGU-approval time, unchanged.
            'revenue_cards' => RevenueReport::platformCards(),
            'platform_revenue_over_time' => RevenueReport::platformRevenueOverTime($start, $end, $unit),
            'gross_revenue_over_time' => RevenueReport::bucketize($start, $end, $unit, (clone $settlementsInRange())->get(['settled_at', 'gross_amount']), 'gross_amount'),
            'revenue_by_municipality' => RevenueReport::platformRevenueByMunicipality($start, $end),
            'revenue_by_species' => RevenueReport::platformRevenueBySpecies($start, $end),
            'revenue_by_seller' => $settlementsInRange()
                ->join('seller_profiles', 'settlements.seller_profile_id', '=', 'seller_profiles.id')
                ->selectRaw('seller_profiles.hatchery_name as seller, sum(settlements.seller_share) as amount, count(*) as total')
                ->groupBy('seller_profiles.hatchery_name')->orderByDesc('amount')->get(),
            'commission_distribution' => [
                ['label' => 'Seller Share', 'amount' => round((float) (clone $settlementsInRange())->sum('seller_share'), 2)],
                ['label' => 'LGU Share', 'amount' => round((float) (clone $settlementsInRange())->sum('lgu_share'), 2)],
                ['label' => 'Platform Payout Fee', 'amount' => RevenueReport::realizedPlatformRevenueTotal($start, $end)],
            ],

            // Global account moderation reporting -- filterable by role and
            // status, bucketized over the same period as the rest of this report.
            'moderation_summary' => [
                'active_buyers' => User::where('role', 'buyer')->where('status', '!=', 'suspended')->count(),
                'suspended_buyers' => User::where('role', 'buyer')->where('status', 'suspended')->count(),
                'active_sellers' => SellerProfile::where('status', '!=', 'suspended')->count(),
                'suspended_sellers' => SellerProfile::where('status', 'suspended')->count(),
                'active_lgu_admins' => User::where('role', 'lgu_admin')->where('status', '!=', 'disabled')->count(),
                'suspended_lgu_admins' => User::where('role', 'lgu_admin')->where('status', 'disabled')->count(),
            ],
            'moderation_filters' => ['role' => $moderationRole, 'status' => $moderationStatus],
            'moderation_actions_over_time' => AnalyticsPeriod::bucketize($start, $end, $unit, (clone $moderationLogs)->get(['moderation_logs.created_at'])),
            'moderation_log' => (clone $moderationLogs)->with(['user:id,name,role', 'moderator:id,name'])->latest()->take(50)->get(),
        ]);
    }

    /**
     * Reviews & Ratings for one municipality (LGU) -- BOTH directions of
     * feedback in one place: buyer_reviews are buyers reviewing sellers, and
     * seller_ratings are sellers rating buyers (see App\Models\BuyerRating).
     * Both are scoped by the SELLER's municipality (the party in this LGU),
     * exactly like every other LGU-scoped view.
     */
    public function lguReviews(Request $request)
    {
        $municipalityId = $request->user()->municipality_id;

        return response()->json([
            'buyer_reviews' => Review::whereHas('sellerProfile', fn ($q) => $q->where('municipality_id', $municipalityId))
                ->with(['buyer', 'sellerProfile.user', 'order.listing'])
                ->latest()
                ->get(),
            'seller_ratings' => BuyerRating::whereHas('sellerProfile', fn ($q) => $q->where('municipality_id', $municipalityId))
                ->with(['buyer', 'sellerProfile.user', 'order.listing'])
                ->latest()
                ->get(),
        ]);
    }

    /**
     * Platform-wide Reviews & Ratings for the Super Admin -- the unscoped
     * counterpart of lguReviews() above. Same two directions (buyer reviews of
     * sellers + seller ratings of buyers), across every municipality.
     */
    public function superReviews()
    {
        return response()->json([
            'buyer_reviews' => Review::with(['buyer', 'sellerProfile.user', 'sellerProfile.municipality', 'order.listing'])
                ->latest()
                ->get(),
            'seller_ratings' => BuyerRating::with(['buyer', 'sellerProfile.user', 'sellerProfile.municipality', 'order.listing'])
                ->latest()
                ->get(),
        ]);
    }

    public function lguReports(Request $request)
    {
        $municipalityId = $request->user()->municipality_id;
        ['period' => $period, 'start' => $start, 'end' => $end, 'unit' => $unit] = AnalyticsPeriod::resolve($request->query('period'));

        $listingsInRange = fn () => FingerlingListing::where('municipality_id', $municipalityId)->whereBetween('created_at', [$start, $end]);
        $sellersInRange = fn () => SellerProfile::where('municipality_id', $municipalityId)->whereBetween('created_at', [$start, $end]);
        $orderRows = Order::whereHas('sellerProfile', fn ($q) => $q->where('municipality_id', $municipalityId))
            ->whereBetween('orders.created_at', [$start, $end])
            ->get(['created_at', 'total_amount']);
        $settlementsInRange = fn () => Settlement::where('settlements.municipality_id', $municipalityId)->whereBetween('settled_at', [$start, $end]);

        return response()->json([
            // Existing all-time totals -- unchanged, for backwards compatibility with the current Reports stat cards.
            'registered_sellers' => SellerProfile::where('municipality_id', $municipalityId)->count(),
            'buyers' => User::where('role', 'buyer')->where('municipality_id', $municipalityId)->count(),
            'listings' => FingerlingListing::where('municipality_id', $municipalityId)->count(),
            'pending_approvals' => FingerlingListing::where('municipality_id', $municipalityId)->where('approval_status', 'pending')->count(),

            // New: period-scoped chart data for the Reports graphs.
            'period' => $period,
            'range' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'listings_by_status' => $listingsInRange()->selectRaw('approval_status, count(*) as total')->groupBy('approval_status')->get(),
            'listings_by_species' => $listingsInRange()->selectRaw('species, count(*) as total')->groupBy('species')->orderByDesc('total')->get(),
            'sellers_by_status' => $sellersInRange()->selectRaw('status, count(*) as total')->groupBy('status')->get(),
            'orders_over_time' => AnalyticsPeriod::bucketize($start, $end, $unit, $orderRows),

            // Municipality Revenue -- this LGU's own settled share only.
            // lgu_revenue_over_time doubles as the "Completed Orders" chart
            // source on the frontend: each bucket already carries both the
            // LGU-share amount and the settled-order count for that period.
            // revenue_cards now also includes available_balance/total_withdrawn
            // (see RevenueReport::municipalityCards -> App\Support\LguWallet).
            'revenue_cards' => RevenueReport::municipalityCards($municipalityId),
            'lgu_revenue_over_time' => RevenueReport::bucketize($start, $end, $unit, (clone $settlementsInRange())->get(['settled_at', 'lgu_share']), 'lgu_share'),
            // Withdrawal Trends -- a distinct time axis from the revenue
            // chart above: this buckets by when the municipality was PAID
            // out, not when the underlying orders were settled.
            'lgu_withdrawal_trends' => RevenueReport::lguWithdrawalTrends($municipalityId, $start, $end, $unit),
            'revenue_by_species' => $settlementsInRange()
                ->join('orders', 'settlements.order_id', '=', 'orders.id')
                ->join('listings', 'orders.listing_id', '=', 'listings.id')
                ->selectRaw('listings.species as species, sum(settlements.lgu_share) as amount, count(*) as total')
                ->groupBy('listings.species')->orderByDesc('amount')->get(),
            'revenue_by_seller' => $settlementsInRange()
                ->join('seller_profiles', 'settlements.seller_profile_id', '=', 'seller_profiles.id')
                ->selectRaw('seller_profiles.hatchery_name as seller, sum(settlements.lgu_share) as amount, count(*) as total')
                ->groupBy('seller_profiles.hatchery_name')->orderByDesc('amount')->get(),
        ]);
    }

    /**
     * Export Reports (LGU) -- Sales/Revenue/Seller, PDF or Excel. Reuses the
     * exact same period resolution as lguReports() above and the same
     * municipality scoping as every other LGU-scoped query in this app; see
     * App\Support\ReportExporter for the table-building/rendering logic
     * itself (no duplicate business-logic queries here).
     */
    public function exportLguReport(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(ReportExporter::LGU_TYPES)],
            'format' => ['required', Rule::in(['pdf', 'xlsx'])],
        ]);

        $municipalityId = $request->user()->municipality_id;
        ['start' => $start, 'end' => $end] = AnalyticsPeriod::resolve($request->query('period'));

        $table = ReportExporter::buildLguTable($data['type'], $municipalityId, $start, $end);

        return $data['format'] === 'pdf'
            ? ReportExporter::toPdf($table, "{$start->toDateString()} to {$end->toDateString()}")
            : ReportExporter::toExcel($table);
    }

    /**
     * Export Reports (Super Admin) -- Marketplace/Municipality Revenue,
     * Buyer/Seller Statistics, Orders, Listings, Payouts, PDF or Excel. See
     * exportLguReport() above for the same reuse principle.
     */
    public function exportSuperReport(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(ReportExporter::SUPER_ADMIN_TYPES)],
            'format' => ['required', Rule::in(['pdf', 'xlsx'])],
        ]);

        ['start' => $start, 'end' => $end] = AnalyticsPeriod::resolve($request->query('period'));

        $table = ReportExporter::buildSuperAdminTable($data['type'], $start, $end);

        return $data['format'] === 'pdf'
            ? ReportExporter::toPdf($table, "{$start->toDateString()} to {$end->toDateString()}")
            : ReportExporter::toExcel($table);
    }
}
