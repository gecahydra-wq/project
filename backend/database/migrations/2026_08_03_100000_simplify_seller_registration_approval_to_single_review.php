<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Collapses the seller registration approval queue from two sequential stages
 * into a single review.
 *
 * The first cut of this workflow required BOTH the LGU Admin and the Super
 * Admin to approve. That is not how the roles actually work: ONE approval is
 * enough. The LGU Admin normally reviews the sellers in their own
 * municipality, and the Super Admin is the fallback who can approve any
 * registration when that LGU Admin is away -- neither waits on the other.
 *
 * So 'pending_lgu' and 'pending_super_admin' both become the single 'pending'
 * state. Anything already 'approved' or 'rejected' is untouched, and a seller
 * who had cleared only the LGU stage lands back in the shared queue (they were
 * never verified under the old rules either, so nothing is taken away).
 *
 * The companion migration that introduced these columns is
 * 2026_08_03_090000_add_registration_approval_to_seller_profiles_table.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('seller_profiles')
            ->whereIn('approval_status', ['pending_lgu', 'pending_super_admin'])
            ->update(['approval_status' => 'pending']);

        Schema::table('seller_profiles', function (Blueprint $table) {
            $table->string('approval_status')->default('pending')->change();
        });
    }

    public function down(): void
    {
        Schema::table('seller_profiles', function (Blueprint $table) {
            $table->string('approval_status')->default('pending_lgu')->change();
        });

        DB::table('seller_profiles')
            ->where('approval_status', 'pending')
            ->update(['approval_status' => 'pending_lgu']);
    }
};
