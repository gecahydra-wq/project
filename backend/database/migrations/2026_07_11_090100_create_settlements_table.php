<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One immutable settlement per order, created the moment an LGU Admin
 * approves that order's earnings (see LguController::approveEarnings). The
 * percentages and computed shares are snapshotted here at creation time, so
 * a later change to commission_settings can never retroactively change what
 * an already-settled order paid out to the seller/LGU/platform.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('seller_profile_id')->constrained('seller_profiles')->cascadeOnDelete();
            $table->foreignId('municipality_id')->constrained('municipalities')->cascadeOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->decimal('gross_amount', 12, 2);
            $table->decimal('seller_share', 12, 2);
            $table->decimal('lgu_share', 12, 2);
            $table->decimal('platform_share', 12, 2);

            // The exact percentages used for this settlement, preserved
            // independently of whatever commission_settings holds today.
            $table->decimal('seller_percent', 5, 2);
            $table->decimal('lgu_percent', 5, 2);
            $table->decimal('platform_percent', 5, 2);

            $table->string('status')->default('settled');
            $table->timestamp('settled_at');
            $table->timestamps();

            $table->index(['municipality_id', 'settled_at']);
            $table->index(['seller_profile_id', 'settled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlements');
    }
};
