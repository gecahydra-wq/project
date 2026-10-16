<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The authoritative moment an order was completed ('delivered' in
 * App\Support\OrderTimeline terms).
 *
 * Until now nothing recorded it: completeDelivery() only wrote
 * `auto_received_at`, and only for the automatic run -- a buyer-confirmed
 * order set that to null -- so the nearest thing to a completion time was
 * `updated_at`, which moves again the moment anyone touches the row (seller
 * notes, an LGU hold, a dispute). Nothing that needs a deadline can hang off
 * a timestamp like that, so this column is added rather than derived.
 *
 * It is deliberately NOT a redundant copy of order.status: status says
 * whether the order is complete, this says when, and only the second one can
 * answer "is it still inside the refund window".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable()->after('auto_received_at');

            // The refund-request window and the closing-window backstop both
            // scan completed orders by this column (see
            // App\Support\RefundRequests and orders:expire-refund-windows).
            $table->index(['status', 'completed_at']);
        });

        // Best available backfill for orders that are already complete.
        // `updated_at` is what App\Support\OrderTimeline has been presenting
        // as the delivery time all along, so using it here keeps the new
        // column consistent with what the app has already been showing.
        // Rows whose real completion predates the 7-day window simply fall
        // outside it, which is the correct outcome for them.
        DB::table('orders')
            ->where('status', 'completed')
            ->whereNull('completed_at')
            ->update(['completed_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['status', 'completed_at']);
            $table->dropColumn('completed_at');
        });
    }
};
