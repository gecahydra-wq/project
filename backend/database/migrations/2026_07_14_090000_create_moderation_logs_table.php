<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A single, unified audit trail for every account-level moderation action
 * across every role -- Buyer, Seller, and LGU Admin -- regardless of which
 * mechanism actually stores the resulting status (users.status for Buyers
 * and LGU Admins, seller_profiles.status for Sellers). See
 * App\Support\AccountModeration, which is the only writer of this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moderation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Snapshot of the account's role at the time of the action --
            // roles never change in this app, but this keeps the log
            // self-describing without a join for simple listing/filtering.
            $table->string('role');
            $table->foreignId('moderator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action'); // suspended|reinstated
            $table->string('reason')->nullable();
            $table->text('notes')->nullable();
            // The account's status immediately after this action -- lets the
            // log be read standalone (e.g. "was this a suspend or a
            // reinstate") without re-deriving it from the action string.
            $table->string('resulting_status');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['role', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moderation_logs');
    }
};
