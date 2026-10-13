<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('in_transit_at')->nullable()->after('status');
        });

        DB::table('orders')
            ->where('status', 'in_transit')
            ->whereNull('in_transit_at')
            ->update(['in_transit_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('in_transit_at');
        });
    }
};
