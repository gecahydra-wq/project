<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Listings no longer wait for per-listing approval -- a verified seller's post
 * goes live immediately (see ListingController::store).
 *
 * Anything sitting in 'pending' was queued under the old rule and would
 * otherwise stay invisible forever, since nothing will ever work that queue
 * again. Those are released here.
 *
 * 'rejected' and 'archived' are deliberately left alone: an LGU Admin or the
 * Super Admin actively took those down, and removing the approval step is not
 * a reason to overturn a moderation decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('listings')->where('approval_status', 'pending')->update(['approval_status' => 'approved']);
    }

    public function down(): void
    {
        // Not reversible: an auto-approved listing is indistinguishable from
        // one an LGU approved by hand under the old rule, so sending them all
        // back to 'pending' would hide listings that were legitimately
        // reviewed.
    }
};
