<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Listing freeze for sellers under a Notice to Explain.
 *
 * A low average rating now hides a seller's listings from the marketplace
 * until their LGU lifts it, so the freeze needs its own field: it is not the
 * same thing as suspension. A frozen seller can still sign in, answer their
 * notice, message buyers and fulfil orders already placed -- only their
 * listings come down. Suspension (seller_profiles.status) remains the heavier,
 * manual sanction and is what the third rejected explanation triggers.
 *
 * Nullable timestamp rather than a boolean so the audit question "since when?"
 * is answerable from the row itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_profiles', function (Blueprint $table) {
            $table->timestamp('listings_frozen_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('seller_profiles', function (Blueprint $table) {
            $table->dropColumn('listings_frozen_at');
        });
    }
};
