<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Engagement on Seller Posts -- likes and comments. Open to every
 * authenticated role (buyers, sellers, LGU admins, Super Admin), not just the
 * post owner. Both cascade away with their post (and with the user, if that
 * account is ever removed). The unique index on likes makes a like idempotent
 * -- one per user per post.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_post_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_post_id')->constrained('seller_posts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['seller_post_id', 'user_id']);
        });

        Schema::create('seller_post_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_post_id')->constrained('seller_posts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['seller_post_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_post_comments');
        Schema::dropIfExists('seller_post_likes');
    }
};
