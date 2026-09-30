<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a seller cancelled an order.
 *
 * Cancelling was previously silent: the buyer was told only that the seller
 * cancelled, with no reason, and nothing was recorded for the LGU to review
 * later. A seller cancelling a PAID order sends it to the refund queue, so
 * this is the one seller action that moves money back -- it should say why.
 *
 * Nullable because it only applies to cancellations, and because every order
 * cancelled before this column existed has no reason to backfill. Expiry and
 * buyer-side cancellations leave it null too; the order's status already says
 * which of those happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->text('cancellation_reason')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('cancellation_reason');
        });
    }
};
