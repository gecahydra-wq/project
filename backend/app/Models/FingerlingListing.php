<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FingerlingListing extends Model
{
    use HasFactory;

    protected $table = 'listings';

    protected $fillable = [
        'seller_profile_id',
        'municipality_id',
        'species',
        'scientific_name',
        'title',
        'description',
        'quantity',
        'price_per_piece',
        // Unit of Measurement + Minimum Order. price_per_piece is the price of
        // ONE unit_type unit, and quantity is the stock in that same unit --
        // see the add_unit_of_measurement_and_minimum_order migration for why
        // the column keeps its original name.
        'unit_type',
        'minimum_order',
        'unit_description',
        // How many fish are in ONE unit. Required for 'bulk', where the unit
        // is otherwise an unknown quantity to buyer and analytics alike.
        'pieces_per_unit',
        'average_size',
        'availability_status',
        'approval_status',
        'rejection_reason',
    ];

    protected $casts = [
        'price_per_piece' => 'decimal:2',
        'minimum_order' => 'integer',
        'pieces_per_unit' => 'integer',
    ];

    /**
     * How a seller may sell a listing, and how each unit reads on screen.
     * 'short' is the per-unit price suffix ("₱5.00/pc"); 'plural' labels a
     * quantity ("500 pcs"). Single source of truth for the API and the UI --
     * the frontend renders whatever labels come back on the listing.
     */
    public const UNIT_TYPES = [
        'piece' => ['label' => 'Per Piece', 'short' => 'pc', 'plural' => 'pcs'],
        'kilogram' => ['label' => 'Per Kilogram', 'short' => 'kg', 'plural' => 'kg'],
        'bulk' => ['label' => 'Per Bulk', 'short' => 'bulk', 'plural' => 'bulk'],
    ];

    /**
     * What a seller may actually pick. Fingerlings are counted, not weighed,
     * so selling them by the kilogram was dropped -- a weight cannot be turned
     * into a number of fish, which left those listings unable to state what a
     * buyer was getting and out of the Turnout/ROI projection entirely.
     *
     * 'kilogram' stays in UNIT_TYPES above on purpose. Any listing created
     * while it was offered keeps its correct "kg" labels everywhere it is
     * displayed; it simply cannot be chosen again, and editing such a listing
     * means choosing one of these instead.
     */
    public const SELECTABLE_UNIT_TYPES = ['piece', 'bulk'];

    protected $appends = ['unit_label', 'unit_label_plural', 'unit_type_label', 'unit_contents_label', 'available_bulks'];

    /** Falls back to 'piece' so a listing predating this feature still reads correctly. */
    private function unitMeta(): array
    {
        return self::UNIT_TYPES[$this->unit_type] ?? self::UNIT_TYPES['piece'];
    }

    public function getUnitLabelAttribute(): string
    {
        return $this->unitMeta()['short'];
    }

    public function getUnitLabelPluralAttribute(): string
    {
        return $this->unitMeta()['plural'];
    }

    public function getUnitTypeLabelAttribute(): string
    {
        return $this->unitMeta()['label'];
    }

    /**
     * Plain-language contents of one unit -- "1 bulk = 10 fish" -- so the
     * seller's form, the buyer's listing page and the order summary all show
     * the same sentence instead of each composing their own.
     *
     * Null when there is nothing worth saying: a per-piece listing (one piece
     * is one fish) or a listing whose seller has not stated a count.
     */
    public function getUnitContentsLabelAttribute(): ?string
    {
        if (! $this->pieces_per_unit) {
            return null;
        }

        return '1 bulk = '.number_format($this->pieces_per_unit).' quantity';
    }

    /** Stock expressed in whole bulks, for the buyer's bulk option. */
    public function getAvailableBulksAttribute(): ?int
    {
        return $this->availableBulks();
    }

    /**
     * The order's quantity expressed as a count of individual fish, or null
     * when the listing cannot say. Used by the Turnout/ROI projection, which
     * is per-fish maths and must never guess at a missing count.
     */
    public function piecesFor(int $quantity): ?int
    {
        if ($this->unit_type === 'piece' || $this->unit_type === null) {
            return $quantity;
        }

        return $this->pieces_per_unit ? $quantity * $this->pieces_per_unit : null;
    }

    /** The smallest order this listing accepts, never below 1. */
    public function minimumOrder(): int
    {
        return max(1, (int) ($this->minimum_order ?? 1));
    }

    /**
     * How many fish make up one bulk.
     *
     * EVERYTHING IS COUNTED IN FISH -- stock, minimum order, order quantity and
     * the price, which is the price of ONE fish. A listing has no unit of
     * measurement of its own; "bulk" is purely a convenience the BUYER can
     * choose at order time, and it is converted to fish before it reaches the
     * API. So this number never takes part in stock or money maths: it only
     * tells the buyer that 5 bulks means 50 fish.
     */
    public function bulkSize(): ?int
    {
        return $this->pieces_per_unit ? max(1, (int) $this->pieces_per_unit) : null;
    }

    /** Whole bulks the remaining stock makes up, for display. */
    public function availableBulks(): ?int
    {
        $size = $this->bulkSize();

        return $size ? intdiv(max(0, (int) $this->quantity), $size) : null;
    }

    /**
     * Why the given quantity can't be ordered, or null when it can. Shared by
     * OrderController (placing an order) and CartController (saving one) so
     * both enforce the minimum and the stock ceiling identically.
     */
    public function quantityIssue(int $quantity): ?string
    {
        $minimum = $this->minimumOrder();

        if ($quantity < $minimum) {
            return sprintf(
                'This seller accepts a minimum order of %s %s. Please order at least that much.',
                number_format($minimum),
                $minimum === 1 ? $this->unit_label : $this->unit_label_plural
            );
        }

        if ($quantity > (int) $this->quantity) {
            return 'Requested quantity exceeds available stock.';
        }

        return null;
    }

    public function sellerProfile()
    {
        return $this->belongsTo(SellerProfile::class);
    }

    public function municipality()
    {
        return $this->belongsTo(Municipality::class);
    }

    public function media()
    {
        return $this->hasMany(ListingMedia::class, 'listing_id')->orderBy('position');
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'listing_id');
    }
}
