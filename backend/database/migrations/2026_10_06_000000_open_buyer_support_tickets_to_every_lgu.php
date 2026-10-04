<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Buyers have no municipality, so a buyer's ticket that is not about an order
 * now belongs to none and every LGU can see it (App\Support\SupportTickets).
 * Tickets sent before that rule were tied to the buyer's profile municipality;
 * this opens them up the same way. Order-linked tickets keep the seller's.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('support_tickets')
            ->where('user_role', 'buyer')
            ->whereNull('order_id')
            ->whereNotNull('municipality_id')
            ->update(['municipality_id' => null]);
    }

    public function down(): void
    {
        // The original municipality is not kept; nothing to restore.
    }
};
