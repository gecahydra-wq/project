<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sellers rating buyers is removed.
 *
 * A buyer's order history already shows whether they complete what they start,
 * which is the signal a seller or moderator can actually act on. A 1-5 score a
 * seller could leave out of irritation at a cancelled order added nothing, and
 * gave sellers a retaliation lever against buyers who exercised their rights.
 *
 * buyer_profiles.rating / ratings_count are cleared rather than dropped: they
 * are nullable, cost nothing, and keeping them means this migration does not
 * have to rebuild the table on SQLite. Nothing writes them any more --
 * ReviewModeration::refreshBuyerRating is gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('buyer_ratings');

        if (Schema::hasColumn('buyer_profiles', 'rating')) {
            DB::table('buyer_profiles')->update(['rating' => null, 'ratings_count' => 0]);
        }
    }

    /**
     * Recreates the table's shape so a rollback leaves a working schema, but
     * the ratings themselves are gone for good -- this is a feature removal,
     * not a reversible data migration.
     */
    public function down(): void
    {
        Schema::create('buyer_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->foreignId('seller_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->timestamps();
        });
    }
};
