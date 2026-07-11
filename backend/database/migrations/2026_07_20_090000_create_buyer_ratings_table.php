<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buyer Ratings -- the reverse of Reviews. A seller rates a buyer after a
 * completed order (reliable payment, smooth transaction, etc.) so other
 * sellers can tell a legitimate buyer from a scammer/troll. One rating per
 * order, exactly like a Review. The aggregate is cached on buyer_profiles
 * (rating + ratings_count) the same way seller_profiles.rating caches the
 * seller's average.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyer_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->foreignId('seller_profile_id')->constrained('seller_profiles')->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index('buyer_id');
        });

        Schema::table('buyer_profiles', function (Blueprint $table) {
            $table->decimal('rating', 3, 2)->nullable()->after('bio');
            $table->unsignedInteger('ratings_count')->default(0)->after('rating');
        });
    }

    public function down(): void
    {
        Schema::table('buyer_profiles', function (Blueprint $table) {
            $table->dropColumn(['rating', 'ratings_count']);
        });

        Schema::dropIfExists('buyer_ratings');
    }
};
