<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_profiles', function (Blueprint $table) {
            $table->text('farming_methods')->nullable();
            $table->text('fish_raising_practices')->nullable();
            $table->text('farm_history')->nullable();
            $table->string('address')->nullable();
            $table->string('profile_picture')->nullable();
            $table->string('cover_photo')->nullable();
            $table->json('gallery')->nullable();
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->text('description')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('seller_profiles', function (Blueprint $table) {
            $table->dropColumn(['farming_methods', 'fish_raising_practices', 'farm_history', 'address', 'profile_picture', 'cover_photo', 'gallery']);
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
