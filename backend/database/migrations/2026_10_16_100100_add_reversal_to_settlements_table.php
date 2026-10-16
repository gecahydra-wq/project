<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a settlement be undone.
 *
 * A Settlement is the immutable record of an LGU approving an order's
 * earnings: it credits the seller's share, the LGU's share and the platform's
 * share, and it is what every balance in the app is summed from. Until now it
 * could only ever be created, because money only ever moved one way.
 *
 * The post-completion refund is the first flow that has to move it back: if a
 * buyer is refunded after the earnings were released, the seller's share can
 * no longer stand, or the seller would be paid for an order the buyer got
 * their money back on. Reversing the row (rather than deleting it) keeps the
 * history -- the reversal is a fact worth recording, and the settlement row is
 * still the evidence that a payout was computed.
 *
 * `reversed_at` is the marker every Settlement reader now filters on
 * (`whereNull('reversed_at')`). Every existing row has it NULL, so this is a
 * no-op for the data that is already there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->timestamp('reversed_at')->nullable()->after('settled_at');
            $table->foreignId('reversed_by')->nullable()->after('reversed_at')->constrained('users')->nullOnDelete();
            $table->text('reversal_reason')->nullable()->after('reversed_by');
        });
    }

    public function down(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn(['reversed_at', 'reversal_reason']);
        });
    }
};
