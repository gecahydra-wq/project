<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('withdrawal_requests', function (Blueprint $table) {
            $table->timestamp('recipient_confirmed_at')->nullable()->after('paid_at');
            $table->foreignId('recipient_confirmed_by')->nullable()->after('recipient_confirmed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable()->after('recipient_confirmed_by');
            $table->foreignId('sent_by')->nullable()->after('sent_at')->constrained('users')->nullOnDelete();
            $table->timestamp('auto_completed_at')->nullable()->after('sent_by');
        });

        Schema::table('lgu_withdrawal_requests', function (Blueprint $table) {
            $table->timestamp('recipient_confirmed_at')->nullable()->after('paid_at');
            $table->foreignId('recipient_confirmed_by')->nullable()->after('recipient_confirmed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable()->after('recipient_confirmed_by');
            $table->foreignId('sent_by')->nullable()->after('sent_at')->constrained('users')->nullOnDelete();
            $table->timestamp('auto_completed_at')->nullable()->after('sent_by');
        });
    }

    public function down(): void
    {
        Schema::table('withdrawal_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recipient_confirmed_by');
            $table->dropConstrainedForeignId('sent_by');
            $table->dropColumn(['recipient_confirmed_at', 'sent_at', 'auto_completed_at']);
        });

        Schema::table('lgu_withdrawal_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recipient_confirmed_by');
            $table->dropConstrainedForeignId('sent_by');
            $table->dropColumn(['recipient_confirmed_at', 'sent_at', 'auto_completed_at']);
        });
    }
};
