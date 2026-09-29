<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stock is now counted in FISH for every listing, whatever unit it is sold in.
 *
 * Before this, listings.quantity held a number of UNITS: a bulk listing with
 * quantity 980 meant 980 bulks. Now it means 980 fish, and ordering 5 bulks of
 * 10 takes 50 fish off it (see FingerlingListing::piecesPerUnit).
 *
 * Existing bulk rows therefore have to be converted, or 980 bulks would
 * silently become 980 fish -- a 10x loss of stock for every bulk seller on the
 * marketplace. THIS MIGRATION CHANGES DATA, not just schema.
 *
 * Per-piece listings are already counted in fish (one piece is one fish) and
 * are left alone. Bulk listings with no stated pieces_per_unit are also left
 * alone: with no bulk size on file there is nothing to multiply by, and
 * piecesPerUnit() treats them as 1, which keeps their current number correct.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('listings')
            ->where('unit_type', 'bulk')
            ->whereNotNull('pieces_per_unit')
            ->where('pieces_per_unit', '>', 0)
            ->update(['quantity' => DB::raw('quantity * pieces_per_unit')]);
    }

    public function down(): void
    {
        // Integer division: a stock that is not a whole number of bulks rounds
        // down, which is the safe direction (never invents stock).
        DB::table('listings')
            ->where('unit_type', 'bulk')
            ->whereNotNull('pieces_per_unit')
            ->where('pieces_per_unit', '>', 0)
            ->update(['quantity' => DB::raw('FLOOR(quantity / pieces_per_unit)')]);
    }
};
