<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_profiles', function (Blueprint $table) {
            $table->string('water_source')->nullable();
            $table->text('feeding_practices')->nullable();
            $table->unsignedSmallInteger('years_experience')->nullable();
            $table->text('certifications')->nullable();
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->string('title')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('seller_profiles', function (Blueprint $table) {
            $table->dropColumn(['water_source', 'feeding_practices', 'years_experience', 'certifications']);
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn('title');
        });
    }
};
