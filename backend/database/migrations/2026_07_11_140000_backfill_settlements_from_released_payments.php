<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reconstructs one Settlement row for every payment that was already
 * 'released' before the settlements table existed. Those earlier releases
 * paid sellers out (and the seller/LGU/platform split simply wasn't
 * recorded) rather than losing any data -- every one of these payments
 * still has a real order_id, seller_profile_id, and municipality_id to
 * rebuild from, so the fixed marketplace split (see
 * App\Support\CommissionCalculator) is reliably reconstructable. Uses the
 * payment's released_at as settled_at, since that is when the earnings were
 * actually approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sellerPercent = 90.0;
        $lguPercent = 4.0;
        $platformPercent = 6.0;

        $payments = DB::table('payments')
            ->join('orders', 'payments.order_id', '=', 'orders.id')
            ->join('seller_profiles', 'orders.seller_profile_id', '=', 'seller_profiles.id')
            ->where('payments.status', 'released')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('settlements')
                    ->whereColumn('settlements.order_id', 'orders.id');
            })
            ->select([
                'payments.id as payment_id',
                'payments.amount as gross_amount',
                'payments.released_at',
                'orders.id as order_id',
                'orders.seller_profile_id',
                'seller_profiles.municipality_id',
            ])
            ->get();

        $now = now();

        foreach ($payments as $payment) {
            $gross = (float) $payment->gross_amount;
            $sellerShare = round($gross * $sellerPercent / 100, 2);
            $lguShare = round($gross * $lguPercent / 100, 2);
            $platformShare = round($gross - $sellerShare - $lguShare, 2);

            DB::table('settlements')->insert([
                'order_id' => $payment->order_id,
                'payment_id' => $payment->payment_id,
                'seller_profile_id' => $payment->seller_profile_id,
                'municipality_id' => $payment->municipality_id,
                'approved_by' => null,
                'gross_amount' => $gross,
                'seller_share' => $sellerShare,
                'lgu_share' => $lguShare,
                'platform_share' => $platformShare,
                'seller_percent' => $sellerPercent,
                'lgu_percent' => $lguPercent,
                'platform_percent' => $platformPercent,
                'status' => 'settled',
                'settled_at' => $payment->released_at ?? $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Intentionally irreversible -- these rows represent real historical
        // earnings that sellers, LGUs, and the platform have already
        // recognized; rolling back would silently zero out real balances.
    }
};
