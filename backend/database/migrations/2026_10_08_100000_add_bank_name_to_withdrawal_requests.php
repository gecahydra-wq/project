<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A bank transfer now records WHICH bank the account is with (see
 * App\Support\PayoutAccount::BANKS), so the Super Admin knows where to send
 * it. Null for GCash / Maya and for requests made before this column existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['withdrawal_requests', 'lgu_withdrawal_requests'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('bank_name', 100)->nullable()->after('method');
            });
        }
    }

    public function down(): void
    {
        foreach (['withdrawal_requests', 'lgu_withdrawal_requests'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('bank_name');
            });
        }
    }
};
