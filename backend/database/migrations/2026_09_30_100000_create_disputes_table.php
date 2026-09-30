<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Appeals against a rejection.
 *
 * Before this, a rejected earnings review or a rejected withdrawal was final
 * from the seller's side: they were told the reason and had no way to answer
 * it. The reviewer could reopen an earnings rejection
 * (LguController::reopenRejectedEarnings) but had no reason to -- nothing
 * prompted them. This closes that loop in the direction it was missing.
 *
 * Polymorphic because the three things that can be rejected -- an order's
 * earnings review, a seller's withdrawal, an LGU's withdrawal -- are unrelated
 * tables that need identical handling: explain, then accept or reject the
 * explanation. One table keeps that rule in one place rather than growing three
 * parallel sets of columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->morphs('disputable');
            // Who is appealing: the seller for their own earnings/withdrawal,
            // an LGU admin for their municipality's withdrawal.
            $table->foreignId('filed_by')->constrained('users')->cascadeOnDelete();
            $table->text('reason');
            // open -> accepted (the rejection is undone and the item goes back
            // into the reviewer's queue) or rejected (the rejection stands).
            $table->string('status')->default('open');
            $table->text('resolution_note')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            // One appeal per subject at a time is enforced in code rather than
            // by a unique index: a seller whose first appeal was rejected may
            // file again if they have something new, and the history of both
            // is worth keeping.
            $table->index(['disputable_type', 'disputable_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disputes');
    }
};
