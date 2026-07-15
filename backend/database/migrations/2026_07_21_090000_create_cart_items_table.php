<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Buyer's "buy later" cart -- saved listings, not reserved stock. A cart
 * row deliberately holds no price snapshot and never decrements
 * listings.quantity: nothing is reserved until the buyer actually places the
 * order (see OrderController::store, which stays the single place an order and
 * its stock reservation are created). Price and availability are therefore
 * always read live from the listing at display/checkout time.
 *
 * One row per (buyer, listing) -- adding an already-saved listing adjusts the
 * existing row's quantity rather than creating a duplicate (see CartController).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();

            $table->unique(['buyer_id', 'listing_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
