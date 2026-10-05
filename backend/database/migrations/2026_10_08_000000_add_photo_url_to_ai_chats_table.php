<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A question to the AI Assistant can carry one photo (muddy pond water, a
 * sick fish, a screen). The URL is kept so the chat history can show it again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_chats', function (Blueprint $table) {
            $table->string('photo_url')->nullable()->after('message');
        });
    }

    public function down(): void
    {
        Schema::table('ai_chats', function (Blueprint $table) {
            $table->dropColumn('photo_url');
        });
    }
};
