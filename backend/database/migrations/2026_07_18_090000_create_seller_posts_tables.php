<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seller Posts -- a simplified farm/hatchery feed (harvests, restocks, farm
 * updates, feeding videos, announcements, behind-the-scenes). Deliberately
 * SEPARATE from listing media: posts belong to the seller profile, while
 * listing_media stays attached only to individual listings. The two never mix.
 *
 * seller_post_media mirrors listing_media (type/title/url/position) so the same
 * ImageUploader pipeline and the same MediaGallery/lightbox on the frontend can
 * be reused unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_profile_id')->constrained('seller_profiles')->cascadeOnDelete();
            $table->text('body')->nullable();
            // created_at is the post date; updated_at doubles as the "edited"
            // timestamp (it only diverges from created_at once a post is edited).
            $table->timestamps();

            $table->index(['seller_profile_id', 'created_at']);
        });

        Schema::create('seller_post_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_post_id')->constrained('seller_posts')->cascadeOnDelete();
            $table->string('type');
            $table->string('title');
            $table->string('url')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_post_media');
        Schema::dropIfExists('seller_posts');
    }
};
