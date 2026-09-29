<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A listing no longer has a unit of measurement. Everything is counted in
 * single fish -- stock, minimum order, and the price, which is now the price
 * of ONE fish. "Bulk" survives only as a convenience the BUYER may choose at
 * order time, converted to fish before it reaches the API.
 *
 * THIS MIGRATION CHANGES MONEY. A listing that was priced per bulk is still
 * priced per bulk in the database until this runs, so leaving it alone would
 * reprice PHP 100.00-per-bulk-of-10 as PHP 100.00 PER FISH -- a tenfold rise
 * the seller never agreed to. Each converted row therefore has:
 *
 *   price_per_piece  divided by the bulk size (PHP 100/bulk -> PHP 10/fish, so
 *                    one bulk still costs the buyer PHP 100)
 *   minimum_order    multiplied by it (10 bulks -> 100 fish, same real floor)
 *   unit_type        set to 'piece', which is now the only meaning it has
 *
 * quantity was already converted to fish by
 * 2026_09_29_000000_convert_bulk_listing_stock_to_fish, so it is not touched
 * again here.
 *
 * Rows with no bulk size on file cannot be converted -- there is nothing to
 * divide by -- so they are left exactly as they are and their seller states a
 * bulk size the next time they edit the listing.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('listings')
            ->where('unit_type', 'bulk')
            ->whereNotNull('pieces_per_unit')
            ->where('pieces_per_unit', '>', 0)
            ->update([
                'price_per_piece' => DB::raw('ROUND(price_per_piece / pieces_per_unit, 2)'),
                // No clamp needed: minimum_order defaults to 1 and is validated
                // min:1, and the WHERE above guarantees pieces_per_unit >= 1, so
                // the product is always >= 1. (GREATEST is MySQL-only and would
                // break the SQLite test database.)
                'minimum_order' => DB::raw('minimum_order * pieces_per_unit'),
                'unit_type' => 'piece',
            ]);
    }

    public function down(): void
    {
        // Irreversible in the strict sense: unit_type was overwritten, so the
        // rows that used to be 'bulk' can no longer be told apart from rows
        // that always were 'piece'. Rolling this back would have to be done
        // from a backup rather than guessed at here.
    }
};
