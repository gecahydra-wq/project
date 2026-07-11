<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Email verification is now enforced at login. Accounts created before this
 * upgrade were never asked to verify an email address, so treat every
 * pre-existing account as already trusted rather than locking real users
 * out -- this only fills in the (currently null) email_verified_at column,
 * it never touches any other user data.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        // Intentionally irreversible -- rolling back would re-lock every
        // real account that existed before this migration ran.
    }
};
