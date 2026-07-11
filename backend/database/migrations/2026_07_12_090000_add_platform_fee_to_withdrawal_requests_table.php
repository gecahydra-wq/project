<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The marketplace commission model moved the Platform's cut from the order
 * settlement (previously a share of the gross order amount) to a payout fee
 * charged when a seller actually withdraws -- see
 * App\Support\CommissionCalculator::WITHDRAWAL_FEE_PERCENT. This column
 * freezes the fee amount at the moment the withdrawal is requested, exactly
 * like Settlement freezes its percentages, so a later change to the fee
 * percentage never retroactively alters an existing withdrawal request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('withdrawal_requests', function (Blueprint $table) {
            $table->decimal('platform_fee', 10, 2)->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('withdrawal_requests', function (Blueprint $table) {
            $table->dropColumn('platform_fee');
        });
    }
};
