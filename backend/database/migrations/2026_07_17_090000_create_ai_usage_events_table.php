<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI Usage Analytics -- aggregate-only telemetry for each AI Assistant
 * request. Deliberately stores NO message or response content (that lives in
 * ai_chats for the chat-history feature); this table exists purely so the
 * Super Admin AI Usage Dashboard can report volumes, category mix, success vs
 * fallback rates, and average response time without ever touching what users
 * actually typed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('role')->index();
            $table->string('category')->nullable()->index();
            // true when Gemini was unreachable/failed and the app's own scripted
            // fallback answer was served instead of a live model response.
            $table->boolean('was_fallback')->default(false);
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_events');
    }
};
