<?php

namespace App\Support;

use App\Models\AppNotification;
use App\Models\FingerlingListing;
use App\Models\LguWithdrawalRequest;
use App\Models\Message;
use App\Models\ModerationLog;
use App\Models\Municipality;
use App\Models\MockPayment;
use App\Models\Order;
use App\Models\Review;
use App\Models\SellerProfile;
use App\Models\Settlement;
use App\Models\User;
use App\Models\WithdrawalRequest;

/**
 * Resolves a question against the live AbaiMarket database when it asks for
 * a concrete, fetchable fact -- role-scoped so a Buyer never sees seller
 * financials, an LGU Admin never sees another municipality's data, and a
 * Seller never sees another seller's wallet. Runs before AiIntentClassifier
 * -- if this returns null, the caller falls through to the scripted
 * knowledge-base flow completely unchanged.
 */
class AiDataQueryResolver
{
    /**
     * Species known even with zero current listings (so "who sells X"
     * degrades to "no seller currently lists X" instead of silently
     * falling through to an off-topic refusal), merged with whatever is
     * actually in the listings table.
     */
    private const KNOWN_SPECIES = ['Tilapia', 'Bangus', 'Grouper', 'Catfish', 'Sea Bass', 'Carp'];

    private const COUNT_TRIGGERS = [
        'how many', 'number of', 'total number', 'count of', 'registered', // English
        'ilan', 'nakarehistro', // Tagalog
        'pila', 'naa', // Bisaya
    ];

    /**
     * A listing at or below this quantity is "low stock" for a seller's own
     * restocking questions. There's no stored threshold anywhere else in the
     * app, so this is a reasonable operational default, not a derived fact.
     */
    private const LOW_STOCK_THRESHOLD = 50;

    public static function resolve(string $question, User $user, ?string $previousSubject): ?array
    {
        $lower = strtolower($question);

        // Order Number lookup -- checked first since it's an unambiguous,
        // role-agnostic reference (e.g. "what is the status of order
        // FG-AB12CD?"). Resolves against the live database and is strictly
        // scoped per role (see resolveOrderLookup) before anything is
        // returned to Gemini, so a Buyer/Seller/LGU Admin can never learn
        // about a transaction outside their own permissions.
        if (preg_match('/\bFG-[A-Z0-9]{4,12}\b/i', $question, $matches)) {
            return self::resolveOrderLookup($matches[0], $user);
        }

        // Role-agnostic own-account facts every authenticated role can ask.
        if (self::matchesAny($lower, ['how many notification', 'notifications do i have', 'unread notification', 'my notifications'])) {
            return self::notificationCount($user);
        }
        if (self::matchesAny($lower, ['who did i last message', 'last messaged', 'who did i message', 'last person i messaged'])) {
            return self::lastMessaged($user);
        }
        if (self::matchesAny($lower, ['unread message', 'how many messages'])) {
            return self::unreadMessageCount($user);
        }

        $roleResult = match ($user->role) {
            'buyer' => self::resolveBuyer($lower, $user),
            'seller' => self::resolveSeller($lower, $user),
            'lgu_admin' => self::resolveLguAdmin($lower, $user),
            'super_admin' => self::resolveSuperAdmin($lower, $user),
            default => null,
        };
        if ($roleResult !== null) {
            return $roleResult;
        }

        return self::resolveSharedMarketplace($lower, $user, $previousSubject);
    }

    // ------------------------------------------------------------------
    // Order Number lookup -- shared by every role. Reuses
    // App\Support\OrderTransactionPresenter/OrderTimeline so the AI's answer
    // always agrees with what the Order Details page shows.
    // ------------------------------------------------------------------

    private static function resolveOrderLookup(string $orderNumber, User $user): array
    {
        $orderNumber = strtoupper($orderNumber);
        $order = Order::with(['sellerProfile'])->where('order_number', $orderNumber)->first();

        $permitted = $order && match ($user->role) {
            'buyer' => $order->buyer_id === $user->id,
            'seller' => $order->seller_profile_id === (SellerProfile::where('user_id', $user->id)->value('id')),
            'lgu_admin' => $order->sellerProfile?->municipality_id === $user->municipality_id,
            'super_admin' => true,
            default => false,
        };

        if (! $permitted) {
            // Deliberately identical whether the order doesn't exist or the
            // caller simply isn't allowed to see it -- never confirms or
            // denies another party's order number exists.
            $context = "Order {$orderNumber} was not found, or you don't have permission to view it.";

            return [
                'subject' => 'order_lookup_denied',
                'context' => $context,
                'fallback' => [
                    'English' => $context,
                    'Tagalog' => "Hindi nahanap ang Order {$orderNumber}, o wala kang pahintulot na tingnan ito.",
                    'Bisaya' => "Wala makit-i ang Order {$orderNumber}, o wala kay permiso nga makita kini.",
                ],
            ];
        }

        $detail = OrderTransactionPresenter::present($order, $user->role);
        $stages = $detail['timeline']['stages'];
        $currentStage = collect($stages)->last(fn ($stage) => $stage['reached'])['label'] ?? $stages[0]['label'];
        $terminal = $detail['timeline']['terminal_status'];

        $context = sprintf(
            'Order %s: %s x%s from %s, ordered by %s. Payment status: %s. Order status: %s. Delivery status: %s. Current progress: %s%s. Total amount: ₱%s.',
            $detail['order_number'],
            $detail['listing']['species'] ?? $detail['listing']['title'] ?? 'an item',
            $detail['quantity'],
            $detail['seller']['hatchery_name'] ?? 'an unknown seller',
            $detail['buyer']['name'] ?? 'an unknown buyer',
            $detail['payment_status'] ?? 'unknown',
            $detail['order_status'],
            $detail['delivery_status'],
            $currentStage,
            $terminal ? " ({$terminal})" : '',
            number_format($detail['total_amount'], 2)
        );

        return [
            'subject' => 'order_lookup',
            'context' => $context,
            'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context],
        ];
    }

    // ------------------------------------------------------------------
    // Buyer
    // ------------------------------------------------------------------

    private static function resolveBuyer(string $lower, User $user): ?array
    {
        if (self::matchesAny($lower, ['my latest order', 'my last order', 'my most recent order'])) {
            return self::latestOrderForBuyer($user);
        }
        if (self::matchesAny($lower, ['available balance', 'pending balance', 'my wallet', 'my balance'])) {
            return self::walletInapplicableForBuyer();
        }

        return null;
    }

    private static function latestOrderForBuyer(User $user): array
    {
        $order = Order::with(['listing', 'sellerProfile'])->where('buyer_id', $user->id)->latest()->first();

        if (!$order) {
            $context = 'You have no orders yet.';

            return ['subject' => 'latest_order', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => 'Wala ka pang order.', 'Bisaya' => 'Wala ka pay order.']];
        }

        $species = $order->listing?->species ?? 'an item';
        $seller = $order->sellerProfile?->hatchery_name ?? 'a seller';
        $context = "Your latest order is for {$order->quantity} {$species} from {$seller}, currently {$order->status}, totaling {$order->total_amount}.";

        return [
            'subject' => 'latest_order',
            'context' => $context,
            'fallback' => [
                'English' => $context,
                'Tagalog' => "Ang huli mong order ay {$order->quantity} {$species} mula sa {$seller}, kasalukuyang {$order->status}, na nagkakahalaga ng {$order->total_amount}.",
                'Bisaya' => "Ang imong pinakabag-o nga order kay {$order->quantity} {$species} gikan sa {$seller}, karon {$order->status}, nga kantidad {$order->total_amount}.",
            ],
        ];
    }

    private static function walletInapplicableForBuyer(): array
    {
        $context = "Buyers don't have a wallet or balance on AbaiMarket -- your payments go through PayMongo checkout directly. Wallets (available/pending balance) are a Seller-only feature for tracking their earnings.";

        return [
            'subject' => 'wallet_inapplicable',
            'context' => $context,
            'fallback' => [
                'English' => $context,
                'Tagalog' => 'Walang wallet o balance ang mga Buyer sa AbaiMarket -- direktang dumadaan ang bayad mo sa PayMongo checkout. Ang Wallet (available/pending balance) ay feature lamang ng Seller para subaybayan ang kanilang kita.',
                'Bisaya' => 'Walay wallet o balance ang mga Buyer sa AbaiMarket -- direkta nga moagi ang imong bayad sa PayMongo checkout. Ang Wallet (available/pending balance) usa ka feature ra sa Seller para subayon ang ilang kita.',
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Seller
    // ------------------------------------------------------------------

    private static function resolveSeller(string $lower, User $user): ?array
    {
        $seller = SellerProfile::where('user_id', $user->id)->first();
        if (!$seller) {
            return null;
        }

        if (self::matchesAny($lower, [
            'available balance', 'pending balance', 'processing amount', 'my wallet', 'my balance',
            'how much can i withdraw', 'total earnings', 'how much did i earn', 'withdrawn amount',
        ])) {
            return self::sellerWallet($seller);
        }

        if (self::matchesAny($lower, ['low stock', 'need restocking', 'need to restock', 'restock', 'out of stock'])) {
            return self::sellerStockAlerts($seller);
        }

        if (self::matchesAny($lower, ['active listing', 'how many listings', 'pending listing', 'rejected listing', 'archived listing', 'my listings'])) {
            return self::sellerListingCounts($lower, $seller);
        }

        if (self::matchesAny($lower, ['my latest order', 'my last order', 'my most recent order'])) {
            return self::latestOrderForSeller($seller);
        }

        if (self::matchesAny($lower, ['who left me a review', 'my reviews', 'my rating', 'my star rating'])) {
            return self::sellerReviews($seller);
        }

        if (self::matchesAny($lower, ['which listing sells the most', 'best selling listing', 'top selling listing', 'which listing performs best'])) {
            return self::sellerBestSellingListing($seller);
        }

        if (self::matchesAny($lower, self::COUNT_TRIGGERS) && str_contains($lower, 'order')) {
            return self::sellerOrderCount($seller);
        }

        return null;
    }

    private static function sellerWallet(SellerProfile $seller): array
    {
        $w = SellerWallet::summary($seller);
        $context = "Your wallet (based on your ".CommissionCalculator::SELLER_PERCENT."% Seller Share of each settled order): Pending Balance {$w['pending_balance']}, Processing Amount {$w['processing_amount']}, Available Balance {$w['available_balance']}, Total Earnings {$w['total_earnings']}, Withdrawn Amount {$w['withdrawn_amount']}. Note: a ".CommissionCalculator::WITHDRAWAL_FEE_PERCENT."% platform payout fee is deducted whenever you withdraw, so you'll receive slightly less than the amount you request.";

        return [
            'subject' => 'seller_wallet',
            'context' => $context,
            'fallback' => [
                'English' => $context,
                'Tagalog' => "Ang wallet mo: Pending Balance {$w['pending_balance']}, Processing Amount {$w['processing_amount']}, Available Balance {$w['available_balance']}, Total Earnings {$w['total_earnings']}, Withdrawn Amount {$w['withdrawn_amount']}.",
                'Bisaya' => "Ang imong wallet: Pending Balance {$w['pending_balance']}, Processing Amount {$w['processing_amount']}, Available Balance {$w['available_balance']}, Total Earnings {$w['total_earnings']}, Withdrawn Amount {$w['withdrawn_amount']}.",
            ],
        ];
    }

    private static function sellerStockAlerts(SellerProfile $seller): array
    {
        $listings = FingerlingListing::where('seller_profile_id', $seller->id)->where('approval_status', 'approved')->get();
        $outOfStock = $listings->where('quantity', '<=', 0)->pluck('species')->implode(', ');
        $lowStock = $listings->whereBetween('quantity', [1, self::LOW_STOCK_THRESHOLD])->pluck('species')->implode(', ');

        if (!$outOfStock && !$lowStock) {
            $context = 'None of your active listings are low on stock right now.';

            return ['subject' => 'seller_stock_alerts', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
        }

        $parts = [];
        if ($outOfStock) {
            $parts[] = "out of stock: {$outOfStock}";
        }
        if ($lowStock) {
            $parts[] = "low stock (at or under ".self::LOW_STOCK_THRESHOLD." pieces): {$lowStock}";
        }
        $context = 'Restocking needed -- '.implode('; ', $parts).'.';

        return ['subject' => 'seller_stock_alerts', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
    }

    private static function sellerListingCounts(string $lower, SellerProfile $seller): array
    {
        $status = match (true) {
            str_contains($lower, 'pending') => 'pending',
            str_contains($lower, 'rejected') => 'rejected',
            str_contains($lower, 'archived') => 'archived',
            str_contains($lower, 'active') => 'approved',
            default => null,
        };

        $query = FingerlingListing::where('seller_profile_id', $seller->id);
        if ($status) {
            $query->where('approval_status', $status);
        }
        $count = $query->count();
        $label = $status ? "{$status} listings" : 'listings';

        $context = "You have {$count} {$label}.";

        return [
            'subject' => 'seller_listing_counts',
            'context' => $context,
            'fallback' => [
                'English' => $context,
                'Tagalog' => "Mayroon kang {$count} {$label}.",
                'Bisaya' => "Naa kay {$count} {$label}.",
            ],
        ];
    }

    private static function latestOrderForSeller(SellerProfile $seller): array
    {
        $order = Order::with(['listing', 'buyer'])->where('seller_profile_id', $seller->id)->latest()->first();

        if (!$order) {
            $context = 'You have no orders yet.';

            return ['subject' => 'latest_order', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => 'Wala ka pang order.', 'Bisaya' => 'Wala ka pay order.']];
        }

        $species = $order->listing?->species ?? 'an item';
        $buyer = $order->buyer?->name ?? 'a buyer';
        $context = "Your latest order is {$order->quantity} {$species} for {$buyer}, currently {$order->status}, totaling {$order->total_amount}.";

        return [
            'subject' => 'latest_order',
            'context' => $context,
            'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context],
        ];
    }

    private static function sellerReviews(SellerProfile $seller): array
    {
        $count = Review::where('seller_profile_id', $seller->id)->count();
        $latest = Review::with('buyer')->where('seller_profile_id', $seller->id)->latest()->first();

        if (!$latest) {
            $context = "You have no reviews yet. Your current rating is {$seller->rating}.";

            return ['subject' => 'seller_reviews', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
        }

        $name = $latest->buyer?->name ?? 'A buyer';
        $context = "You have {$count} reviews, currently rated {$seller->rating} out of 5. Your most recent review is from {$name}, {$latest->rating} out of 5".($latest->comment ? ": \"{$latest->comment}\"" : '').'.';

        return ['subject' => 'seller_reviews', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
    }

    private static function sellerBestSellingListing(SellerProfile $seller): array
    {
        $row = Order::where('orders.seller_profile_id', $seller->id)
            ->where('orders.status', 'completed')
            ->join('listings', 'orders.listing_id', '=', 'listings.id')
            ->selectRaw('listings.species as species, sum(orders.quantity) as total')
            ->groupBy('listings.species')
            ->orderByDesc('total')
            ->first();

        if (!$row) {
            $context = 'You have no completed sales yet.';

            return ['subject' => 'seller_best_selling', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
        }

        $context = "Your best-selling species is {$row->species}, with {$row->total} pieces sold across completed orders.";

        return ['subject' => 'seller_best_selling', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
    }

    private static function sellerOrderCount(SellerProfile $seller): array
    {
        $count = Order::where('seller_profile_id', $seller->id)->count();
        $context = "You have {$count} orders in total.";

        return [
            'subject' => 'seller_order_count',
            'context' => $context,
            'fallback' => ['English' => $context, 'Tagalog' => "Mayroon kang {$count} order sa kabuuan.", 'Bisaya' => "Naa kay {$count} ka order sa tanan."],
        ];
    }

    // ------------------------------------------------------------------
    // LGU Admin -- every query here is forcibly scoped to the admin's own
    // municipality_id, never to a municipality named in the question.
    // ------------------------------------------------------------------

    private static function resolveLguAdmin(string $lower, User $user): ?array
    {
        $municipalityId = $user->municipality_id;

        if (self::matchesAny($lower, ['pending seller', 'sellers awaiting verification', 'sellers need verification', 'which sellers need verification', 'unverified seller'])) {
            $count = SellerProfile::where('municipality_id', $municipalityId)->where('status', 'pending')->count();
            $context = "There are {$count} sellers awaiting verification in your municipality.";

            return ['subject' => 'lgu_pending_sellers', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
        }

        if (self::matchesAny($lower, ['pending listing', 'listings awaiting approval', 'listings pending approval', 'how many pending listings'])) {
            $count = FingerlingListing::where('municipality_id', $municipalityId)->where('approval_status', 'pending')->count();
            $context = "There are {$count} listings pending approval in your municipality.";

            return ['subject' => 'lgu_pending_listings', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
        }

        if (self::matchesAny($lower, ['pending earnings', 'earnings awaiting approval', 'earnings need approval', 'completed deliveries still need earnings approval', 'need earnings approval'])) {
            $count = MockPayment::where('status', 'paid_held')
                ->whereHas('order', fn ($q) => $q->where('status', 'completed')
                    ->whereHas('sellerProfile', fn ($q2) => $q2->where('municipality_id', $municipalityId)))
                ->count();
            $context = "There are {$count} completed deliveries awaiting seller earnings approval in your municipality.";

            return ['subject' => 'lgu_pending_earnings', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
        }

        if (self::matchesAny($lower, ['municipality statistics', 'marketplace statistics', 'today\'s marketplace', 'current marketplace', 'municipality stats'])) {
            return self::municipalityOverview($municipalityId);
        }

        // Checked before the broader "revenue" triggers below so wallet
        // questions ("how much can I withdraw", "pending withdrawals") never
        // fall through to the generic municipality revenue summary.
        if (self::matchesAny($lower, [
            'available balance', 'pending withdrawal', 'withdrawal history', 'how much can i withdraw',
            'how much have i withdrawn', 'already withdrawn', 'withdrawn amount', 'my wallet', 'lgu wallet', 'our wallet',
        ])) {
            return self::lguWallet($municipalityId);
        }

        if (self::matchesAny($lower, [
            'municipality revenue', 'lgu revenue', 'today\'s revenue', 'todays revenue', 'monthly revenue',
            'total revenue', 'revenue report', 'revenue trend', 'revenue summary', 'how much revenue', 'our revenue',
        ])) {
            return self::municipalityRevenue($municipalityId);
        }

        if (self::matchesAny($lower, ['reviews', 'seller has the most reports', 'most reports'])) {
            $avg = round((float) Review::whereHas('sellerProfile', fn ($q) => $q->where('municipality_id', $municipalityId))->avg('rating'), 2);
            $count = Review::whereHas('sellerProfile', fn ($q) => $q->where('municipality_id', $municipalityId))->count();
            $context = "Sellers in your municipality have {$count} reviews with an average rating of {$avg} out of 5.";

            return ['subject' => 'lgu_reviews_overview', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
        }

        return null;
    }

    private static function municipalityOverview(int $municipalityId): array
    {
        $sellers = SellerProfile::where('municipality_id', $municipalityId)->count();
        $buyers = User::where('role', 'buyer')->where('municipality_id', $municipalityId)->count();
        $listings = FingerlingListing::where('municipality_id', $municipalityId)->where('approval_status', 'approved')->count();
        $pending = FingerlingListing::where('municipality_id', $municipalityId)->where('approval_status', 'pending')->count();
        $name = Municipality::find($municipalityId)?->name ?? 'your municipality';

        $context = "{$name} currently has {$sellers} registered sellers, {$buyers} registered buyers, {$listings} approved listings, and {$pending} listings pending approval.";

        return [
            'subject' => 'municipality_overview',
            'context' => $context,
            'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context],
        ];
    }

    /**
     * Municipality Revenue -- the LGU's own settled share (see
     * App\Support\RevenueReport) only, scoped to their own municipality.
     * Never includes the Platform Share or another municipality's figures.
     */
    private static function municipalityRevenue(int $municipalityId): array
    {
        $cards = RevenueReport::municipalityCards($municipalityId);
        $name = Municipality::find($municipalityId)?->name ?? 'your municipality';

        $context = "{$name}'s municipality revenue (the LGU's fixed ".CommissionCalculator::LGU_PERCENT."% share of every settled order): today ₱{$cards['today_revenue']}, this month ₱{$cards['monthly_revenue']}, all-time total ₱{$cards['total_revenue']}, across {$cards['total_completed_orders']} completed orders (avg ₱{$cards['average_revenue_per_order']} per order). Available Balance to withdraw: ₱{$cards['available_balance']}.";

        return [
            'subject' => 'municipality_revenue',
            'context' => $context,
            'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context],
        ];
    }

    /**
     * LGU Wallet -- mirrors sellerWallet() exactly (same computed fields,
     * same "answer using the wallet page's own math" principle), just
     * backed by App\Support\LguWallet and scoped to municipality_id.
     */
    private static function lguWallet(int $municipalityId): array
    {
        $w = LguWallet::summary($municipalityId);
        $pendingCount = LguWithdrawalRequest::where('municipality_id', $municipalityId)->whereIn('status', ['pending', 'approved'])->count();
        $paidCount = LguWithdrawalRequest::where('municipality_id', $municipalityId)->where('status', 'paid')->count();

        $context = "Your municipality's LGU wallet: Available Balance {$w['available_balance']}, Total Revenue {$w['total_revenue']}, Withdrawn Amount {$w['withdrawn_amount']}, {$pendingCount} pending withdrawal request(s), {$paidCount} completed withdrawal(s).";

        return [
            'subject' => 'lgu_wallet',
            'context' => $context,
            'fallback' => [
                'English' => $context,
                'Tagalog' => "Ang wallet ng inyong munisipyo: Available Balance {$w['available_balance']}, Total Revenue {$w['total_revenue']}, Withdrawn Amount {$w['withdrawn_amount']}, {$pendingCount} pending na withdrawal request, {$paidCount} tapos nang withdrawal.",
                'Bisaya' => "Ang wallet sa inyong munisipyo: Available Balance {$w['available_balance']}, Total Revenue {$w['total_revenue']}, Withdrawn Amount {$w['withdrawn_amount']}, {$pendingCount} nga pending withdrawal request, {$paidCount} nga nahuman nga withdrawal.",
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Super Admin -- platform-wide, unrestricted.
    // ------------------------------------------------------------------

    private static function resolveSuperAdmin(string $lower, User $user): ?array
    {
        if (self::matchesAny($lower, ['total users'])) {
            $count = User::count();
            $context = "There are {$count} total user accounts on AbaiMarket (buyers, sellers, LGU admins, and Super Admin).";

            return ['subject' => 'super_total_users', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
        }

        // Moderation questions -- most specific triggers first so "most
        // suspended sellers" and "suspended this month" don't fall through
        // to the generic "suspended seller"/"suspended user" branches below.
        if (self::matchesAny($lower, ['most suspended seller', 'suspended sellers by municipality', 'municipality has the most suspended'])) {
            return self::municipalityWithMostSuspendedSellers();
        }

        if (self::matchesAny($lower, ['suspended this month', 'suspended accounts this month', 'accounts were suspended this month', 'accounts suspended this month'])) {
            return self::accountsSuspendedThisMonth();
        }

        if (self::matchesAny($lower, ['suspended buyer'])) {
            return self::suspendedBuyersList();
        }

        if (self::matchesAny($lower, ['suspended seller'])) {
            return self::suspendedSellersList();
        }

        if (self::matchesAny($lower, ['suspended user', 'suspended account', 'how many suspended'])) {
            return self::suspendedUsersCount();
        }

        if (self::matchesAny($lower, ['pending listing', 'approved listing', 'archived listing', 'rejected listing'])) {
            $status = match (true) {
                str_contains($lower, 'pending') => 'pending',
                str_contains($lower, 'approved') => 'approved',
                str_contains($lower, 'archived') => 'archived',
                default => 'rejected',
            };
            $count = FingerlingListing::where('approval_status', $status)->count();
            $context = "There are {$count} {$status} listings platform-wide.";

            return ['subject' => 'super_listing_status_count', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
        }

        // Checked before the broader "revenue" triggers below so "platform
        // revenue" and "commission" always resolve to the Platform payout
        // fee specifically, never the gross figure.
        if (self::matchesAny($lower, ['platform revenue', 'platform share', 'platform commission', 'marketplace commission', 'payout fee'])) {
            $cards = RevenueReport::platformCards();
            $context = "Platform Revenue (a ".CommissionCalculator::WITHDRAWAL_FEE_PERCENT."% payout fee charged when a seller withdraws, realized only once the Super Admin marks that withdrawal Paid -- never taken from the order at settlement time): today ₱{$cards['today_platform_revenue']}, this month ₱{$cards['monthly_platform_revenue']}, all-time total ₱{$cards['total_platform_revenue']}.";

            return ['subject' => 'super_platform_revenue', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
        }

        if (self::matchesAny($lower, ['commission report', 'commission distribution', 'commission breakdown', 'revenue sharing', 'revenue split'])) {
            $seller = round((float) Settlement::sum('seller_share'), 2);
            $lgu = round((float) Settlement::sum('lgu_share'), 2);
            $platform = RevenueReport::platformCards()['total_platform_revenue'];
            $context = "Commission distribution: Seller Share ₱{$seller} (".CommissionCalculator::SELLER_PERCENT."% of every settled order), LGU Share ₱{$lgu} (".CommissionCalculator::LGU_PERCENT."%, realized at settlement), realized Platform payout fee ₱{$platform} (".CommissionCalculator::WITHDRAWAL_FEE_PERCENT."% of withdrawn amounts, realized only once a seller's withdrawal is paid out). Seller and LGU shares are fixed at settlement; the Platform instead earns a fee on withdrawals.";

            return ['subject' => 'super_commission_distribution', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
        }

        if (self::matchesAny($lower, ['lgu revenue'])) {
            $municipality = self::detectMunicipality($lower);
            if ($municipality) {
                $cards = RevenueReport::municipalityCards($municipality->id);
                $context = "{$municipality->name}'s LGU Revenue: total ₱{$cards['total_revenue']} across {$cards['total_completed_orders']} completed orders (this month ₱{$cards['monthly_revenue']}).";
            } else {
                $total = round((float) Settlement::sum('lgu_share'), 2);
                $context = "Total LGU Revenue across every municipality: ₱{$total}.";
            }

            return ['subject' => 'super_lgu_revenue', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
        }

        if (self::matchesAny($lower, ['total marketplace sales', 'gross marketplace revenue', 'gross revenue', 'total sales', 'total revenue'])) {
            $total = round((float) MockPayment::whereIn('status', ['paid_held', 'released'])->sum('amount'), 2);
            $context = "Gross Marketplace Revenue (total value paid by buyers, before revenue sharing) captured to date: ₱{$total}.";

            return ['subject' => 'super_total_sales', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
        }

        if (self::matchesAny($lower, ['pending payout', 'completed payout', 'how many withdrawals'])) {
            $status = str_contains($lower, 'completed') || str_contains($lower, 'paid') ? 'paid' : 'pending';
            $count = WithdrawalRequest::where('status', $status)->count();
            $sum = round((float) WithdrawalRequest::where('status', $status)->sum('amount'), 2);
            $label = $status === 'paid' ? 'completed payouts' : 'pending payouts';
            $context = "There are {$count} {$label}, totaling {$sum}.";

            return ['subject' => 'super_payouts', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
        }

        if (self::matchesAny($lower, ['municipality has the highest sales', 'highest sales', 'most sales'])) {
            return self::municipalityWithMostSales();
        }

        if (self::matchesAny($lower, ['most pending approvals', 'lgu has the most pending'])) {
            return self::municipalityWithMostPendingApprovals();
        }

        if (self::matchesAny($lower, ['which fish species sells the most', 'best selling species', 'top selling species', 'most popular species'])) {
            return self::bestSellingSpeciesPlatformWide();
        }

        if (self::matchesAny($lower, self::COUNT_TRIGGERS) && str_contains($lower, 'this month') && str_contains($lower, 'seller')) {
            $count = SellerProfile::where('created_at', '>=', now()->startOfMonth())->count();
            $context = "{$count} sellers registered this month.";

            return ['subject' => 'super_sellers_this_month', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
        }

        return null;
    }

    private static function municipalityWithMostSales(): array
    {
        $row = Order::whereIn('orders.status', ['completed'])
            ->join('seller_profiles', 'orders.seller_profile_id', '=', 'seller_profiles.id')
            ->join('municipalities', 'seller_profiles.municipality_id', '=', 'municipalities.id')
            ->selectRaw('municipalities.name as municipality, sum(orders.total_amount) as total')
            ->groupBy('municipalities.name')
            ->orderByDesc('total')
            ->first();

        if (!$row) {
            $context = 'No municipality has any completed sales yet.';

            return ['subject' => 'municipality_most_sales', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
        }

        $context = "{$row->municipality} has the highest sales, totaling {$row->total} from completed orders.";

        return ['subject' => 'municipality_most_sales', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
    }

    private static function municipalityWithMostPendingApprovals(): array
    {
        $row = FingerlingListing::where('approval_status', 'pending')
            ->join('municipalities', 'listings.municipality_id', '=', 'municipalities.id')
            ->selectRaw('municipalities.name as municipality, count(*) as total')
            ->groupBy('municipalities.name')
            ->orderByDesc('total')
            ->first();

        if (!$row) {
            $context = 'No municipality currently has any listings pending approval.';

            return ['subject' => 'municipality_most_pending', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
        }

        $context = "{$row->municipality} has the most pending approvals, with {$row->total} listings awaiting review.";

        return ['subject' => 'municipality_most_pending', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
    }

    private static function bestSellingSpeciesPlatformWide(): array
    {
        $row = Order::where('orders.status', 'completed')
            ->join('listings', 'orders.listing_id', '=', 'listings.id')
            ->selectRaw('listings.species as species, sum(orders.quantity) as total')
            ->groupBy('listings.species')
            ->orderByDesc('total')
            ->first();

        if (!$row) {
            $context = 'No species has any completed sales yet.';

            return ['subject' => 'platform_best_selling_species', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
        }

        $context = "{$row->species} is the best-selling species platform-wide, with {$row->total} pieces sold across completed orders.";

        return ['subject' => 'platform_best_selling_species', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
    }

    /**
     * "Suspended users" spans all three moderated roles -- Buyer/LGU Admin
     * status live on users.status, Seller status lives on
     * seller_profiles.status (see App\Support\AccountModeration), so this is
     * a three-way count rather than a single query.
     */
    private static function suspendedUsersCount(): array
    {
        $buyers = User::where('role', 'buyer')->where('status', 'suspended')->count();
        $sellers = SellerProfile::where('status', 'suspended')->count();
        $lguAdmins = User::where('role', 'lgu_admin')->where('status', 'disabled')->count();
        $total = $buyers + $sellers + $lguAdmins;

        $context = "There are {$total} suspended accounts in total: {$buyers} buyers, {$sellers} sellers, and {$lguAdmins} LGU admins.";

        return ['subject' => 'super_suspended_users_count', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
    }

    private static function suspendedBuyersList(): array
    {
        $buyers = User::where('role', 'buyer')->where('status', 'suspended')->pluck('name');

        if ($buyers->isEmpty()) {
            $context = 'There are currently no suspended buyers.';

            return ['subject' => 'super_suspended_buyers', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
        }

        $context = "Suspended buyers ({$buyers->count()}): {$buyers->implode(', ')}.";

        return ['subject' => 'super_suspended_buyers', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
    }

    private static function suspendedSellersList(): array
    {
        $sellers = SellerProfile::where('status', 'suspended')->pluck('hatchery_name');

        if ($sellers->isEmpty()) {
            $context = 'There are currently no suspended sellers.';

            return ['subject' => 'super_suspended_sellers', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
        }

        $context = "Suspended sellers ({$sellers->count()}): {$sellers->implode(', ')}.";

        return ['subject' => 'super_suspended_sellers', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
    }

    private static function municipalityWithMostSuspendedSellers(): array
    {
        $row = SellerProfile::where('status', 'suspended')
            ->join('municipalities', 'seller_profiles.municipality_id', '=', 'municipalities.id')
            ->selectRaw('municipalities.name as municipality, count(*) as total')
            ->groupBy('municipalities.name')
            ->orderByDesc('total')
            ->first();

        if (!$row) {
            $context = 'No municipality currently has any suspended sellers.';

            return ['subject' => 'super_municipality_most_suspended', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
        }

        $context = "{$row->municipality} has the most suspended sellers, with {$row->total}.";

        return ['subject' => 'super_municipality_most_suspended', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
    }

    private static function accountsSuspendedThisMonth(): array
    {
        $logs = ModerationLog::with('user:id,name')
            ->where('action', 'suspended')
            ->where('created_at', '>=', now()->startOfMonth())
            ->get();

        if ($logs->isEmpty()) {
            $context = 'No accounts have been suspended this month.';

            return ['subject' => 'super_suspended_this_month', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
        }

        $names = $logs->map(fn ($log) => ($log->user?->name ?? 'Unknown').' ('.$log->role.')')->implode(', ');
        $context = "{$logs->count()} accounts were suspended this month: {$names}.";

        return ['subject' => 'super_suspended_this_month', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
    }

    // ------------------------------------------------------------------
    // Shared marketplace facts -- available to any role. Municipality-scoped
    // facts are forcibly pinned to an LGU Admin's own municipality and never
    // to one named in the question, and cross-municipality comparisons are
    // reframed to that one municipality for an LGU Admin instead of exposing
    // a platform-wide ranking.
    // ------------------------------------------------------------------

    private static function resolveSharedMarketplace(string $lower, User $user, ?string $previousSubject): ?array
    {
        $species = self::detectSpecies($lower);
        if ($species && self::matchesAny($lower, ['who sells', 'which seller sells', 'where can i buy', 'who is selling'])) {
            return self::whoSellsSpecies($species, self::municipalityScope($user, null));
        }

        if (self::matchesAny($lower, ['highest rating', 'highest rated', 'best rated', 'top rated'])) {
            return self::highestRatedSeller(self::municipalityScope($user, null));
        }

        if (self::matchesAny($lower, ['which species', 'what species', 'species are available', 'species currently available', 'species are currently'])) {
            return self::availableSpecies(self::municipalityScope($user, null));
        }

        if (self::matchesAny($lower, ['pending approval', 'pending listings', 'awaiting approval', 'listings are pending'])) {
            $scope = self::municipalityScope($user, null);

            return self::pendingListingsCount($scope);
        }

        if (self::matchesAny($lower, ['marketplace statistics', 'marketplace stats', 'platform statistics', 'overall statistics', 'current marketplace'])) {
            if ($user->role === 'lgu_admin') {
                return self::municipalityOverview($user->municipality_id);
            }

            return self::marketplaceOverview();
        }

        if (self::matchesAny($lower, ['municipality has the most', 'which municipality', 'most sellers', 'most approved sellers'])) {
            if ($user->role === 'lgu_admin') {
                return self::municipalityOverview($user->municipality_id);
            }

            $wantApproved = str_contains($lower, 'approved') || str_contains($lower, 'verified');

            return self::municipalityWithMostSellers($wantApproved);
        }

        $municipality = self::municipalityScope($user, self::detectMunicipality($lower));
        $entity = self::detectEntity($lower);
        $isCountQuestion = self::matchesAny($lower, self::COUNT_TRIGGERS);

        if ($entity && $isCountQuestion) {
            return $municipality ? self::countEntityInMunicipality($entity, $municipality) : self::countEntity($entity);
        }

        if (!$entity && $municipality && $previousSubject) {
            $carriedEntity = self::entityForSubject($previousSubject);
            if ($carriedEntity) {
                return self::countEntityInMunicipality($carriedEntity, $municipality);
            }
        }

        return null;
    }

    /**
     * An LGU Admin's municipality always wins over whatever was named in the
     * question -- this is what makes "never access other municipality data"
     * actually enforced rather than merely advisory. Every other role uses
     * whatever municipality (if any) was detected in the question.
     */
    private static function municipalityScope(User $user, ?Municipality $detected): ?Municipality
    {
        if ($user->role === 'lgu_admin') {
            return $user->municipality;
        }

        return $detected;
    }

    private static function matchesAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function detectMunicipality(string $lower): ?Municipality
    {
        return Municipality::all()->first(fn ($m) => str_contains($lower, strtolower($m->name)));
    }

    private static function detectSpecies(string $lower): ?string
    {
        $candidates = collect(self::KNOWN_SPECIES)
            ->merge(FingerlingListing::distinct()->pluck('species'))
            ->unique(fn ($s) => strtolower($s));

        return $candidates->first(fn ($s) => str_contains($lower, strtolower($s)));
    }

    private static function detectEntity(string $lower): ?string
    {
        if (self::matchesAny($lower, ['lgu admin', 'lgu_admin', 'lgu-admin'])) {
            return 'lgu_admin';
        }
        if (str_contains($lower, 'seller')) {
            return 'seller';
        }
        if (str_contains($lower, 'buyer')) {
            return 'buyer';
        }
        if (str_contains($lower, 'listing')) {
            return 'listing';
        }

        return null;
    }

    private static function entityForSubject(string $subject): ?string
    {
        return match ($subject) {
            'seller_count' => 'seller',
            'buyer_count' => 'buyer',
            'lgu_admin_count' => 'lgu_admin',
            'approved_listings_count' => 'listing',
            default => null,
        };
    }

    private static function countEntity(string $entity): array
    {
        [$count, $label, $subject] = match ($entity) {
            'seller' => [SellerProfile::count(), 'registered sellers', 'seller_count'],
            'buyer' => [User::where('role', 'buyer')->count(), 'registered buyers', 'buyer_count'],
            'lgu_admin' => [User::where('role', 'lgu_admin')->count(), 'LGU admins', 'lgu_admin_count'],
            'listing' => [FingerlingListing::where('approval_status', 'approved')->count(), 'approved listings', 'approved_listings_count'],
        };

        $context = "There are {$count} {$label} on AbaiMarket.";

        return [
            'subject' => $subject,
            'context' => $context,
            'fallback' => [
                'English' => $context,
                'Tagalog' => "Mayroong {$count} {$label} sa AbaiMarket.",
                'Bisaya' => "Naa'y {$count} {$label} sa AbaiMarket.",
            ],
        ];
    }

    private static function countEntityInMunicipality(string $entity, Municipality $municipality): array
    {
        [$count, $label, $subject] = match ($entity) {
            'seller' => [SellerProfile::where('municipality_id', $municipality->id)->count(), 'sellers', 'seller_count'],
            'buyer' => [User::where('role', 'buyer')->where('municipality_id', $municipality->id)->count(), 'buyers', 'buyer_count'],
            'lgu_admin' => [User::where('role', 'lgu_admin')->where('municipality_id', $municipality->id)->count(), 'LGU admins', 'lgu_admin_count'],
            'listing' => [FingerlingListing::where('approval_status', 'approved')->where('municipality_id', $municipality->id)->count(), 'approved listings', 'approved_listings_count'],
        };

        $name = $municipality->name;
        $context = "There are {$count} {$label} in {$name}.";

        return [
            'subject' => $subject,
            'context' => $context,
            'fallback' => [
                'English' => $context,
                'Tagalog' => "Mayroong {$count} {$label} sa {$name}.",
                'Bisaya' => "Naa'y {$count} {$label} sa {$name}.",
            ],
        ];
    }

    private static function municipalityWithMostSellers(bool $verifiedOnly): array
    {
        $query = SellerProfile::query();
        if ($verifiedOnly) {
            $query->where('status', 'verified');
        }

        $row = $query->join('municipalities', 'seller_profiles.municipality_id', '=', 'municipalities.id')
            ->selectRaw('municipalities.name as municipality, count(*) as total')
            ->groupBy('municipalities.name')
            ->orderByDesc('total')
            ->first();

        $label = $verifiedOnly ? 'approved sellers' : 'sellers';

        if (!$row) {
            $context = "No municipality currently has any {$label}.";

            return ['subject' => 'municipality_most_sellers', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
        }

        $context = "{$row->municipality} has the most {$label}, with {$row->total}.";

        return [
            'subject' => 'municipality_most_sellers',
            'context' => $context,
            'fallback' => [
                'English' => $context,
                'Tagalog' => "Ang {$row->municipality} ang may pinakamaraming {$label}, na may {$row->total}.",
                'Bisaya' => "Ang {$row->municipality} ang naay pinakadaghan nga {$label}, nga adunay {$row->total}.",
            ],
        ];
    }

    private static function whoSellsSpecies(string $species, ?Municipality $scope): array
    {
        $query = FingerlingListing::where('species', $species)->where('approval_status', 'approved');
        if ($scope) {
            $query->where('municipality_id', $scope->id);
        }

        $sellers = $query->with('sellerProfile.municipality')
            ->get()
            ->pluck('sellerProfile')
            ->filter()
            ->unique('id')
            ->values();

        if ($sellers->isEmpty()) {
            $context = "No approved seller currently lists {$species}".($scope ? " in {$scope->name}" : '').'.';

            return ['subject' => 'who_sells_species', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
        }

        $names = $sellers->map(fn ($s) => "{$s->hatchery_name} ({$s->municipality?->name})")->implode(', ');
        $context = "Sellers currently listing {$species}: {$names}.";

        return [
            'subject' => 'who_sells_species',
            'context' => $context,
            'fallback' => [
                'English' => $context,
                'Tagalog' => "Ang mga seller na nagbebenta ng {$species}: {$names}.",
                'Bisaya' => "Ang mga seller nga nagbaligya og {$species}: {$names}.",
            ],
        ];
    }

    private static function highestRatedSeller(?Municipality $scope): array
    {
        $query = fn () => $scope ? SellerProfile::where('municipality_id', $scope->id) : SellerProfile::query();

        $seller = (clone $query())->where('status', 'verified')->orderByDesc('rating')->with('municipality')->first()
            ?? $query()->orderByDesc('rating')->with('municipality')->first();

        if (!$seller) {
            $context = 'There are no sellers registered yet.'.($scope ? " in {$scope->name}" : '');

            return ['subject' => 'highest_rated_seller', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
        }

        $municipalityName = $seller->municipality?->name ?? 'an unknown municipality';
        $context = "{$seller->hatchery_name} in {$municipalityName} has the highest rating, at {$seller->rating} out of 5.";

        return [
            'subject' => 'highest_rated_seller',
            'context' => $context,
            'fallback' => [
                'English' => $context,
                'Tagalog' => "Ang {$seller->hatchery_name} sa {$municipalityName} ang may pinakamataas na rating, {$seller->rating} sa 5.",
                'Bisaya' => "Ang {$seller->hatchery_name} sa {$municipalityName} ang naay pinakataas nga rating, {$seller->rating} sa 5.",
            ],
        ];
    }

    private static function availableSpecies(?Municipality $scope): array
    {
        $query = FingerlingListing::where('approval_status', 'approved')->where('availability_status', 'in_stock');
        if ($scope) {
            $query->where('municipality_id', $scope->id);
        }
        $species = $query->distinct()->pluck('species');

        if ($species->isEmpty()) {
            $context = 'No species are currently in stock.'.($scope ? " in {$scope->name}" : '');

            return ['subject' => 'available_species', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => $context, 'Bisaya' => $context]];
        }

        $list = $species->implode(', ');
        $context = "Species currently available: {$list}.";

        return [
            'subject' => 'available_species',
            'context' => $context,
            'fallback' => [
                'English' => $context,
                'Tagalog' => "Mga species na kasalukuyang available: {$list}.",
                'Bisaya' => "Mga species nga naa karon: {$list}.",
            ],
        ];
    }

    private static function pendingListingsCount(?Municipality $scope): array
    {
        $query = FingerlingListing::where('approval_status', 'pending');
        if ($scope) {
            $query->where('municipality_id', $scope->id);
        }
        $count = $query->count();
        $context = "There are {$count} listings currently pending approval".($scope ? " in {$scope->name}" : '').'.';

        return [
            'subject' => 'pending_listings_count',
            'context' => $context,
            'fallback' => [
                'English' => $context,
                'Tagalog' => "May {$count} listing na kasalukuyang naghihintay ng approval.",
                'Bisaya' => "Naa'y {$count} listing nga karon naghulat og approval.",
            ],
        ];
    }

    private static function marketplaceOverview(): array
    {
        $sellers = SellerProfile::count();
        $buyers = User::where('role', 'buyer')->count();
        $listings = FingerlingListing::where('approval_status', 'approved')->count();
        $municipalitiesRepresented = SellerProfile::distinct('municipality_id')->count('municipality_id');

        $context = "AbaiMarket currently has {$sellers} registered sellers, {$buyers} registered buyers, and {$listings} approved listings, across {$municipalitiesRepresented} municipalities.";

        return [
            'subject' => 'marketplace_stats_overview',
            'context' => $context,
            'fallback' => [
                'English' => $context,
                'Tagalog' => "Ang AbaiMarket ay may {$sellers} rehistradong seller, {$buyers} rehistradong buyer, at {$listings} approved na listing, sa {$municipalitiesRepresented} munisipyo.",
                'Bisaya' => "Ang AbaiMarket naay {$sellers} rehistradong seller, {$buyers} rehistradong buyer, ug {$listings} approved nga listing, sa {$municipalitiesRepresented} ka munisipyo.",
            ],
        ];
    }

    private static function notificationCount(User $user): array
    {
        $unread = AppNotification::where('user_id', $user->id)->whereNull('read_at')->count();
        $context = "You have {$unread} unread notifications.";

        return [
            'subject' => 'notification_count',
            'context' => $context,
            'fallback' => [
                'English' => $context,
                'Tagalog' => "May {$unread} kang hindi pa nababasang notification.",
                'Bisaya' => "Naa kay {$unread} nga wala pa mabasa nga notification.",
            ],
        ];
    }

    private static function lastMessaged(User $user): array
    {
        $message = Message::where('sender_id', $user->id)->orWhere('receiver_id', $user->id)->latest()->first();

        if (!$message) {
            $context = "You haven't messaged anyone yet.";

            return ['subject' => 'last_messaged', 'context' => $context, 'fallback' => ['English' => $context, 'Tagalog' => 'Wala ka pang na-message na sinuman.', 'Bisaya' => 'Wala pa ka nakapa-message og kang bisan kinsa.']];
        }

        $otherId = $message->sender_id === $user->id ? $message->receiver_id : $message->sender_id;
        $name = User::find($otherId)?->name ?? 'someone';
        $context = "You last messaged {$name}.";

        return [
            'subject' => 'last_messaged',
            'context' => $context,
            'fallback' => [
                'English' => $context,
                'Tagalog' => "Huli mong na-message si {$name}.",
                'Bisaya' => "Ang imong katong gi-message kay si {$name}.",
            ],
        ];
    }

    private static function unreadMessageCount(User $user): array
    {
        $count = Message::where('receiver_id', $user->id)->whereNull('read_at')->count();
        $context = "You have {$count} unread messages.";

        return [
            'subject' => 'unread_message_count',
            'context' => $context,
            'fallback' => [
                'English' => $context,
                'Tagalog' => "May {$count} kang hindi pa nababasang mensahe.",
                'Bisaya' => "Naa kay {$count} nga wala pa mabasa nga mensahe.",
            ],
        ];
    }
}
