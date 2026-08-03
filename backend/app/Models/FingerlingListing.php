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
        'average_size',
        'availability_status',
        'approval_status',
        'rejection_reason',
    ];

    protected $casts = [
        'price_per_piece' => 'decimal:2',
        'minimum_order' => 'integer',
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

    protected $appends = ['unit_label', 'unit_label_plural', 'unit_type_label'];

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

    /** The smallest order this listing accepts, never below 1. */
    public function minimumOrder(): int
    {
        return max(1, (int) ($this->minimum_order ?? 1));
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
