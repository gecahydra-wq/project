<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('profile_picture')->nullable();
        });

        Schema::table('buyer_profiles', function (Blueprint $table) {
            $table->string('address')->nullable();
            $table->text('bio')->nullable();
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('profile_picture');
        });

        Schema::table('buyer_profiles', function (Blueprint $table) {
            $table->dropColumn(['address', 'bio']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['edited_at', 'deleted_at']);
        });
    }
};
