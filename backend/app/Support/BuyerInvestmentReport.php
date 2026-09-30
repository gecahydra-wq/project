<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Support\Carbon;

/**
 * Buyer Turnout / ROI analytics -- the figures a fish farmer actually needs
 * from their purchase record: what they have put in, how they have engaged
 * with the marketplace, and what their stock might be worth at harvest.
 *
 * A NOTE ON THE RETURN FIGURES, because it matters for how they're presented:
 * AbaiMarket only ever sees the BUY side of a farmer's business. Nothing in
 * this system knows what they later sold their grown fish for, or whether a
 * pond failed. So the return overview is a projection built from two openly
 * stated assumptions -- a survival rate and a farm-gate value per harvested
 * fish -- both of which the farmer overrides with their own figures via query
 * parameters. Everything under 'investment' and 'turnout' is hard recorded
 * fact; everything under 'projection' is clearly an estimate, and the UI
 * labels it as such. We never present a projection as realised earnings.
 *
 * The projection needs a COUNT OF FISH, because that is what a survival rate
 * and a per-fish harvest value apply to. A piece-priced purchase is already a
 * count. A bulk purchase is one too, as soon as the seller states how many fish
 * are in a bulk -- listings.pieces_per_unit -- so those are converted and
 * included. Anything the listing cannot turn into a count (a per-kilogram
 * purchase, or a bulk listing whose seller never stated a count) stays in the
 * unit breakdown and out of the ROI maths, and is counted in 'excluded_orders'
 * so the UI can say so rather than silently under-reporting.
 */
class BuyerInvestmentReport
{
    /** Share of fingerlings expected to survive to harvest. */
    public const DEFAULT_SURVIVAL_RATE = 0.80;

    /**
     * Farm-gate value of ONE harvested fish, in pesos. A neutral starting
     * point only -- prices vary by species, size, and municipality, so the
     * farmer is expected to replace it with their own figure.
     */
    public const DEFAULT_HARVEST_VALUE_PER_PIECE = 25.00;

    /** Orders that represent money committed but not yet a completed purchase. */
    private const ACTIVE_STATUSES = ['placed', 'paid', 'confirmed', 'in_transit'];

    /**
     * @param  array{survival_rate?: float|null, harvest_value_per_piece?: float|null}  $assumptions
     */
    public static function build(int $buyerId, Carbon $start, Carbon $end, array $assumptions = []): array
    {
        $survivalRate = self::clampRate($assumptions['survival_rate'] ?? null);
        $harvestValue = self::clampValue($assumptions['harvest_value_per_piece'] ?? null);

        $completed = Order::query()
            ->with(['listing:id,species,title,unit_type,pieces_per_unit', 'sellerProfile:id,hatchery_name'])
            ->where('orders.buyer_id', $buyerId)
            ->where('orders.status', 'completed')
            ->whereBetween('orders.created_at', [$start, $end])
            ->latest()
            ->get();

        $allInPeriod = Order::where('buyer_id', $buyerId)->whereBetween('created_at', [$start, $end])->get(['status', 'total_amount', 'seller_profile_id', 'created_at']);

        $investment = self::investment($buyerId, $completed, $allInPeriod);
        $units = self::unitBreakdown($completed);
        $projection = self::projection($completed, $survivalRate, $harvestValue);

        return [
            'assumptions' => [
                'survival_rate' => $survivalRate,
                'harvest_value_per_piece' => $harvestValue,
                'defaults' => [
                    'survival_rate' => self::DEFAULT_SURVIVAL_RATE,
                    'harvest_value_per_piece' => self::DEFAULT_HARVEST_VALUE_PER_PIECE,
                ],
            ],
            'investment' => $investment,
            'turnout' => self::turnout($buyerId, $completed, $allInPeriod),
            'units' => $units,
            'projection' => $projection,
            'purchase_history' => self::purchaseHistory($completed),
            // Whether this buyer has ever ordered at all -- the gate for the
            // harvest projection, which is meaningless with nothing bought:
            // every figure it produces would be a multiple of zero, which
            // reads like a real (and terrible) forecast rather than "no data".
            //
            // All-time and status-agnostic on purpose. Everything else here is
            // scoped to the selected period, but the calculator must not blink
            // in and out of existence as the buyer changes the date filter, and
            // an order still in transit is a real purchase already made.
            'has_orders' => Order::where('buyer_id', $buyerId)->exists(),
        ];
    }

    /** Money in: settled purchases, money still in flight, and the all-time total. */
    private static function investment(int $buyerId, $completed, $allInPeriod): array
    {
        $spent = round((float) $completed->sum('total_amount'), 2);
        $committed = round((float) $allInPeriod->whereIn('status', self::ACTIVE_STATUSES)->sum('total_amount'), 2);

        return [
            'total_invested' => $spent,
            'committed_investment' => $committed,
            'total_exposure' => round($spent + $committed, 2),
            'lifetime_invested' => round((float) Order::where('buyer_id', $buyerId)->where('status', 'completed')->sum('total_amount'), 2),
            'average_order_value' => $completed->count() ? round($spent / $completed->count(), 2) : 0.0,
            'largest_order_value' => round((float) ($completed->max('total_amount') ?? 0), 2),
        ];
    }

    /**
     * Buyer Turnout -- how actively this farmer is using the marketplace, not
     * how much they spent. Completion rate is the share of their orders that
     * actually finished, which is the honest denominator for the ROI figures.
     */
    private static function turnout(int $buyerId, $completed, $allInPeriod): array
    {
        $totalOrders = $allInPeriod->count();
        $completedCount = $completed->count();

        return [
            'total_orders' => $totalOrders,
            'completed_orders' => $completedCount,
            'active_orders' => $allInPeriod->whereIn('status', self::ACTIVE_STATUSES)->count(),
            'cancelled_orders' => $allInPeriod->where('status', 'cancelled')->count(),
            'completion_rate' => $totalOrders ? round(($completedCount / $totalOrders) * 100, 1) : 0.0,
            'sellers_engaged' => $allInPeriod->pluck('seller_profile_id')->filter()->unique()->count(),
            'first_purchase' => optional(Order::where('buyer_id', $buyerId)->where('status', 'completed')->oldest()->value('created_at'))->toDateString(),
            'last_purchase' => optional($completed->first()?->created_at)->toDateString(),
        ];
    }

    /**
     * What was bought, grouped by how it was sold (see
     * FingerlingListing::UNIT_TYPES) -- a farmer buying 5,000 pieces and 40 kg
     * needs those kept apart, never summed into one meaningless "quantity".
     */
    private static function unitBreakdown($completed): array
    {
        return $completed
            ->groupBy(fn ($order) => $order->listing?->unit_type ?: 'piece')
            ->map(fn ($orders, $unitType) => [
                'unit_type' => $unitType,
                'unit_label' => \App\Models\FingerlingListing::UNIT_TYPES[$unitType]['plural'] ?? 'pcs',
                'quantity' => (int) $orders->sum('quantity'),
                'amount' => round((float) $orders->sum('total_amount'), 2),
                'orders' => $orders->count(),
            ])
            ->values()
            ->all();
    }

    /**
     * The estimated return, over every purchase whose quantity can be resolved
     * to a count of fish -- see the class docblock. Returns zeroed figures (not
     * nulls) when none can, so the UI has nothing to special-case.
     */
    private static function projection($completed, float $survivalRate, float $harvestValue): array
    {
        // [order, fish count] for each purchase the projection can cover. A
        // bulk order of 3 at 10 fish per bulk contributes 30.
        $counted = $completed
            ->map(fn ($order) => [$order, $order->listing?->piecesFor((int) $order->quantity)])
            ->filter(fn ($pair) => $pair[1] !== null && $pair[1] > 0);

        $pieces = (int) $counted->sum(fn ($pair) => $pair[1]);
        $invested = round((float) $counted->sum(fn ($pair) => (float) $pair[0]->total_amount), 2);
        $survivors = (int) floor($pieces * $survivalRate);
        $revenue = round($survivors * $harvestValue, 2);
        $return = round($revenue - $invested, 2);

        return [
            'pieces_purchased' => $pieces,
            'invested_in_pieces' => $invested,
            'projected_survivors' => $survivors,
            'projected_losses' => $pieces - $survivors,
            'projected_revenue' => $revenue,
            'projected_return' => $return,
            'projected_roi_percent' => $invested > 0 ? round(($return / $invested) * 100, 1) : 0.0,
            'cost_per_piece' => $pieces > 0 ? round($invested / $pieces, 2) : 0.0,
            'break_even_value_per_piece' => $survivors > 0 ? round($invested / $survivors, 2) : 0.0,
            // Purchases the projection could not cover, so the UI can say so
            // rather than quietly under-reporting.
            'excluded_orders' => $completed->count() - $counted->count(),
        ];
    }

    /** Purchase history rows -- date, seller, what, how much, what it cost. */
    private static function purchaseHistory($completed): array
    {
        return $completed->take(50)->map(fn ($order) => [
            'order_number' => $order->order_number,
            'date' => $order->created_at?->toIso8601String(),
            'seller' => $order->sellerProfile?->hatchery_name,
            'species' => $order->listing?->species ?? $order->listing?->title,
            'quantity' => (int) $order->quantity,
            'unit_label' => $order->listing?->unit_label_plural ?? 'pcs',
            'unit_price' => round((float) $order->unit_price, 2),
            'total_amount' => round((float) $order->total_amount, 2),
        ])->values()->all();
    }

    private static function clampRate($value): float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return self::DEFAULT_SURVIVAL_RATE;
        }

        return round(max(0.0, min(1.0, (float) $value)), 4);
    }

    private static function clampValue($value): float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return self::DEFAULT_HARVEST_VALUE_PER_PIECE;
        }

        return round(max(0.0, (float) $value), 2);
    }
}
