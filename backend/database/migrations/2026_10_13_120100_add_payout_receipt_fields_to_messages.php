<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('message_type')->default('text')->after('body');
            $table->string('attachment_path')->nullable()->after('message_type');
            $table->string('withdrawal_type')->nullable()->after('attachment_path');
            $table->unsignedBigInteger('withdrawal_id')->nullable()->after('withdrawal_type');
            $table->index(['withdrawal_type', 'withdrawal_id']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['withdrawal_type', 'withdrawal_id']);
            $table->dropColumn(['message_type', 'attachment_path', 'withdrawal_type', 'withdrawal_id']);
        });
    }
};
