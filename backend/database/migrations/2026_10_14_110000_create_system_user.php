<?php

use App\Models\User;
use App\Support\SystemMessenger;
use Illuminate\Database\Migrations\Migration;

/**
 * The AbaiMarket system account: the sender every automatic message comes
 * from (auto-completed orders and payouts, out-for-delivery notices), so
 * they never appear to come from a real person's Super Admin account.
 *
 * SystemMessenger::user() is a firstOrCreate, so this is a no-op where the
 * row already exists -- and sending stays safe on a database where it is
 * somehow missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        SystemMessenger::user();
    }

    public function down(): void
    {
        User::where('email', SystemMessenger::EMAIL)->where('role', 'system')->delete();
    }
};
