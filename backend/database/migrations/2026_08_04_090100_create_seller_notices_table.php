<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notices to Explain -- raised automatically when a seller's average rating
 * falls to 3 stars or below (see App\Support\SellerReputation).
 *
 * A notice is a REQUEST FOR AN EXPLANATION, never a penalty: raising one
 * notifies the seller and their LGU Admin and nothing else. It deliberately
 * does not touch seller_profiles.status or .verified, so a low rating can
 * never auto-suspend anyone -- the LGU Admin reads the seller's response and
 * decides what, if anything, to do (suspending still goes through
 * App\Support\AccountModeration exactly as before).
 *
 * average_rating/ratings_count are snapshotted at the moment the notice is
 * raised: the seller's live average will move as new reviews arrive, and the
 * LGU needs to see the figure the notice was actually issued for.
 *
 * At most one OPEN notice per seller at a time (enforced in SellerReputation)
 * so a run of bad reviews produces one conversation, not twenty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_profile_id')->constrained('seller_profiles')->cascadeOnDelete();
            $table->foreignId('municipality_id')->nullable()->constrained()->nullOnDelete();
            // Only 'low_rating' today; a column rather than a hardcoded
            // assumption so a future trigger doesn't need a new table.
            $table->string('type')->default('low_rating');
            $table->decimal('average_rating', 3, 2)->nullable();
            $table->unsignedInteger('ratings_count')->default(0);
            $table->text('details')->nullable();
            // open -> under_review -> resolved|dismissed
            $table->string('status')->default('open');
            // The seller's explanation -- the point of the notice.
            $table->text('seller_response')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->text('lgu_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['municipality_id', 'status']);
            $table->index(['seller_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_notices');
    }
};
