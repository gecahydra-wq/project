<?php

namespace App\Support;

use App\Models\FingerlingListing;
use App\Models\Municipality;
use App\Models\Order;
use App\Models\Review;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Turns "recommend me a listing" / "which seller should I buy from" / "how
 * do I improve sales" / "which municipality needs help" into a ranked,
 * data-backed answer instead of letting Gemini guess. Every method here
 * queries the minimum data needed, computes a transparent weighted score,
 * and returns the ranked facts as a context string -- Gemini is only ever
 * asked to explain WHY those already-ranked results were selected, never to
 * invent or re-order them. Same ['subject','context','fallback'] shape as
 * AiDataQueryResolver so GeminiService can ground either one identically.
 */
class AiRecommendationEngine
{
    private const KEYWORDS = [
        'recommend', 'recommendation', 'best listing', 'best seller', 'best value', 'trusted seller',
        'cheapest', 'nearby seller', 'popular species', 'trending', 'which seller should i buy',
        'which listing do you recommend', 'give me a recommendation', 'give me recommendations',
        'suggest', 'top listing', 'top seller', 'restock', 'low-performing', 'low performing',
        'improve sales', 'improve my sales', 'sales trend', 'review insights', 'need assistance', 'needs assistance',
        'requiring review', 'require review', 'municipality trends', 'reports needing attention',
        'best-performing', 'best performing', 'needing improvement', 'needs improvement',
        'platform trends', 'approval efficiency', 'revenue insights', 'top-performing seller', 'top performing seller',
    ];

    /** A listing at or below this quantity counts as needing restocking. */
    private const LOW_STOCK_THRESHOLD = 50;

    public static function resolve(string $question, User $user, ?string $previousSubject): ?array
    {
        $lower = strtolower($question);

        if (!self::matchesAny($lower, self::KEYWORDS)) {
            return null;
        }

        return match ($user->role) {
            'buyer' => self::buyerRecommendations($lower, $user),
            'seller' => self::sellerRecommendations($lower, $user),
            'lgu_admin' => self::lguRecommendations($lower, $user),
            'super_admin' => self::superAdminRecommendations($lower),
            default => null,
        };
    }

    // ------------------------------------------------------------------
    // Buyer -- ranked listings/sellers to buy from.
    // ------------------------------------------------------------------

    private static function buyerRecommendations(string $lower, User $user): array
    {
        if (self::matchesAny($lower, ['popular species', 'trending'])) {
            return self::popularSpecies();
        }

        $species = self::detectSpecies($lower);
        $cheapestOnly = str_contains($lower, 'cheapest');
        $nearbyOnly = str_contains($lower, 'nearby');

        $listings = FingerlingListing::where('approval_status', 'approved')
            ->where('quantity', '>', 0)
            ->when($species, fn ($q) => $q->where('species', $species))
            ->when($nearbyOnly && $user->municipality_id, fn ($q) => $q->where('municipality_id', $user->municipality_id))
            ->with(['sellerProfile.municipality'])
            ->get();

        if ($listings->isEmpty()) {
            $context = 'There are no in-stock approved listings to recommend right now'.($species ? " for {$species}" : '').'.';

            return ['subject' => 'buyer_recommendations', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
        }

        if ($cheapestOnly) {
            $ranked = $listings->sortBy('price_per_piece')->values()->take(5);
            $lines = $ranked->map(fn ($l, $i) => self::describeListing($i + 1, $l).' -- price '.$l->price_per_piece.' per '.$l->unit_label.'.')->implode(' ');
            $context = "Cheapest listings, ranked by price: {$lines}";

            return ['subject' => 'buyer_recommendations', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
        }

        $scored = self::scoreListingsForBuyer($listings, $user->municipality_id)->take(5);
        $lines = $scored->values()->map(fn ($l, $i) => self::describeListing($i + 1, $l).sprintf(
            ' -- price %s/%s, rating %s/5 (%d reviews), %d completed orders, %s, %d in stock, score %.2f.',
            $l->price_per_piece,
            $l->unit_label,
            $l->sellerProfile->rating,
            $l->reviews_count,
            $l->completed_orders,
            $l->sellerProfile->status === 'verified' ? 'verified seller' : 'unverified seller',
            $l->quantity,
            $l->score
        ))->implode(' ');

        $context = 'Recommended listings, ranked by a balanced score of seller rating, review count, completed orders, stock availability, seller verification, listing freshness, and price competitiveness'
            .($nearbyOnly ? ' (limited to your own municipality)' : '')
            .": {$lines}";

        return ['subject' => 'buyer_recommendations', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
    }

    private static function scoreListingsForBuyer(Collection $listings, ?int $buyerMunicipalityId): Collection
    {
        $sellerIds = $listings->pluck('seller_profile_id')->unique();

        $completedOrders = Order::where('status', 'completed')
            ->whereIn('seller_profile_id', $sellerIds)
            ->selectRaw('seller_profile_id, count(*) as total')
            ->groupBy('seller_profile_id')
            ->pluck('total', 'seller_profile_id');

        $reviewCounts = Review::whereIn('seller_profile_id', $sellerIds)
            ->selectRaw('seller_profile_id, count(*) as total')
            ->groupBy('seller_profile_id')
            ->pluck('total', 'seller_profile_id');

        $maxOrders = max($completedOrders->max() ?? 0, 1);
        $maxReviews = max($reviewCounts->max() ?? 0, 1);
        $avgPrice = $listings->avg('price_per_piece') ?: 1;

        return $listings->map(function ($listing) use ($completedOrders, $reviewCounts, $maxOrders, $maxReviews, $avgPrice, $buyerMunicipalityId) {
            $listing->completed_orders = (int) ($completedOrders[$listing->seller_profile_id] ?? 0);
            $listing->reviews_count = (int) ($reviewCounts[$listing->seller_profile_id] ?? 0);

            $ratingScore = min(1, (float) $listing->sellerProfile->rating / 5);
            $ordersScore = $listing->completed_orders / $maxOrders;
            $reviewsScore = $listing->reviews_count / $maxReviews;
            $stockScore = min(1, $listing->quantity / 500);
            $reliabilityScore = $listing->sellerProfile->status === 'verified' ? 1 : 0.5;
            $daysOld = Carbon::parse($listing->created_at)->diffInDays(now());
            $freshnessScore = max(0, 1 - (min($daysOld, 60) / 60));
            $priceScore = $avgPrice > 0 ? max(0, min(1, 1 - (($listing->price_per_piece - $avgPrice) / $avgPrice))) : 0.5;

            $score = $ratingScore * 0.25
                + $ordersScore * 0.20
                + $reviewsScore * 0.15
                + $stockScore * 0.10
                + $reliabilityScore * 0.10
                + $freshnessScore * 0.10
                + $priceScore * 0.10;

            if ($buyerMunicipalityId && $listing->municipality_id === $buyerMunicipalityId) {
                $score += 0.05; // Personalization: slight boost for the buyer's own municipality.
            }

            $listing->score = round($score, 4);

            return $listing;
        })->sortByDesc('score');
    }

    private static function describeListing(int $rank, FingerlingListing $listing): string
    {
        $muni = $listing->sellerProfile->municipality?->name ?? 'unknown municipality';

        return "{$rank}) {$listing->species} from {$listing->sellerProfile->hatchery_name} ({$muni})";
    }

    private static function popularSpecies(): array
    {
        $rows = Order::where('orders.status', 'completed')
            ->join('listings', 'orders.listing_id', '=', 'listings.id')
            ->selectRaw('listings.species as species, sum(orders.quantity) as total, count(distinct orders.id) as orders')
            ->groupBy('listings.species')
            ->orderByDesc('total')
            ->take(3)
            ->get();

        if ($rows->isEmpty()) {
            $context = 'No species has any completed sales yet, so there\'s no popularity ranking available.';

            return ['subject' => 'buyer_recommendations', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
        }

        $lines = $rows->values()->map(fn ($r, $i) => ($i + 1).") {$r->species} -- {$r->total} pieces sold across {$r->orders} completed orders")->implode('; ');
        $context = "Most popular species by completed sales: {$lines}.";

        return ['subject' => 'buyer_recommendations', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
    }

    // ------------------------------------------------------------------
    // Seller -- business recommendations for the seller's own listings.
    // ------------------------------------------------------------------

    private static function sellerRecommendations(string $lower, User $user): ?array
    {
        $seller = SellerProfile::where('user_id', $user->id)->first();
        if (!$seller) {
            return null;
        }

        if (self::matchesAny($lower, ['restock', 'low stock', 'out of stock'])) {
            return self::sellerRestockRecommendation($seller);
        }

        if (self::matchesAny($lower, ['sales trend'])) {
            return self::sellerSalesTrend($seller);
        }

        if (self::matchesAny($lower, ['review insights'])) {
            return self::sellerReviewInsights($seller);
        }

        if (self::matchesAny($lower, ['low-performing', 'low performing'])) {
            return self::sellerLowPerformingListings($seller);
        }

        // Default: "which species perform best" / "how to improve sales" / general "give me recommendations".
        return self::sellerSpeciesPerformance($seller);
    }

    private static function sellerRestockRecommendation(SellerProfile $seller): array
    {
        $listings = FingerlingListing::where('seller_profile_id', $seller->id)
            ->where('approval_status', 'approved')
            ->where('quantity', '<=', self::LOW_STOCK_THRESHOLD)
            ->orderBy('quantity')
            ->get(['species', 'quantity']);

        if ($listings->isEmpty()) {
            $context = 'None of your active listings need restocking right now -- stock levels are healthy.';

            return ['subject' => 'seller_recommendations', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
        }

        $lines = $listings->map(fn ($l) => "{$l->species} ({$l->quantity} left)")->implode(', ');
        $context = "Restock recommendation, lowest stock first: {$lines}.";

        return ['subject' => 'seller_recommendations', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
    }

    private static function sellerSpeciesPerformance(SellerProfile $seller): array
    {
        $performing = Order::where('orders.seller_profile_id', $seller->id)
            ->where('orders.status', 'completed')
            ->join('listings', 'orders.listing_id', '=', 'listings.id')
            ->selectRaw('listings.species as species, sum(orders.quantity) as quantity, sum(orders.total_amount) as revenue')
            ->groupBy('listings.species')
            ->orderByDesc('revenue')
            ->get();

        $listedSpecies = FingerlingListing::where('seller_profile_id', $seller->id)->where('approval_status', 'approved')->pluck('species')->unique();
        $neverSold = $listedSpecies->diff($performing->pluck('species'));

        if ($performing->isEmpty() && $neverSold->isEmpty()) {
            $context = 'You have no approved listings yet, so there\'s no sales performance to rank.';

            return ['subject' => 'seller_recommendations', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
        }

        $ranked = $performing->values()->map(fn ($r, $i) => ($i + 1).") {$r->species} -- {$r->quantity} pieces sold, revenue {$r->revenue}")->implode('; ');
        $context = 'Your species performance, ranked by revenue: '.($ranked ?: 'no completed sales yet').'.';
        if ($neverSold->isNotEmpty()) {
            $context .= ' Never sold yet: '.$neverSold->implode(', ').'.';
        }

        return ['subject' => 'seller_recommendations', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
    }

    private static function sellerSalesTrend(SellerProfile $seller): array
    {
        $now = Carbon::now();
        $currentStart = $now->copy()->subDays(30);
        $priorStart = $now->copy()->subDays(60);

        $current = (float) Order::where('seller_profile_id', $seller->id)->where('status', 'completed')
            ->whereBetween('created_at', [$currentStart, $now])->sum('total_amount');
        $prior = (float) Order::where('seller_profile_id', $seller->id)->where('status', 'completed')
            ->whereBetween('created_at', [$priorStart, $currentStart])->sum('total_amount');

        $direction = match (true) {
            $current > $prior => 'up',
            $current < $prior => 'down',
            default => 'flat',
        };
        $context = "Your completed sales over the last 30 days total {$current}, versus {$prior} in the 30 days before that -- trend is {$direction}.";

        return ['subject' => 'seller_recommendations', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
    }

    private static function sellerReviewInsights(SellerProfile $seller): array
    {
        $lowRated = Review::with('buyer')->where('seller_profile_id', $seller->id)->where('rating', '<=', 3)->latest()->take(3)->get();
        $count = Review::where('seller_profile_id', $seller->id)->count();

        if ($count === 0) {
            $context = 'You have no reviews yet, so there are no review insights to share.';

            return ['subject' => 'seller_recommendations', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
        }

        if ($lowRated->isEmpty()) {
            $context = "You have {$count} reviews with an average rating of {$seller->rating}/5 and none below 4 stars -- no urgent review concerns.";

            return ['subject' => 'seller_recommendations', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
        }

        $lines = $lowRated->map(fn ($r) => "{$r->rating}/5".($r->comment ? " (\"{$r->comment}\")" : ''))->implode('; ');
        $context = "You have {$count} reviews averaging {$seller->rating}/5. Recent lower-rated reviews to review: {$lines}.";

        return ['subject' => 'seller_recommendations', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
    }

    private static function sellerLowPerformingListings(SellerProfile $seller): array
    {
        $soldListingIds = Order::where('seller_profile_id', $seller->id)->where('status', 'completed')->pluck('listing_id')->unique();

        $unsold = FingerlingListing::where('seller_profile_id', $seller->id)
            ->where('approval_status', 'approved')
            ->whereNotIn('id', $soldListingIds)
            ->oldest()
            ->get(['species', 'created_at']);

        if ($unsold->isEmpty()) {
            $context = 'Every one of your approved listings has at least one completed sale -- no low-performing listings right now.';

            return ['subject' => 'seller_recommendations', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
        }

        $lines = $unsold->map(fn ($l) => "{$l->species} (listed ".Carbon::parse($l->created_at)->diffInDays(now()).' days ago, 0 sales)')->implode(', ');
        $context = "Low-performing listings with zero completed sales: {$lines}.";

        return ['subject' => 'seller_recommendations', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
    }

    // ------------------------------------------------------------------
    // LGU Admin -- moderation-focused recommendations, own municipality only.
    // ------------------------------------------------------------------

    private static function lguRecommendations(string $lower, User $user): array
    {
        $municipalityId = $user->municipality_id;

        if (self::matchesAny($lower, ['requiring review', 'require review'])) {
            $listings = FingerlingListing::where('municipality_id', $municipalityId)->where('approval_status', 'pending')
                ->oldest()->take(5)->get(['species', 'created_at']);

            if ($listings->isEmpty()) {
                $context = 'No listings in your municipality currently need review.';

                return ['subject' => 'lgu_recommendations', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
            }

            $lines = $listings->map(fn ($l) => "{$l->species} (waiting ".Carbon::parse($l->created_at)->diffInDays(now()).' days)')->implode(', ');
            $context = "Listings requiring review, oldest first: {$lines}.";

            return ['subject' => 'lgu_recommendations', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
        }

        if (self::matchesAny($lower, ['municipality trends'])) {
            return self::lguMunicipalityTrends($municipalityId);
        }

        if (self::matchesAny($lower, ['reports needing attention'])) {
            $stalePending = FingerlingListing::where('municipality_id', $municipalityId)
                ->where('approval_status', 'pending')
                ->where('created_at', '<=', now()->subDays(3))
                ->count();
            $context = "{$stalePending} listings in your municipality have been pending approval for more than 3 days and need attention.";

            return ['subject' => 'lgu_recommendations', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
        }

        // Default: "sellers needing assistance".
        $sellers = SellerProfile::where('municipality_id', $municipalityId)
            ->where('status', 'verified')
            ->get()
            ->filter(function ($seller) {
                $completedOrders = Order::where('seller_profile_id', $seller->id)->where('status', 'completed')
                    ->where('created_at', '>=', now()->subDays(60))->count();

                return (float) $seller->rating < 3.0 || $completedOrders === 0;
            })
            ->take(5);

        if ($sellers->isEmpty()) {
            $context = 'No verified sellers in your municipality currently show signs of needing assistance (low rating or no recent sales).';

            return ['subject' => 'lgu_recommendations', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
        }

        $lines = $sellers->map(fn ($s) => "{$s->hatchery_name} (rating {$s->rating}/5)")->implode(', ');
        $context = "Sellers who may need assistance (low rating or no completed orders in the last 60 days): {$lines}.";

        return ['subject' => 'lgu_recommendations', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
    }

    private static function lguMunicipalityTrends(int $municipalityId): array
    {
        $municipalityCount = Municipality::count();
        $mySellers = SellerProfile::where('municipality_id', $municipalityId)->count();
        $platformSellers = SellerProfile::count();
        $avgSellersPerMunicipality = $municipalityCount > 0 ? round($platformSellers / $municipalityCount, 1) : 0;

        $myPending = FingerlingListing::where('municipality_id', $municipalityId)->where('approval_status', 'pending')->count();
        $platformPending = FingerlingListing::where('approval_status', 'pending')->count();
        $avgPendingPerMunicipality = $municipalityCount > 0 ? round($platformPending / $municipalityCount, 1) : 0;

        $context = "Your municipality has {$mySellers} sellers (platform average is {$avgSellersPerMunicipality} per municipality) and {$myPending} listings pending approval (platform average is {$avgPendingPerMunicipality}).";

        return ['subject' => 'lgu_recommendations', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
    }

    // ------------------------------------------------------------------
    // Super Admin -- platform-wide executive recommendations.
    // ------------------------------------------------------------------

    private static function superAdminRecommendations(string $lower): array
    {
        if (self::matchesAny($lower, ['needing improvement', 'needs improvement'])) {
            return self::municipalityRanking(ascending: true, label: 'needing improvement (lowest completed sales)');
        }

        if (self::matchesAny($lower, ['best-performing', 'best performing', 'highest sales', 'most sales'])) {
            return self::municipalityRanking(ascending: false, label: 'best-performing (highest completed sales)');
        }

        if (self::matchesAny($lower, ['top-performing seller', 'top performing seller', 'top seller'])) {
            return self::topSellersPlatformWide();
        }

        if (self::matchesAny($lower, ['approval efficiency'])) {
            return self::approvalEfficiency();
        }

        if (self::matchesAny($lower, ['platform trends', 'revenue insights'])) {
            return self::platformRevenueTrend();
        }

        // Default general recommendation ask -- top-performing municipalities.
        return self::municipalityRanking(ascending: false, label: 'top-performing (highest completed sales)');
    }

    private static function municipalityRanking(bool $ascending, string $label): array
    {
        $rows = Municipality::all()->map(function ($m) {
            $sales = (float) Order::where('status', 'completed')
                ->whereHas('sellerProfile', fn ($q) => $q->where('municipality_id', $m->id))
                ->sum('total_amount');
            $pending = FingerlingListing::where('municipality_id', $m->id)->where('approval_status', 'pending')->count();

            return (object) ['name' => $m->name, 'sales' => $sales, 'pending' => $pending];
        });

        $sorted = $ascending ? $rows->sortBy('sales') : $rows->sortByDesc('sales');
        $top = $sorted->take(3)->values();

        $lines = $top->map(fn ($r, $i) => ($i + 1).") {$r->name} -- completed sales {$r->sales}, {$r->pending} listings pending approval")->implode('; ');
        $context = "Municipalities {$label}: {$lines}.";

        return ['subject' => 'super_admin_recommendations', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
    }

    private static function topSellersPlatformWide(): array
    {
        $rows = Order::where('orders.status', 'completed')
            ->join('seller_profiles', 'orders.seller_profile_id', '=', 'seller_profiles.id')
            ->selectRaw('seller_profiles.hatchery_name as name, sum(orders.total_amount) as revenue, count(*) as orders')
            ->groupBy('seller_profiles.hatchery_name')
            ->orderByDesc('revenue')
            ->take(5)
            ->get();

        if ($rows->isEmpty()) {
            $context = 'No seller has any completed sales yet.';

            return ['subject' => 'super_admin_recommendations', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
        }

        $lines = $rows->values()->map(fn ($r, $i) => ($i + 1).") {$r->name} -- revenue {$r->revenue} across {$r->orders} completed orders")->implode('; ');
        $context = "Top-performing sellers platform-wide, ranked by completed revenue: {$lines}.";

        return ['subject' => 'super_admin_recommendations', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
    }

    /**
     * Approval-latency proxy: for already-approved listings there's no
     * dedicated "approved_at" column, so the gap between created_at and
     * updated_at (the moment the approval status flips) stands in for how
     * long each municipality's queue took.
     */
    private static function approvalEfficiency(): array
    {
        $rows = Municipality::all()->map(function ($m) {
            $approved = FingerlingListing::where('municipality_id', $m->id)->where('approval_status', 'approved')->get(['created_at', 'updated_at']);
            if ($approved->isEmpty()) {
                return null;
            }
            $avgHours = round($approved->avg(fn ($l) => Carbon::parse($l->created_at)->diffInHours(Carbon::parse($l->updated_at))), 1);

            return (object) ['name' => $m->name, 'avg_hours' => $avgHours];
        })->filter()->sortByDesc('avg_hours')->values();

        if ($rows->isEmpty()) {
            $context = 'No municipality has any approved listings yet, so approval efficiency can\'t be measured.';

            return ['subject' => 'super_admin_recommendations', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
        }

        $lines = $rows->take(5)->map(fn ($r) => "{$r->name}: {$r->avg_hours}h average")->implode(', ');
        $context = "Average time from listing submission to approval, slowest first: {$lines}.";

        return ['subject' => 'super_admin_recommendations', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
    }

    private static function platformRevenueTrend(): array
    {
        $now = Carbon::now();
        $current = (float) Order::where('status', 'completed')->whereBetween('created_at', [$now->copy()->subDays(30), $now])->sum('total_amount');
        $prior = (float) Order::where('status', 'completed')->whereBetween('created_at', [$now->copy()->subDays(60), $now->copy()->subDays(30)])->sum('total_amount');

        $direction = match (true) {
            $current > $prior => 'up',
            $current < $prior => 'down',
            default => 'flat',
        };
        $context = "Platform-wide completed sales over the last 30 days total {$current}, versus {$prior} in the prior 30 days -- trend is {$direction}.";

        return ['subject' => 'super_admin_recommendations', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
    }

    // ------------------------------------------------------------------

    private static function matchesAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function detectSpecies(string $lower): ?string
    {
        $candidates = FingerlingListing::distinct()->pluck('species');

        return $candidates->first(fn ($s) => str_contains($lower, strtolower($s)));
    }
}
