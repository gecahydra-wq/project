<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The settlements reconstructed by the previous migration don't reconcile
 * against real historical withdrawal payouts made under the old system,
 * where a seller received 100% of the gross order amount. Sellers already
 * physically received more than the new fixed 90% Seller Share says they
 * ever earned, which would permanently violate the wallet's invariant that
 * Total Earnings == Available + Pending + Processing + Withdrawn.
 *
 * Rather than leave that inconsistency live, this resets Seller financial
 * history to a clean slate: the backfilled settlements and all withdrawal
 * requests are removed, and every previously-released payment is put back
 * into the LGU earnings-approval queue (paid_held) so it can be
 * re-approved through the real LguController::approveEarnings flow and
 * produce a correctly-split, internally consistent Settlement row going
 * forward. No orders, listings, users, or other records are touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settlements')->delete();
        DB::table('withdrawal_requests')->delete();
        DB::table('payments')
            ->where('status', 'released')
            ->update(['status' => 'paid_held', 'released_at' => null]);
    }

    public function down(): void
    {
        // Intentionally irreversible -- this is a one-way reset to a clean
        // financial slate; there is no prior state worth restoring to.
    }
};
