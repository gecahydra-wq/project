<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User Reports -- a Buyer reporting a Seller, or a Seller reporting a Buyer.
 *
 * Deliberately ONE table for both directions: the two reports carry identical
 * information (who reported whom, why, when, and where it stands), and a
 * single table is what lets the LGU and Super Admin dashboards show them in
 * one list instead of two that must be kept in sync.
 *
 * This is a *complaint about a person* and is unrelated to moderation_logs
 * (the record of an action an admin already took) or to the revenue "Reports"
 * pages. An admin acting on a report still suspends through
 * App\Support\AccountModeration as usual -- resolving a report never changes
 * an account's standing by itself.
 *
 * municipality_id is denormalised on purpose: it is always the SELLER side's
 * municipality (whichever party that is), and it is what scopes an LGU Admin
 * to their own jurisdiction without joining through two possible paths.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reported_user_id')->constrained('users')->cascadeOnDelete();
            // Role snapshots, so the list reads correctly ("Buyer -> Seller")
            // without joining users twice just to label a row.
            $table->string('reporter_role');
            $table->string('reported_role');
            $table->foreignId('municipality_id')->nullable()->constrained()->nullOnDelete();
            // Optional transaction context -- most complaints are about a
            // specific order, but a report can also be filed without one.
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason');
            $table->text('description');
            // pending -> under_review -> resolved|dismissed
            $table->string('status')->default('pending');
            $table->text('resolution_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['municipality_id', 'status']);
            $table->index(['reported_user_id', 'status']);
            $table->index(['reporter_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_reports');
    }
};
