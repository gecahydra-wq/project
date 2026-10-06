<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rejected withdrawals now HOLD their amount until the rejection is final
 * ('rejected_final'; see App\Support\WithdrawalRejection). Before this change a
 * rejection released the money immediately, and owners may already have
 * requested it again -- so every existing rejection is treated as final,
 * which is exactly how the money was being counted. The only exception is a
 * rejection with a dispute still open: that one keeps waiting for its
 * decision, as a new rejection would.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['withdrawal_requests' => 'App\\Models\\WithdrawalRequest', 'lgu_withdrawal_requests' => 'App\\Models\\LguWithdrawalRequest'] as $table => $morph) {
            DB::table($table)
                ->where('status', 'rejected')
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                    ->from('disputes')
                    ->where('disputes.disputable_type', $morph)
                    ->whereColumn('disputes.disputable_id', "{$table}.id")
                    ->where('disputes.status', 'open'))
                ->update(['status' => 'rejected_final']);
        }
    }

    public function down(): void
    {
        DB::table('withdrawal_requests')->where('status', 'rejected_final')->update(['status' => 'rejected']);
        DB::table('lgu_withdrawal_requests')->where('status', 'rejected_final')->update(['status' => 'rejected']);
    }
};
