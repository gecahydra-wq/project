<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The marketplace commission model changed again (Platform Revenue moved
 * from an order-settlement share to a withdrawal payout fee -- see
 * App\Support\CommissionCalculator). Every existing order/payment/
 * settlement/withdrawal predates this change and mixes at least two
 * different revenue-sharing rules, so rather than attempt another
 * historical recalculation, this wipes all transactional data clean:
 * orders, payments, settlements, and withdrawal requests. Deleting orders
 * cascades (via FK ON DELETE CASCADE) to payments, payment_logs,
 * settlements, and reviews automatically.
 *
 * Users, seller profiles, listings, and municipalities are untouched --
 * sellers can immediately place fresh test orders against their existing
 * listings under the new fixed model. seller_profiles.rating is reset
 * alongside the review wipe since it's a cached average with nothing left
 * to average.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('withdrawal_requests')->delete();
        DB::table('orders')->delete();
        DB::table('seller_profiles')->update(['rating' => 0]);
    }

    public function down(): void
    {
        // Intentionally irreversible -- this is a one-way reset to a clean
        // transactional slate; there is no prior state worth restoring to.
    }
};
