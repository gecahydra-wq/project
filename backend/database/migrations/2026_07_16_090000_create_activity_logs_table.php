<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Write-side of the Global Activity Log / Audit Trail (see
 * App\Support\ActivityLog). Only holds event types that have no existing
 * table of their own -- suspend/reinstate, earnings approval, and payout
 * requests/approvals are read live from moderation_logs/settlements/
 * withdrawal_requests/lgu_withdrawal_requests instead of being duplicated
 * here (see App\Support\ActivityLog::query).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role')->nullable();
            $table->string('action');
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('municipality_id')->nullable()->constrained('municipalities')->nullOnDelete();
            $table->string('reference_type')->nullable();
            $table->string('reference_number')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['municipality_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
