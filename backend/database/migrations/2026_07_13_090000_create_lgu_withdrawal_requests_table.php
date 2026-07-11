<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LGU Revenue Withdrawals -- mirrors the seller `withdrawal_requests` table
 * (see App\Support\SellerWallet / SellerController) but scoped to
 * municipality_id rather than seller_profile_id, since LGU revenue is a
 * shared municipal resource, not owned by one individual admin account.
 * Any LGU admin for a municipality can see and act on that municipality's
 * withdrawal requests; requested_by records who actually submitted it, for
 * notifications/email only. Unlike seller withdrawals, there is no
 * platform_fee column -- the business flow for this task takes the
 * Platform's cut at order settlement already (Settlement.platform_share),
 * not a second time on the LGU's own withdrawal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lgu_withdrawal_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('method');
            $table->string('account_name');
            $table->string('account_number');
            $table->decimal('amount', 10, 2);
            $table->string('status')->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['municipality_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lgu_withdrawal_requests');
    }
};
