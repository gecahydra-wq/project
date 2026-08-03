<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seller Registration Approval.
 *
 * NOTE: the two-stage (LGU then Super Admin) rule described below was
 * superseded the same day by
 * 2026_08_03_100000_simplify_seller_registration_approval_to_single_review --
 * one approval from either reviewer is enough. The columns added here are
 * unchanged; only the set of approval_status values differs. See
 * App\Support\SellerApproval for the current rules.
 *
 * A newly registered seller used to be immediately operational (status
 * 'pending', but nothing actually gated listing creation). Sellers now have to
 * clear LGU review and THEN Super Admin review before they can list -- see
 * App\Support\SellerApproval for the state machine.
 *
 * The workflow lives in its own `approval_status` column, deliberately
 * mirroring listings.approval_status rather than overloading
 * seller_profiles.status. That keeps `status` meaning exactly what it has
 * always meant (account standing: pending/verified/suspended, written by
 * App\Support\AccountModeration) so every existing `status === 'verified'` /
 * `status !== 'suspended'` check across the app keeps working untouched.
 * `verified` stays the derived "fully approved" boolean.
 *
 * Backfill is conservative: any seller already verified stays fully approved
 * (both review stamps set to their registration date so they are never sent
 * back through the queue), and everyone else lands at the front of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_profiles', function (Blueprint $table) {
            $table->string('approval_status')->default('pending_lgu')->after('status');
            $table->timestamp('lgu_reviewed_at')->nullable()->after('approval_status');
            $table->foreignId('lgu_reviewed_by')->nullable()->after('lgu_reviewed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('super_admin_reviewed_at')->nullable()->after('lgu_reviewed_by');
            $table->foreignId('super_admin_reviewed_by')->nullable()->after('super_admin_reviewed_at')->constrained('users')->nullOnDelete();
            $table->text('registration_rejection_reason')->nullable()->after('super_admin_reviewed_by');
            $table->string('rejected_by_role')->nullable()->after('registration_rejection_reason');
        });

        // Existing verified sellers are grandfathered in as fully approved --
        // they are already trading, and pushing them back into a review queue
        // would take their live listings off the market.
        DB::table('seller_profiles')
            ->where('verified', true)
            ->update([
                'approval_status' => 'approved',
                'lgu_reviewed_at' => DB::raw('created_at'),
                'super_admin_reviewed_at' => DB::raw('created_at'),
            ]);
    }

    public function down(): void
    {
        Schema::table('seller_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lgu_reviewed_by');
            $table->dropConstrainedForeignId('super_admin_reviewed_by');
            $table->dropColumn([
                'approval_status',
                'lgu_reviewed_at',
                'super_admin_reviewed_at',
                'registration_rejection_reason',
                'rejected_by_role',
            ]);
        });
    }
};
