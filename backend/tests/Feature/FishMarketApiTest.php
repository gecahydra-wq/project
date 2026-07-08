<?php

namespace Tests\Feature;

use App\Models\FingerlingListing;
use App\Models\AppNotification;
use App\Models\MockPayment;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FishMarketApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_buyers_can_filter_listings_by_species(): void
    {
        $response = $this->getJson('/api/listings?species=Bangus');

        $response->assertOk()
            ->assertJsonFragment(['species' => 'Bangus'])
            ->assertJsonMissing(['species' => 'Tilapia']);
    }

    public function test_order_creation_holds_mock_payment_and_reduces_inventory(): void
    {
        $buyer = User::where('role', 'buyer')->firstOrFail();
        $listing = FingerlingListing::where('species', 'Bangus')->firstOrFail();
        Sanctum::actingAs($buyer);

        $response = $this->postJson('/api/orders', [
            'fingerling_listing_id' => $listing->id,
            'quantity' => 100,
        ]);

        $response->assertCreated()
            ->assertJsonPath('payment.status', 'pending');

        $this->assertDatabaseHas('listings', [
            'id' => $listing->id,
            'quantity' => $listing->quantity - 100,
        ]);
    }

    public function test_lgu_admin_can_approve_pending_listing(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $listing = FingerlingListing::where('approval_status', 'pending')
            ->where('municipality_id', $lguAdmin->municipality_id)
            ->firstOrFail();
        Sanctum::actingAs($lguAdmin);

        $response = $this->patchJson("/api/lgu/listings/{$listing->id}/approve");

        $response->assertOk()
            ->assertJsonPath('approval_status', 'approved');
    }

    public function test_super_admin_can_release_held_payment(): void
    {
        $payment = MockPayment::where('status', 'held')->firstOrFail();
        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());

        $response = $this->patchJson("/api/super-admin/payments/{$payment->id}/release");

        $response->assertOk()
            ->assertJsonPath('status', 'released');
    }

    public function test_ai_assistant_returns_scripted_guidance(): void
    {
        Sanctum::actingAs(User::where('role', 'buyer')->firstOrFail());

        $response = $this->postJson('/api/ai-assistant/ask', [
            'language' => 'Bisaya',
            'question' => 'Unsay maayo nga isda para sa beginner?',
        ]);

        $response->assertCreated()
            ->assertJsonFragment(['language' => 'Bisaya'])
            ->assertJsonFragment(['message' => 'Unsay maayo nga isda para sa beginner?']);
    }

    public function test_buyer_dashboard_returns_notifications_and_orders(): void
    {
        Sanctum::actingAs(User::where('role', 'buyer')->firstOrFail());

        $response = $this->getJson('/api/buyer/dashboard');

        $response->assertOk()
            ->assertJsonStructure(['active_orders', 'completed_orders', 'notifications', 'recent_orders']);
    }

    public function test_buyer_notifications_can_be_marked_read_and_disappear_from_feed(): void
    {
        $buyer = User::where('role', 'buyer')->firstOrFail();
        Sanctum::actingAs($buyer);

        $notification = AppNotification::where('user_id', $buyer->id)
            ->whereNull('read_at')
            ->firstOrFail();

        $markRead = $this->patchJson("/api/buyer/notifications/{$notification->id}/read");
        $markRead->assertOk()
            ->assertJsonPath('id', $notification->id);

        $this->assertNotNull($notification->fresh()->read_at);

        $feed = $this->getJson('/api/buyer/notifications');
        $feed->assertOk()
            ->assertJsonMissing(['id' => $notification->id]);
    }

    public function test_payment_success_and_failure_create_notifications(): void
    {
        $buyer = User::where('role', 'buyer')->firstOrFail();
        Sanctum::actingAs($buyer);
        $order = Order::where('buyer_id', $buyer->id)->firstOrFail();

        $success = $this->postJson("/api/orders/{$order->order_number}/payment-success");
        $success->assertOk()->assertJsonPath('status', 'success');
        $this->assertDatabaseHas('notifications', [
            'user_id' => $buyer->id,
            'type' => 'payment_success',
            'title' => 'Payment received',
        ]);

        $listing = FingerlingListing::firstOrFail();
        $cancelledOrder = Order::create([
            'order_number' => 'FG-CANCEL1',
            'buyer_id' => $buyer->id,
            'seller_profile_id' => $listing->seller_profile_id,
            'listing_id' => $listing->id,
            'quantity' => 1,
            'unit_price' => $listing->price_per_piece,
            'total_amount' => $listing->price_per_piece,
            'status' => 'placed',
        ]);
        MockPayment::create([
            'order_id' => $cancelledOrder->id,
            'amount' => $cancelledOrder->total_amount,
            'status' => 'pending',
            'provider' => 'paymongo',
        ]);

        $this->postJson("/api/orders/{$cancelledOrder->order_number}/payment-cancelled")->assertOk()->assertJsonPath('status', 'failed');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $buyer->id,
            'type' => 'payment_failed',
            'title' => 'Card payment declined',
        ]);
    }

    public function test_seller_dashboard_returns_listings_and_orders(): void
    {
        Sanctum::actingAs(User::where('role', 'seller')->firstOrFail());

        $response = $this->getJson('/api/seller/dashboard');

        $response->assertOk()
            ->assertJsonStructure(['seller', 'active_listings', 'pending_orders', 'listings', 'orders']);
    }

    public function test_lgu_reports_are_scoped_to_their_municipality(): void
    {
        Sanctum::actingAs(User::where('role', 'lgu_admin')->firstOrFail());

        $response = $this->getJson('/api/lgu/reports');

        $response->assertOk()
            ->assertJsonStructure(['registered_sellers', 'buyers', 'listings', 'pending_approvals']);
    }

    public function test_super_admin_reports_and_admin_lists_work(): void
    {
        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());

        $reports = $this->getJson('/api/super-admin/reports');
        $admins = $this->getJson('/api/super-admin/lgu-admins');

        $reports->assertOk()->assertJsonStructure(['total_lgus', 'total_sellers', 'total_buyers', 'total_listings', 'total_transactions', 'pending_payouts']);
        $admins->assertOk();
    }
}
