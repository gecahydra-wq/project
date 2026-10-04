<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support tickets become a contact form: the user fills in who they are and
 * how to reach them, staff reply by email, and there is no back-and-forth
 * chat. Staff can also leave internal notes the user never sees.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('user_role');
            $table->string('last_name')->nullable()->after('first_name');
            // Where replies are emailed. Pre-filled with the account email.
            $table->string('contact_email')->nullable()->after('last_name');
        });

        Schema::table('support_ticket_messages', function (Blueprint $table) {
            // Staff-only note: hidden from the ticket's owner and never emailed.
            $table->boolean('is_internal')->default(false)->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('support_ticket_messages', function (Blueprint $table) {
            $table->dropColumn('is_internal');
        });

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name', 'contact_email']);
        });
    }
};
