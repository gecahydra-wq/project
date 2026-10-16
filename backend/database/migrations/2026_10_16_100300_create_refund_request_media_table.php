<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evidence photos attached to a refund request.
 *
 * Mirrors seller_post_media (type/title/url/position) so the existing
 * ImageUploader pipeline and the frontend MediaGallery/lightbox are reused
 * unchanged -- the buyer attaches photos with the very same code that uploads a
 * listing photo, and the Super Admin views them with the very same gallery.
 *
 * A request may have zero of these: evidence is optional, unlike a listing
 * photo. `url` is nullable for the same reason listing_media's is -- the row is
 * created before the upload settles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_request_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('refund_request_id')->constrained('refund_requests')->cascadeOnDelete();
            $table->string('type');
            $table->string('title');
            $table->string('url')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_request_media');
    }
};
