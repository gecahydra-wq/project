<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Global announcements broadcast to Buyers/Sellers/LGU Admins (see
 * App\Http\Controllers\Api\AnnouncementController). starts_at/expires_at
 * control the display window; notified_at marks whether the one-time
 * notification fan-out (see App\Support\AnnouncementNotifier) has already
 * happened for this announcement, so it never fires twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->string('category')->default('general'); // maintenance|update|policy|holiday|general
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->index(['starts_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
