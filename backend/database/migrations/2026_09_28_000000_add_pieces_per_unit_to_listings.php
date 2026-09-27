<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many individual fish make up one saleable unit.
 *
 * 'bulk' was added as a unit of measurement without ever saying how much a
 * bulk contains, which left two things broken. A buyer could not tell whether
 * one bulk meant 10 fish or 1,000, and the Turnout/ROI projection had to drop
 * bulk purchases entirely, because a survival rate and a per-fish harvest
 * value need a COUNT of fish and a bulk gave it none.
 *
 * Nullable rather than defaulted: null means "the seller has not stated a
 * count", which is the honest state for every listing that predates this
 * column, and the projection keeps excluding those instead of inventing a
 * figure. New bulk listings are required to state it (see ListingController).
 *
 * Named pieces_per_unit, not pieces_per_bulk, because a per-kilogram listing
 * has the same "how many fish is that?" problem and can adopt the column later
 * without a rename.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->unsignedInteger('pieces_per_unit')->nullable()->after('unit_description');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('pieces_per_unit');
        });
    }
};
