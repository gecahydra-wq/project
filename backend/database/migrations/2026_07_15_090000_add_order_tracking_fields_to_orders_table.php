<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds Order Number-centric transaction review fields to orders, all
 * nullable so every existing row/query is unaffected. Backs the Unified
 * Order Tracking & Order Lookup feature (see App\Support\OrderTimeline,
 * App\Support\OrderTransactionPresenter, LguController hold/reject actions).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->text('seller_notes')->nullable()->after('pickup_notes');

            // LGU transaction review state, distinct from order.status and
            // payment.status -- see LguController::holdEarnings/rejectEarnings.
            // 'on_hold' | 'rejected'; null means no review action taken.
            $table->string('lgu_review_status')->nullable()->after('status');
            $table->string('lgu_review_reason')->nullable()->after('lgu_review_status');
            $table->timestamp('lgu_reviewed_at')->nullable()->after('lgu_review_reason');
            $table->foreignId('lgu_reviewed_by')->nullable()->after('lgu_reviewed_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lgu_reviewed_by');
            $table->dropColumn(['seller_notes', 'lgu_review_status', 'lgu_review_reason', 'lgu_reviewed_at']);
        });
    }
};
