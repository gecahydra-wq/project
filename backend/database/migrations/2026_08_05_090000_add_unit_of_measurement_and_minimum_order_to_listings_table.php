<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unit of Measurement + Minimum Order for listings.
 *
 * A listing used to be implicitly priced and counted per piece. Sellers can
 * now choose how they sell -- per piece, per kilogram, or per bulk -- and set
 * the smallest order they are willing to accept.
 *
 * IMPORTANT: `price_per_piece` is deliberately NOT renamed. It has always
 * meant "the price of one unit of this listing" and now simply carries the
 * unit named by `unit_type`; every existing consumer (orders.unit_price,
 * PayMongo line items, the cart, analytics, report exports, the AI
 * recommendation engine) keeps working untouched. Renaming it would have
 * rippled through all of them for no behavioural gain. `quantity` likewise
 * stays the stock count in whatever unit `unit_type` names.
 *
 * Existing listings default to 'piece' with a minimum of 1, which is exactly
 * how they behaved before, so nothing already on the marketplace changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            // piece | kilogram | bulk -- see FingerlingListing::UNIT_TYPES.
            $table->string('unit_type')->default('piece')->after('price_per_piece');
            // The smallest quantity a buyer may order, in the unit above.
            $table->unsignedInteger('minimum_order')->default(1)->after('unit_type');
            // What one unit actually contains -- mainly for 'bulk', where only
            // the seller can say whether a bulk is a sack, a crate, or 1,000
            // pieces. Shown to buyers next to the price.
            $table->string('unit_description')->nullable()->after('minimum_order');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn(['unit_type', 'minimum_order', 'unit_description']);
        });
    }
};
