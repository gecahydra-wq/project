<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support tickets -- a Buyer or Seller asking AbaiMarket for help with the
 * platform itself (an order, a payment, a withdrawal, their account, a bug).
 *
 * Unrelated to user_reports (a complaint about another person) and disputes
 * (an appeal against a rejection); the ticket form points users at those.
 *
 * Every ticket is shared: the LGU Admins of its municipality and every Super
 * Admin can see and answer it, and whoever picks it up replies (see
 * App\Support\SupportTickets). The category only describes the topic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number')->nullable()->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('user_role');
            $table->string('category');
            $table->string('subject');
            $table->foreignId('municipality_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            // open (waiting on staff) -> answered (waiting on the user) -> resolved.
            // A reply from the user moves it back to open, including a resolved one.
            $table->string('status')->default('open');
            $table->timestamp('last_activity_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['municipality_id', 'status']);
            $table->index(['user_id', 'last_activity_at']);
        });

        Schema::create('support_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_role');
            $table->text('body');
            // Optional screenshot, one per message.
            $table->string('attachment_url')->nullable();
            // Same edit/delete rules as user-to-user messages: the author may
            // edit within a short window, and a delete blanks the message in
            // place so the thread keeps its shape.
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->index(['support_ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
    }
};
