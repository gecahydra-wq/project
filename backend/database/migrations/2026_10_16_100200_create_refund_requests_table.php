<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A buyer's post-completion refund request, and the Super Admin's decision on
 * it.
 *
 * This is deliberately separate from the cancellation refund path
 * (App\Support\OrderCancellation). A cancellation refund is triggered by the
 * app itself -- the order is cancelled, the money is queued back, nobody has
 * to decide anything. This one is a request: the buyer asks, and a person
 * decides. Mixing the two into one table/status would make "the refund the
 * system queued" and "the refund a buyer asked for" indistinguishable, which
 * is exactly what must not happen.
 *
 * `order_id` is UNIQUE rather than merely indexed: eligibility is "one request
 * per order", and a unique index is the only duplicate guard that holds when
 * two requests arrive at once. (The Disputes table enforces its one-at-a-time
 * rule in code instead, because a rejected appeal may be filed again; a
 * rejected refund request may not, so the database can enforce this one.)
 *
 * Notice what is NOT stored: no deadline and no "expired" flag. Whether an
 * order is still inside its window is derived from orders.completed_at and the
 * configured window length (see App\Support\RefundRequests), so a request that
 * is past its deadline is still a perfectly normal pending request -- it is
 * just no longer *creatable*. That is what keeps the scheduler from having to
 * mutate pending requests when a window closes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();

            $table->text('reason');
            // pending -> approved | denied. The refund amount is NOT stored:
            // it is the payment's own amount, which is the only figure that
            // cannot be tampered with from the client.
            $table->string('status')->default('pending');

            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            // Required on denial, null on approval -- the admin has to say why.
            $table->text('denial_reason')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_requests');
    }
};
