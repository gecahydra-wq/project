<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\BuyerProfile;
use App\Models\FingerlingListing;
use App\Models\Message;
use App\Models\MockPayment;
use App\Models\Municipality;
use App\Models\Order;
use App\Models\Review;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    protected function makeBuyer(array $overrides = []): User
    {
        $user = User::create(array_merge([
            'name' => 'Test Buyer',
            'email' => 'buyer-'.Str::random(10).'@example.test',
            'password' => Hash::make('password'),
            'role' => 'buyer',
            'municipality_id' => Municipality::first()->id,
            'status' => 'active',
        ], $overrides));

        BuyerProfile::create([
            'user_id' => $user->id,
            'municipality_id' => $user->municipality_id,
        ]);

        return $user;
    }

    protected function makeSeller(array $userOverrides = [], array $profileOverrides = []): SellerProfile
    {
        $municipality = Municipality::first();

        $user = User::create(array_merge([
            'name' => 'Test Hatchery',
            'email' => 'seller-'.Str::random(10).'@example.test',
            'password' => Hash::make('password'),
            'role' => 'seller',
            'municipality_id' => $municipality->id,
            'status' => 'active',
        ], $userOverrides));

        return SellerProfile::create(array_merge([
            'user_id' => $user->id,
            'municipality_id' => $user->municipality_id,
            'hatchery_name' => $user->name,
            'description' => 'Test hatchery for automated tests.',
            'verified' => true,
            'status' => 'verified',
        ], $profileOverrides));
    }

    protected function makeListing(SellerProfile $seller, array $overrides = []): FingerlingListing
    {
        return FingerlingListing::create(array_merge([
            'seller_profile_id' => $seller->id,
            'municipality_id' => $seller->municipality_id,
            'species' => 'Bangus',
            'title' => 'Bangus Fingerlings',
            'quantity' => 5000,
            'price_per_piece' => 3.50,
            'average_size' => '4-5 inches',
            'availability_status' => 'in_stock',
            'approval_status' => 'approved',
        ], $overrides));
    }

    protected function makeOrder(User $buyer, FingerlingListing $listing, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'FG-'.strtoupper(Str::random(6)),
            'buyer_id' => $buyer->id,
            'seller_profile_id' => $listing->seller_profile_id,
            'listing_id' => $listing->id,
            'quantity' => 100,
            'unit_price' => $listing->price_per_piece,
            'total_amount' => 100 * $listing->price_per_piece,
            'status' => 'placed',
        ], $overrides));
    }

    protected function makePayment(Order $order, array $overrides = []): MockPayment
    {
        return MockPayment::create(array_merge([
            'order_id' => $order->id,
            'amount' => $order->total_amount,
            'status' => 'pending',
            'provider' => 'paymongo',
        ], $overrides));
    }

    public function test_fresh_seed_contains_only_the_two_administrator_accounts(): void
    {
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('buyer_profiles', 0);
        $this->assertDatabaseCount('seller_profiles', 0);

        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $this->assertSame('lgu@gmail.com', $lguAdmin->email);
        $this->assertSame('active', $lguAdmin->status);

        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        $this->assertSame('superadmin@gmail.com', $superAdmin->email);
        $this->assertSame('active', $superAdmin->status);
    }

    public function test_fresh_seed_contains_no_marketplace_listings(): void
    {
        $this->assertDatabaseCount('listings', 0);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('reviews', 0);
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('notifications', 0);

        $this->getJson('/api/listings')->assertOk()->assertJsonCount(0);
    }

    public function test_buyers_can_filter_listings_by_species(): void
    {
        $seller = $this->makeSeller();
        $this->makeListing($seller, ['species' => 'Bangus']);
        $this->makeListing($seller, ['species' => 'Tilapia', 'title' => 'Tilapia Fingerlings']);

        $response = $this->getJson('/api/listings?species=Bangus');

        $response->assertOk()
            ->assertJsonFragment(['species' => 'Bangus'])
            ->assertJsonMissing(['species' => 'Tilapia']);
    }

    public function test_public_listing_index_includes_seller_name_for_marketplace_cards(): void
    {
        $seller = $this->makeSeller(['name' => 'Juan Dela Cruz']);
        $this->makeListing($seller);

        $response = $this->getJson('/api/listings');

        $response->assertOk()->assertJsonPath('0.sellerProfile.user.name', 'Juan Dela Cruz');
    }

    public function test_order_creation_holds_mock_payment_and_reduces_inventory(): void
    {
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller);
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

    public function test_order_creation_rejects_out_of_stock_listing_even_if_the_frontend_guard_is_bypassed(): void
    {
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller, ['quantity' => 0]);
        Sanctum::actingAs($buyer);

        $response = $this->postJson('/api/orders', [
            'fingerling_listing_id' => $listing->id,
            'quantity' => 1,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Requested quantity exceeds available stock.');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('listings', ['id' => $listing->id, 'quantity' => 0]);
    }

    public function test_order_creation_rejects_quantity_exceeding_remaining_stock(): void
    {
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller, ['quantity' => 5]);
        Sanctum::actingAs($buyer);

        $this->postJson('/api/orders', [
            'fingerling_listing_id' => $listing->id,
            'quantity' => 6,
        ])->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_lgu_admin_can_approve_pending_listing(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $seller = $this->makeSeller(
            ['municipality_id' => $lguAdmin->municipality_id],
            ['municipality_id' => $lguAdmin->municipality_id]
        );
        $listing = $this->makeListing($seller, ['approval_status' => 'pending']);
        Sanctum::actingAs($lguAdmin);

        $response = $this->patchJson("/api/lgu/listings/{$listing->id}/approve");

        $response->assertOk()
            ->assertJsonPath('approval_status', 'approved');
    }

    public function test_lgu_admin_can_view_full_listing_detail_for_review(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $seller = $this->makeSeller(
            ['name' => 'Juan Dela Cruz', 'municipality_id' => $lguAdmin->municipality_id],
            ['municipality_id' => $lguAdmin->municipality_id]
        );
        $listing = $this->makeListing($seller, ['approval_status' => 'pending']);
        Sanctum::actingAs($lguAdmin);

        $response = $this->getJson("/api/lgu/listings/{$listing->id}");

        $response->assertOk()
            ->assertJsonPath('approval_status', 'pending')
            ->assertJsonPath('sellerProfile.hatchery_name', $seller->hatchery_name)
            ->assertJsonPath('sellerProfile.user.name', 'Juan Dela Cruz')
            ->assertJsonStructure(['media', 'municipality']);
    }

    public function test_lgu_admin_cannot_view_listing_detail_outside_their_municipality(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $otherMunicipality = Municipality::where('id', '!=', $lguAdmin->municipality_id)->firstOrFail();
        $seller = $this->makeSeller([], ['municipality_id' => $otherMunicipality->id]);
        $listing = $this->makeListing($seller, ['approval_status' => 'pending']);
        Sanctum::actingAs($lguAdmin);

        $this->getJson("/api/lgu/listings/{$listing->id}")->assertStatus(403);
    }

    public function test_lgu_admin_can_reject_a_listing_with_an_optional_reason(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $seller = $this->makeSeller(
            ['municipality_id' => $lguAdmin->municipality_id],
            ['municipality_id' => $lguAdmin->municipality_id]
        );
        $listing = $this->makeListing($seller, ['approval_status' => 'pending']);
        Sanctum::actingAs($lguAdmin);

        $response = $this->patchJson("/api/lgu/listings/{$listing->id}/reject", [
            'reason' => 'Photos do not clearly show the fingerlings.',
        ]);

        $response->assertOk()
            ->assertJsonPath('approval_status', 'rejected')
            ->assertJsonPath('rejection_reason', 'Photos do not clearly show the fingerlings.');

        $this->assertDatabaseHas('listings', [
            'id' => $listing->id,
            'rejection_reason' => 'Photos do not clearly show the fingerlings.',
        ]);
    }

    public function test_lgu_admin_can_reject_a_listing_without_a_reason(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $seller = $this->makeSeller(
            ['municipality_id' => $lguAdmin->municipality_id],
            ['municipality_id' => $lguAdmin->municipality_id]
        );
        $listing = $this->makeListing($seller, ['approval_status' => 'pending']);
        Sanctum::actingAs($lguAdmin);

        $response = $this->patchJson("/api/lgu/listings/{$listing->id}/reject");

        $response->assertOk()
            ->assertJsonPath('approval_status', 'rejected')
            ->assertJsonPath('rejection_reason', null);
    }

    public function test_approving_a_listing_clears_any_previous_rejection_reason(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $seller = $this->makeSeller(
            ['municipality_id' => $lguAdmin->municipality_id],
            ['municipality_id' => $lguAdmin->municipality_id]
        );
        $listing = $this->makeListing($seller, ['approval_status' => 'rejected', 'rejection_reason' => 'Needs clearer photos.']);
        Sanctum::actingAs($lguAdmin);

        $response = $this->patchJson("/api/lgu/listings/{$listing->id}/approve");

        $response->assertOk()->assertJsonPath('approval_status', 'approved');
        $this->assertDatabaseHas('listings', ['id' => $listing->id, 'rejection_reason' => null]);
    }

    public function test_super_admin_can_no_longer_release_payments_directly(): void
    {
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller);
        $order = $this->makeOrder($buyer, $listing);
        $payment = $this->makePayment($order, ['status' => 'paid_held']);

        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());

        $this->patchJson("/api/super-admin/payments/{$payment->id}/release")->assertStatus(404);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'paid_held']);
    }

    public function test_lgu_admin_can_approve_earnings_for_completed_orders_in_their_municipality(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $buyer = $this->makeBuyer(['name' => 'Nina Buyer']);
        $seller = $this->makeSeller([], ['municipality_id' => $lguAdmin->municipality_id]);
        $listing = $this->makeListing($seller);
        $order = $this->makeOrder($buyer, $listing, ['status' => 'completed', 'order_number' => 'FG-NOTIFY1']);
        $payment = $this->makePayment($order, ['status' => 'paid_held', 'amount' => $order->total_amount]);

        Sanctum::actingAs($lguAdmin);
        $response = $this->patchJson("/api/lgu/payments/{$payment->id}/approve");

        $response->assertOk()->assertJsonPath('status', 'released');

        $notification = AppNotification::where('user_id', $seller->user_id)->where('type', 'earnings_approved')->firstOrFail();
        $this->assertStringContainsString('FG-NOTIFY1', $notification->body);
    }

    public function test_marking_an_order_completed_notifies_the_municipalitys_lgu_admin_of_pending_earnings(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $sellerProfile = $this->makeSeller(
            ['name' => 'Juan Dela Cruz'],
            ['hatchery_name' => "Juan's Hatchery", 'municipality_id' => $lguAdmin->municipality_id]
        );
        $seller = $sellerProfile->user;
        $buyer = $this->makeBuyer(['name' => 'Nina Buyer']);
        $listing = $this->makeListing($sellerProfile, ['species' => 'Bangus']);
        $order = $this->makeOrder($buyer, $listing, ['order_number' => 'FG-DELIVERED1']);
        $this->makePayment($order, ['status' => 'paid_held']);

        Sanctum::actingAs($seller);
        $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'completed'])->assertOk();

        $payment = MockPayment::where('order_id', $order->id)->firstOrFail();
        $notification = AppNotification::where('user_id', $lguAdmin->id)
            ->where('type', "earnings_pending_approval:{$payment->id}")
            ->firstOrFail();

        $this->assertStringContainsString('Juan Dela Cruz', $notification->body);
        $this->assertStringContainsString("Juan's Hatchery", $notification->body);
        $this->assertStringContainsString('Bangus', $notification->body);
        $this->assertStringContainsString('Nina Buyer', $notification->body);
        $this->assertStringContainsString('FG-DELIVERED1', $notification->body);
        $this->assertNull($notification->read_at);

        // Idempotent: re-marking completed must not create a second notification.
        $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'completed'])->assertOk();
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_lgu_dashboard_includes_the_pending_earnings_notification(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $sellerProfile = $this->makeSeller([], ['municipality_id' => $lguAdmin->municipality_id]);
        $seller = $sellerProfile->user;
        $buyer = $this->makeBuyer();
        $listing = $this->makeListing($sellerProfile);
        $order = $this->makeOrder($buyer, $listing);
        $this->makePayment($order, ['status' => 'paid_held']);

        Sanctum::actingAs($seller);
        $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'completed'])->assertOk();

        Sanctum::actingAs($lguAdmin);
        $dashboard = $this->getJson('/api/lgu/dashboard')->assertOk();
        $this->assertCount(1, $dashboard->json('notifications'));
        $this->assertSame('Seller earnings await your approval', $dashboard->json('notifications.0.title'));
    }

    public function test_approving_earnings_marks_the_pending_approval_notification_as_read(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $sellerProfile = $this->makeSeller([], ['municipality_id' => $lguAdmin->municipality_id]);
        $seller = $sellerProfile->user;
        $buyer = $this->makeBuyer();
        $listing = $this->makeListing($sellerProfile);
        $order = $this->makeOrder($buyer, $listing);
        $this->makePayment($order, ['status' => 'paid_held']);

        Sanctum::actingAs($seller);
        $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'completed'])->assertOk();

        $payment = MockPayment::where('order_id', $order->id)->firstOrFail();
        $notification = AppNotification::where('type', "earnings_pending_approval:{$payment->id}")->firstOrFail();
        $this->assertNull($notification->read_at);

        Sanctum::actingAs($lguAdmin);
        $this->patchJson("/api/lgu/payments/{$payment->id}/approve")->assertOk();

        $this->assertNotNull($notification->fresh()->read_at);
        $dashboard = $this->getJson('/api/lgu/dashboard')->assertOk();
        $this->assertCount(0, $dashboard->json('notifications'));
    }

    public function test_lgu_admin_can_mark_their_own_notification_read(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $notification = AppNotification::create([
            'user_id' => $lguAdmin->id,
            'type' => 'earnings_pending_approval:999',
            'title' => 'Seller earnings await your approval',
            'body' => 'Test notification body.',
        ]);

        Sanctum::actingAs($lguAdmin);
        $this->patchJson("/api/lgu/notifications/{$notification->id}/read")->assertOk();

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_lgu_admin_cannot_mark_another_admins_notification_read(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $municipalities = Municipality::all();
        $otherMunicipality = $municipalities->firstWhere('id', '!=', $lguAdmin->municipality_id);
        $otherLguAdmin = User::create([
            'name' => 'Other LGU Admin',
            'email' => 'other-lgu@example.test',
            'password' => Hash::make('password'),
            'role' => 'lgu_admin',
            'municipality_id' => $otherMunicipality->id,
            'status' => 'active',
        ]);
        $notification = AppNotification::create([
            'user_id' => $otherLguAdmin->id,
            'type' => 'earnings_pending_approval:999',
            'title' => 'Seller earnings await your approval',
            'body' => 'Test notification body.',
        ]);

        Sanctum::actingAs($lguAdmin);
        $this->patchJson("/api/lgu/notifications/{$notification->id}/read")->assertStatus(403);
        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_lgu_admin_cannot_approve_earnings_for_sellers_outside_their_municipality(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $otherMunicipality = Municipality::where('id', '!=', $lguAdmin->municipality_id)->firstOrFail();
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller([], ['municipality_id' => $otherMunicipality->id]);
        $listing = $this->makeListing($seller);
        $order = $this->makeOrder($buyer, $listing, ['status' => 'completed']);
        $payment = $this->makePayment($order, ['status' => 'paid_held']);

        Sanctum::actingAs($lguAdmin);
        $this->patchJson("/api/lgu/payments/{$payment->id}/approve")->assertStatus(403);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'paid_held']);
    }

    public function test_lgu_admin_cannot_approve_earnings_for_orders_not_yet_delivered(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller([], ['municipality_id' => $lguAdmin->municipality_id]);
        $listing = $this->makeListing($seller);
        $order = $this->makeOrder($buyer, $listing, ['status' => 'in_transit']);
        $payment = $this->makePayment($order, ['status' => 'paid_held']);

        Sanctum::actingAs($lguAdmin);
        $this->patchJson("/api/lgu/payments/{$payment->id}/approve")->assertStatus(422);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'paid_held']);
    }

    public function test_super_admin_dashboard_transactions_include_seller_profile_details(): void
    {
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller(['name' => 'Maria Santos'], ['hatchery_name' => "Maria's Hatchery"]);
        $listing = $this->makeListing($seller);
        $this->makeOrder($buyer, $listing);

        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());

        $response = $this->getJson('/api/super-admin/dashboard');

        $response->assertOk()
            ->assertJsonPath('transactions.0.sellerProfile.hatchery_name', "Maria's Hatchery")
            ->assertJsonPath('transactions.0.sellerProfile.user.name', 'Maria Santos');
    }

    public function test_super_admin_dashboard_no_longer_exposes_earnings_approval_fields(): void
    {
        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());

        $response = $this->getJson('/api/super-admin/dashboard');

        $response->assertOk();
        $this->assertArrayNotHasKey('held_in_escrow', $response->json());
        $this->assertArrayNotHasKey('pending_payouts', $response->json());
    }

    public function test_seller_wallet_reports_available_pending_and_total_earnings(): void
    {
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller);

        // LGU-approved: money is available for withdrawal.
        $releasedOrder = $this->makeOrder($buyer, $listing, ['status' => 'completed']);
        $this->makePayment($releasedOrder, ['status' => 'released', 'amount' => 100]);

        // Delivered but not yet LGU-approved: still pending.
        $deliveredOrder = $this->makeOrder($buyer, $listing, ['status' => 'completed']);
        $this->makePayment($deliveredOrder, ['status' => 'paid_held', 'amount' => 50]);

        // Buyer already paid but the order hasn't been delivered yet: earnings
        // must still be recognized in Pending Balance (Step 1 of the corrected
        // workflow) even though delivery (Step 2) hasn't happened.
        $inTransitOrder = $this->makeOrder($buyer, $listing, ['status' => 'in_transit']);
        $this->makePayment($inTransitOrder, ['status' => 'paid_held', 'amount' => 999]);

        // Not yet actually paid by the buyer (still at checkout): must not count anywhere.
        $unpaidOrder = $this->makeOrder($buyer, $listing, ['status' => 'placed']);
        $this->makePayment($unpaidOrder, ['status' => 'pending', 'amount' => 12345]);

        Sanctum::actingAs($seller->user);
        $response = $this->getJson('/api/seller/wallet');

        $response->assertOk()
            ->assertJsonPath('available_balance', 100)
            ->assertJsonPath('pending_balance', 1049)
            ->assertJsonPath('processing_amount', 0)
            ->assertJsonPath('total_earnings', 1149)
            ->assertJsonPath('withdrawn_amount', 0);
    }

    public function test_seller_can_submit_withdrawal_request_within_available_balance(): void
    {
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller);
        $order = $this->makeOrder($buyer, $listing, ['status' => 'completed']);
        $this->makePayment($order, ['status' => 'released', 'amount' => 200]);

        Sanctum::actingAs($seller->user);

        $response = $this->postJson('/api/seller/withdrawals', [
            'method' => 'gcash',
            'account_name' => 'Test Seller',
            'account_number' => '09171234567',
            'amount' => 150,
        ]);

        $response->assertCreated()->assertJsonPath('status', 'pending');
        $this->assertDatabaseHas('withdrawal_requests', [
            'seller_profile_id' => $seller->id,
            'amount' => 150,
            'status' => 'pending',
        ]);

        $wallet = $this->getJson('/api/seller/wallet');
        $wallet->assertOk()->assertJsonPath('available_balance', 50);
    }

    public function test_seller_cannot_submit_withdrawal_request_exceeding_available_balance(): void
    {
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller);
        $order = $this->makeOrder($buyer, $listing, ['status' => 'completed']);
        $this->makePayment($order, ['status' => 'released', 'amount' => 100]);

        Sanctum::actingAs($seller->user);

        $this->postJson('/api/seller/withdrawals', [
            'method' => 'maya',
            'account_name' => 'Test Seller',
            'account_number' => '09171234567',
            'amount' => 500,
        ])->assertStatus(422);
    }

    public function test_super_admin_can_approve_and_reject_withdrawal_requests(): void
    {
        $seller = $this->makeSeller();
        $withdrawal = WithdrawalRequest::create([
            'seller_profile_id' => $seller->id,
            'method' => 'bank_transfer',
            'account_name' => 'Test Seller',
            'account_number' => '0011223344',
            'amount' => 75,
            'status' => 'pending',
        ]);
        $otherWithdrawal = WithdrawalRequest::create([
            'seller_profile_id' => $seller->id,
            'method' => 'gcash',
            'account_name' => 'Test Seller',
            'account_number' => '09171234567',
            'amount' => 25,
            'status' => 'pending',
        ]);

        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());

        $this->getJson('/api/super-admin/withdrawals')->assertOk()->assertJsonCount(2);

        $this->patchJson("/api/super-admin/withdrawals/{$withdrawal->id}/approve")
            ->assertOk()->assertJsonPath('status', 'approved');
        $this->patchJson("/api/super-admin/withdrawals/{$otherWithdrawal->id}/reject", ['reason' => 'Account details could not be verified.'])
            ->assertOk()
            ->assertJsonPath('status', 'rejected')
            ->assertJsonPath('rejection_reason', 'Account details could not be verified.');

        $this->assertDatabaseHas('notifications', ['user_id' => $seller->user_id, 'type' => 'withdrawal_approved']);
        $rejectedNotification = AppNotification::where('user_id', $seller->user_id)->where('type', 'withdrawal_rejected')->firstOrFail();
        $this->assertStringContainsString('Account details could not be verified.', $rejectedNotification->body);
    }

    public function test_rejecting_a_withdrawal_request_without_a_reason_is_rejected(): void
    {
        $seller = $this->makeSeller();
        $withdrawal = WithdrawalRequest::create([
            'seller_profile_id' => $seller->id,
            'method' => 'gcash',
            'account_name' => 'Test Seller',
            'account_number' => '09171234567',
            'amount' => 25,
            'status' => 'pending',
        ]);

        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());

        $this->patchJson("/api/super-admin/withdrawals/{$withdrawal->id}/reject")->assertStatus(422);
        $this->assertDatabaseHas('withdrawal_requests', ['id' => $withdrawal->id, 'status' => 'pending']);
    }

    public function test_super_admin_can_mark_an_approved_withdrawal_as_paid_and_seller_sees_the_update(): void
    {
        $seller = $this->makeSeller();
        $withdrawal = WithdrawalRequest::create([
            'seller_profile_id' => $seller->id,
            'method' => 'maya',
            'account_name' => 'Test Seller',
            'account_number' => '09179998888',
            'amount' => 40,
            'status' => 'pending',
        ]);

        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());

        // Cannot mark as paid before it has been approved.
        $this->patchJson("/api/super-admin/withdrawals/{$withdrawal->id}/paid")->assertStatus(422);

        $this->patchJson("/api/super-admin/withdrawals/{$withdrawal->id}/approve")->assertOk();
        $paid = $this->patchJson("/api/super-admin/withdrawals/{$withdrawal->id}/paid");

        $paid->assertOk()->assertJsonPath('status', 'paid');
        $this->assertNotNull($paid->json('paid_at'));

        $this->assertDatabaseHas('notifications', ['user_id' => $seller->user_id, 'type' => 'withdrawal_paid']);

        Sanctum::actingAs($seller->user);
        $wallet = $this->getJson('/api/seller/wallet');
        $wallet->assertOk()
            ->assertJsonPath('withdrawal_requests.0.status', 'paid')
            ->assertJsonPath('withdrawal_requests.0.id', $withdrawal->id);
        $this->assertNotNull($wallet->json('withdrawal_requests.0.paid_at'));
    }

    public function test_marking_a_withdrawal_paid_does_not_return_the_amount_to_available_balance(): void
    {
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller);
        $order = $this->makeOrder($buyer, $listing, ['status' => 'completed']);
        $this->makePayment($order, ['status' => 'released', 'amount' => 200]);

        Sanctum::actingAs($seller->user);
        $this->postJson('/api/seller/withdrawals', [
            'method' => 'gcash',
            'account_name' => 'Test Seller',
            'account_number' => '09171234567',
            'amount' => 150,
        ])->assertCreated();
        $withdrawal = WithdrawalRequest::firstOrFail();

        $before = $this->getJson('/api/seller/wallet');
        $before->assertOk()
            ->assertJsonPath('available_balance', 50)
            ->assertJsonPath('withdrawn_amount', 0);

        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
        $this->patchJson("/api/super-admin/withdrawals/{$withdrawal->id}/approve")->assertOk();
        $this->patchJson("/api/super-admin/withdrawals/{$withdrawal->id}/paid")->assertOk();

        Sanctum::actingAs($seller->user);
        $after = $this->getJson('/api/seller/wallet');
        $after->assertOk()
            ->assertJsonPath('available_balance', 50) // must NOT bounce back to 200
            ->assertJsonPath('withdrawn_amount', 150);
    }

    /**
     * End-to-end audit of the full seller wallet lifecycle: order -> payment
     * capture -> delivery -> LGU earnings approval -> withdrawal request ->
     * super admin approval -> paid. At every single step, Total Earnings must
     * reconcile exactly against Available + Pending + Processing + Withdrawn
     * -- including the "requested/approved but not yet paid" window, which is
     * precisely where the wallet previously lost track of reserved money.
     */
    public function test_full_wallet_lifecycle_keeps_every_balance_internally_consistent(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller([], ['municipality_id' => $lguAdmin->municipality_id]);
        $listing = $this->makeListing($seller, ['price_per_piece' => 1, 'quantity' => 1000]);

        $assertReconciles = function (array $wallet) {
            $this->assertEquals(
                $wallet['total_earnings'],
                round($wallet['available_balance'] + $wallet['pending_balance'] + $wallet['processing_amount'] + $wallet['withdrawn_amount'], 2),
                'Total Earnings must equal Available + Pending + Processing + Withdrawn.'
            );
        };

        // 1. Buyer places an order (100 pcs @ ₱1 = ₱100) and 2. payment succeeds.
        Sanctum::actingAs($buyer);
        $order = $this->postJson('/api/orders', ['fingerling_listing_id' => $listing->id, 'quantity' => 100])->assertCreated()->json();
        $this->postJson("/api/orders/{$order['order_number']}/payment-success")->assertOk();

        // 3. Earnings sit in Pending Balance, untouched by anything else yet.
        Sanctum::actingAs($seller->user);
        $wallet = $this->getJson('/api/seller/wallet')->assertOk()->json();
        $this->assertEquals(100, $wallet['pending_balance']);
        $this->assertEquals(0, $wallet['available_balance']);
        $this->assertEquals(0, $wallet['processing_amount']);
        $this->assertEquals(0, $wallet['withdrawn_amount']);
        $this->assertEquals(100, $wallet['total_earnings']);
        $assertReconciles($wallet);

        // 4-5. Seller ships, buyer's delivery is confirmed (order marked completed).
        $this->patchJson("/api/orders/{$order['id']}/status", ['status' => 'confirmed'])->assertOk();
        $this->patchJson("/api/orders/{$order['id']}/status", ['status' => 'in_transit'])->assertOk();
        $this->patchJson("/api/orders/{$order['id']}/status", ['status' => 'completed'])->assertOk();

        // 6-7. Until LGU approves, Pending must still hold the money and Available must stay at 0.
        $wallet = $this->getJson('/api/seller/wallet')->assertOk()->json();
        $this->assertEquals(100, $wallet['pending_balance']);
        $this->assertEquals(0, $wallet['available_balance']);
        $assertReconciles($wallet);

        $payment = MockPayment::whereHas('order', fn ($q) => $q->where('id', $order['id']))->firstOrFail();
        Sanctum::actingAs($lguAdmin);
        $this->patchJson("/api/lgu/payments/{$payment->id}/approve")->assertOk();

        // 8. Pending -> Available. Nothing lost, nothing duplicated.
        Sanctum::actingAs($seller->user);
        $wallet = $this->getJson('/api/seller/wallet')->assertOk()->json();
        $this->assertEquals(0, $wallet['pending_balance']);
        $this->assertEquals(100, $wallet['available_balance']);
        $this->assertEquals(100, $wallet['total_earnings']);
        $assertReconciles($wallet);

        // 9. Seller requests a partial payout.
        $this->postJson('/api/seller/withdrawals', [
            'method' => 'gcash', 'account_name' => 'Seller', 'account_number' => '0900000000', 'amount' => 60,
        ])->assertCreated();
        $withdrawal = WithdrawalRequest::where('seller_profile_id', $seller->id)->firstOrFail();

        // While requested-but-unpaid, the ₱60 must show as Processing, NOT vanish from the total.
        $wallet = $this->getJson('/api/seller/wallet')->assertOk()->json();
        $this->assertEquals(40, $wallet['available_balance']);
        $this->assertEquals(60, $wallet['processing_amount']);
        $this->assertEquals(100, $wallet['total_earnings']);
        $assertReconciles($wallet);

        // Same must hold once the Super Admin approves it but hasn't paid it yet.
        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
        $this->patchJson("/api/super-admin/withdrawals/{$withdrawal->id}/approve")->assertOk();
        Sanctum::actingAs($seller->user);
        $wallet = $this->getJson('/api/seller/wallet')->assertOk()->json();
        $this->assertEquals(40, $wallet['available_balance']);
        $this->assertEquals(60, $wallet['processing_amount']);
        $this->assertEquals(0, $wallet['withdrawn_amount']);
        $assertReconciles($wallet);

        // 10. Super Admin marks it paid: Processing -> Withdrawn. Available must NOT change again.
        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
        $this->patchJson("/api/super-admin/withdrawals/{$withdrawal->id}/paid")->assertOk();
        Sanctum::actingAs($seller->user);
        $wallet = $this->getJson('/api/seller/wallet')->assertOk()->json();
        $this->assertEquals(40, $wallet['available_balance']);
        $this->assertEquals(0, $wallet['processing_amount']);
        $this->assertEquals(60, $wallet['withdrawn_amount']);
        $this->assertEquals(100, $wallet['total_earnings']);
        $assertReconciles($wallet);
    }

    /**
     * Regression test for a support report where a seller expected a fresh
     * ₱120 order to raise Available Balance by exactly ₱120 after LGU
     * approval. For a seller with NO prior payment/withdrawal history, the
     * full gross order amount must land in Available Balance with zero
     * deductions -- there is no platform commission, PayMongo fee, service
     * fee, or tax applied anywhere in this codebase. (Sellers who already
     * have prior released earnings and prior withdrawals will instead see
     * their existing running balance net against the new order -- that is
     * the correct behavior of a cumulative wallet, not a bug; see
     * test_full_wallet_lifecycle_keeps_every_balance_internally_consistent
     * for that reconciliation.)
     */
    public function test_lgu_approved_earnings_credit_the_full_gross_order_amount_with_no_deductions(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller([], ['municipality_id' => $lguAdmin->municipality_id]);
        $listing = $this->makeListing($seller, ['price_per_piece' => 60, 'quantity' => 1000]);

        Sanctum::actingAs($buyer);
        $order = $this->postJson('/api/orders', ['fingerling_listing_id' => $listing->id, 'quantity' => 2])->assertCreated()->json();
        $this->assertEquals(120, $order['total_amount'], 'Gross order amount must be quantity * unit_price with no deduction.');
        $this->postJson("/api/orders/{$order['order_number']}/payment-success")->assertOk();

        Sanctum::actingAs($seller->user);
        $wallet = $this->getJson('/api/seller/wallet')->assertOk()->json();
        $this->assertEquals(120, $wallet['pending_balance']);
        $this->assertEquals(0, $wallet['available_balance']);

        $this->patchJson("/api/orders/{$order['id']}/status", ['status' => 'completed'])->assertOk();

        $payment = MockPayment::whereHas('order', fn ($q) => $q->where('id', $order['id']))->firstOrFail();
        $this->assertEquals(120, $payment->amount, 'The captured payment amount must equal the gross order total with no deduction.');

        Sanctum::actingAs($lguAdmin);
        $this->patchJson("/api/lgu/payments/{$payment->id}/approve")->assertOk();

        Sanctum::actingAs($seller->user);
        $wallet = $this->getJson('/api/seller/wallet')->assertOk()->json();
        $this->assertEquals(0, $wallet['pending_balance']);
        $this->assertEquals(120, $wallet['available_balance'], 'Available Balance must equal the full ₱120 gross amount with zero deductions.');
        $this->assertEquals(120, $wallet['total_earnings']);
    }

    public function test_finalized_withdrawal_requests_cannot_be_re_approved_or_re_rejected(): void
    {
        $seller = $this->makeSeller();
        $withdrawal = WithdrawalRequest::create([
            'seller_profile_id' => $seller->id,
            'method' => 'gcash',
            'account_name' => 'Test Seller',
            'account_number' => '09171234567',
            'amount' => 25,
            'status' => 'rejected',
            'rejection_reason' => 'Already handled.',
        ]);

        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());

        $this->patchJson("/api/super-admin/withdrawals/{$withdrawal->id}/approve")->assertStatus(422);
        $this->patchJson("/api/super-admin/withdrawals/{$withdrawal->id}/reject", ['reason' => 'again'])->assertStatus(422);
    }

    public function test_seller_can_view_and_mark_notifications_read(): void
    {
        $seller = $this->makeSeller();
        AppNotification::create([
            'user_id' => $seller->user_id,
            'type' => 'payment_released',
            'title' => 'Payment Released',
            'body' => 'Test notification body.',
        ]);

        Sanctum::actingAs($seller->user);

        $list = $this->getJson('/api/seller/notifications');
        $list->assertOk()->assertJsonCount(1);
        $notificationId = $list->json('0.id');

        $this->getJson('/api/seller/dashboard')->assertJsonCount(1, 'notifications');

        $this->patchJson("/api/seller/notifications/{$notificationId}/read")->assertOk();
        $this->getJson('/api/seller/notifications')->assertJsonCount(0);
    }

    public function test_ai_assistant_returns_scripted_guidance(): void
    {
        Sanctum::actingAs($this->makeBuyer());

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
        Sanctum::actingAs($this->makeBuyer());

        $response = $this->getJson('/api/buyer/dashboard');

        $response->assertOk()
            ->assertJsonStructure(['active_orders', 'completed_orders', 'notifications', 'recent_orders']);
    }

    public function test_buyer_dashboard_recent_orders_include_seller_profile_details(): void
    {
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller(['name' => 'Juan Dela Cruz'], ['hatchery_name' => 'Juan\'s Hatchery']);
        $listing = $this->makeListing($seller);
        $this->makeOrder($buyer, $listing);
        Sanctum::actingAs($buyer);

        $response = $this->getJson('/api/buyer/dashboard');

        $response->assertOk()
            ->assertJsonPath('recent_orders.0.sellerProfile.hatchery_name', "Juan's Hatchery")
            ->assertJsonPath('recent_orders.0.sellerProfile.user.name', 'Juan Dela Cruz');
    }

    public function test_buyer_notifications_can_be_marked_read_and_disappear_from_feed(): void
    {
        $buyer = $this->makeBuyer();
        $notification = AppNotification::create([
            'user_id' => $buyer->id,
            'type' => 'order_created',
            'title' => 'Order placed',
            'body' => 'Your order is now being reviewed by the seller.',
        ]);
        Sanctum::actingAs($buyer);

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
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller);
        $order = $this->makeOrder($buyer, $listing);
        $this->makePayment($order);
        Sanctum::actingAs($buyer);

        $success = $this->postJson("/api/orders/{$order->order_number}/payment-success");
        $success->assertOk()->assertJsonPath('status', 'success');
        $this->assertDatabaseHas('notifications', [
            'user_id' => $buyer->id,
            'type' => 'payment_success',
            'title' => 'Payment received',
        ]);

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

    public function test_buyer_and_seller_can_exchange_messages_and_mark_thread_read(): void
    {
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller()->user;

        Sanctum::actingAs($buyer);
        $send = $this->postJson('/api/messages', [
            'receiver_id' => $seller->id,
            'body' => 'Available pa ang bangus fingerlings?',
        ]);
        $send->assertCreated()->assertJsonPath('body', 'Available pa ang bangus fingerlings?');

        $buyerThreads = $this->getJson('/api/messages/threads');
        $buyerThreads->assertOk()->assertJsonFragment(['id' => $seller->id]);

        Sanctum::actingAs($seller);
        $unreadBefore = \App\Models\Message::where('receiver_id', $seller->id)->where('sender_id', $buyer->id)->whereNull('read_at')->count();
        $this->assertGreaterThanOrEqual(1, $unreadBefore);

        $thread = $this->getJson("/api/messages/thread/{$buyer->id}");
        $thread->assertOk();
        $this->assertGreaterThanOrEqual(1, count($thread->json('messages')));

        $this->patchJson("/api/messages/thread/{$buyer->id}/read")->assertOk();

        $unreadAfter = \App\Models\Message::where('receiver_id', $seller->id)->where('sender_id', $buyer->id)->whereNull('read_at')->count();
        $this->assertEquals(0, $unreadAfter);
    }

    public function test_messaging_is_rejected_between_non_buyer_seller_pairs(): void
    {
        $buyer = $this->makeBuyer();
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();

        Sanctum::actingAs($buyer);

        $this->postJson('/api/messages', [
            'receiver_id' => $lguAdmin->id,
            'body' => 'Hello?',
        ])->assertStatus(422);
    }

    public function test_sender_can_edit_their_own_message_within_the_time_window(): void
    {
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller()->user;

        Sanctum::actingAs($buyer);
        $sent = $this->postJson('/api/messages', ['receiver_id' => $seller->id, 'body' => 'Original message'])->assertCreated();
        $messageId = $sent->json('id');

        $edit = $this->patchJson("/api/messages/{$messageId}", ['body' => 'Edited message']);
        $edit->assertOk()
            ->assertJsonPath('body', 'Edited message');

        $this->assertNotNull($edit->json('edited_at'));
        $this->assertDatabaseHas('messages', ['id' => $messageId, 'body' => 'Edited message']);
    }

    public function test_recipient_cannot_edit_another_users_message(): void
    {
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller()->user;

        Sanctum::actingAs($buyer);
        $sent = $this->postJson('/api/messages', ['receiver_id' => $seller->id, 'body' => 'Original message'])->assertCreated();

        Sanctum::actingAs($seller);
        $this->patchJson("/api/messages/{$sent->json('id')}", ['body' => 'Hijacked'])->assertStatus(403);
        $this->assertDatabaseHas('messages', ['id' => $sent->json('id'), 'body' => 'Original message']);
    }

    public function test_message_cannot_be_edited_after_the_edit_window_expires(): void
    {
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller()->user;
        $message = \App\Models\Message::create([
            'sender_id' => $buyer->id,
            'receiver_id' => $seller->id,
            'body' => 'Old message',
        ]);
        // created_at/updated_at are not mass-assignable, so backdate them directly.
        $message->forceFill(['created_at' => now()->subMinutes(20), 'updated_at' => now()->subMinutes(20)])->save();

        Sanctum::actingAs($buyer);
        $this->patchJson("/api/messages/{$message->id}", ['body' => 'Too late'])->assertStatus(422);
        $this->assertDatabaseHas('messages', ['id' => $message->id, 'body' => 'Old message']);
    }

    public function test_sender_can_delete_their_own_message_leaving_a_placeholder(): void
    {
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller()->user;

        Sanctum::actingAs($buyer);
        $sent = $this->postJson('/api/messages', ['receiver_id' => $seller->id, 'body' => 'Delete me'])->assertCreated();
        $messageId = $sent->json('id');

        $delete = $this->deleteJson("/api/messages/{$messageId}");
        $delete->assertOk()
            ->assertJsonPath('body', 'This message was deleted.');

        $this->assertNotNull($delete->json('deleted_at'));
        $this->assertDatabaseHas('messages', ['id' => $messageId, 'body' => 'This message was deleted.']);

        // The message still appears in the thread (conversation order preserved), not removed.
        $thread = $this->getJson("/api/messages/thread/{$seller->id}");
        $thread->assertOk();
        $this->assertTrue(collect($thread->json('messages'))->contains('id', $messageId));
    }

    public function test_recipient_cannot_delete_another_users_message(): void
    {
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller()->user;

        Sanctum::actingAs($buyer);
        $sent = $this->postJson('/api/messages', ['receiver_id' => $seller->id, 'body' => 'Not yours'])->assertCreated();

        Sanctum::actingAs($seller);
        $this->deleteJson("/api/messages/{$sent->json('id')}")->assertStatus(403);
        $this->assertDatabaseHas('messages', ['id' => $sent->json('id'), 'body' => 'Not yours']);
    }

    public function test_deleted_message_cannot_be_edited_or_deleted_again(): void
    {
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller()->user;

        Sanctum::actingAs($buyer);
        $sent = $this->postJson('/api/messages', ['receiver_id' => $seller->id, 'body' => 'Temporary'])->assertCreated();
        $messageId = $sent->json('id');

        $this->deleteJson("/api/messages/{$messageId}")->assertOk();
        $this->patchJson("/api/messages/{$messageId}", ['body' => 'Resurrected'])->assertStatus(422);
        $this->deleteJson("/api/messages/{$messageId}")->assertStatus(422);
    }

    public function test_seller_can_edit_their_own_listing(): void
    {
        $sellerProfile = $this->makeSeller();
        $seller = $sellerProfile->user;
        $listing = $this->makeListing($sellerProfile);
        Sanctum::actingAs($seller);

        $response = $this->patchJson("/api/listings/{$listing->id}", [
            'price_per_piece' => 9.99,
            'quantity' => 42,
        ]);

        $response->assertOk()
            ->assertJsonPath('price_per_piece', '9.99')
            ->assertJsonPath('quantity', 42);

        $this->assertDatabaseHas('listings', ['id' => $listing->id, 'quantity' => 42]);
    }

    public function test_seller_cannot_edit_another_sellers_listing(): void
    {
        $sellerProfile = $this->makeSeller();
        $seller = $sellerProfile->user;
        $otherSellerProfile = $this->makeSeller();
        $otherListing = $this->makeListing($otherSellerProfile);
        Sanctum::actingAs($seller);

        $response = $this->patchJson("/api/listings/{$otherListing->id}", ['price_per_piece' => 1]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('listings', ['id' => $otherListing->id, 'price_per_piece' => 1]);
    }

    public function test_suspended_seller_cannot_edit_their_own_listing(): void
    {
        $sellerProfile = $this->makeSeller();
        $seller = $sellerProfile->user;
        $listing = $this->makeListing($sellerProfile);
        $sellerProfile->update(['status' => 'suspended']);

        Sanctum::actingAs($seller);
        $this->patchJson("/api/listings/{$listing->id}", ['price_per_piece' => 1])->assertStatus(403);
    }

    public function test_seller_can_delete_a_listing_with_no_orders(): void
    {
        $sellerProfile = $this->makeSeller();
        $seller = $sellerProfile->user;
        $listing = $this->makeListing($sellerProfile, ['approval_status' => 'pending', 'title' => 'Deletable Listing', 'species' => 'Test Species', 'quantity' => 10, 'price_per_piece' => 2.5]);
        Sanctum::actingAs($seller);

        $this->deleteJson("/api/listings/{$listing->id}")->assertOk();
        $this->assertDatabaseMissing('listings', ['id' => $listing->id]);
    }

    public function test_seller_cannot_delete_a_listing_with_existing_orders(): void
    {
        $sellerProfile = $this->makeSeller();
        $seller = $sellerProfile->user;
        $buyer = $this->makeBuyer();
        $listing = $this->makeListing($sellerProfile);
        $this->makeOrder($buyer, $listing);
        Sanctum::actingAs($seller);

        $response = $this->deleteJson("/api/listings/{$listing->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('listings', ['id' => $listing->id]);
    }

    public function test_seller_cannot_delete_another_sellers_listing(): void
    {
        $sellerProfile = $this->makeSeller();
        $seller = $sellerProfile->user;
        $otherSellerProfile = $this->makeSeller();
        $otherListing = $this->makeListing($otherSellerProfile);
        Sanctum::actingAs($seller);

        $this->deleteJson("/api/listings/{$otherListing->id}")->assertStatus(403);
        $this->assertDatabaseHas('listings', ['id' => $otherListing->id]);
    }

    public function test_listing_creation_always_uses_the_sellers_own_municipality_and_ignores_client_value(): void
    {
        $municipalities = Municipality::all();
        $sellerMunicipality = $municipalities->first();
        $otherMunicipality = $municipalities->skip(1)->first();

        $sellerProfile = $this->makeSeller(
            ['municipality_id' => $sellerMunicipality->id],
            ['municipality_id' => $sellerMunicipality->id]
        );
        Sanctum::actingAs($sellerProfile->user);

        $response = $this->postJson('/api/listings', [
            'species' => 'Bangus',
            'title' => 'Bangus Fingerlings',
            'quantity' => 100,
            'price_per_piece' => 2.5,
            'municipality_id' => $otherMunicipality->id,
        ]);

        $response->assertCreated()->assertJsonPath('municipality_id', $sellerMunicipality->id);
        $this->assertDatabaseHas('listings', ['id' => $response->json('id'), 'municipality_id' => $sellerMunicipality->id]);
        $this->assertDatabaseMissing('listings', ['id' => $response->json('id'), 'municipality_id' => $otherMunicipality->id]);
    }

    public function test_listing_description_is_saved_and_returned_to_buyers(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $sellerProfile = $this->makeSeller(
            ['municipality_id' => $lguAdmin->municipality_id],
            ['municipality_id' => $lguAdmin->municipality_id]
        );
        Sanctum::actingAs($sellerProfile->user);

        $response = $this->postJson('/api/listings', [
            'species' => 'Tilapia',
            'title' => 'Tilapia Fingerlings',
            'description' => 'Healthy tilapia fingerlings raised in aerated freshwater ponds.',
            'quantity' => 200,
            'price_per_piece' => 1.75,
        ]);

        $response->assertCreated()->assertJsonPath('description', 'Healthy tilapia fingerlings raised in aerated freshwater ponds.');

        // New listings start out pending and are not publicly visible until an LGU admin approves them.
        $this->getJson("/api/listings/{$response->json('id')}")->assertStatus(404);

        Sanctum::actingAs($lguAdmin);
        $this->patchJson("/api/lgu/listings/{$response->json('id')}/approve")->assertOk();

        Sanctum::actingAs($sellerProfile->user);
        $this->getJson("/api/listings/{$response->json('id')}")
            ->assertOk()
            ->assertJsonPath('description', 'Healthy tilapia fingerlings raised in aerated freshwater ponds.');

        $update = $this->patchJson("/api/listings/{$response->json('id')}", ['description' => 'Updated description text.']);
        $update->assertOk()->assertJsonPath('description', 'Updated description text.');
    }

    public function test_seller_can_update_their_public_profile_and_contact_phone(): void
    {
        $sellerProfile = $this->makeSeller();
        $seller = $sellerProfile->user;
        Sanctum::actingAs($seller);

        $response = $this->patchJson('/api/seller/profile', [
            'name' => 'Updated Seller Name',
            'email' => 'updated-seller@example.test',
            'hatchery_name' => 'Updated Hatchery',
            'description' => 'About our farm.',
            'farming_methods' => 'Recirculating aquaculture system.',
            'fish_raising_practices' => 'Daily water quality monitoring.',
            'farm_history' => 'Operating since 2015.',
            'water_source' => 'Deep well, filtered.',
            'feeding_practices' => 'Twice daily, commercial pellet feed.',
            'years_experience' => 8,
            'certifications' => 'BFAR Aquaculture Certificate',
            'address' => '123 Coastal Road',
            'profile_picture' => 'https://example.test/avatar.jpg',
            'cover_photo' => 'https://example.test/cover.jpg',
            'gallery' => ['https://example.test/photo1.jpg', 'https://example.test/photo2.jpg'],
            'phone' => '0917-000-0000',
        ]);

        $response->assertOk()
            ->assertJsonPath('hatchery_name', 'Updated Hatchery')
            ->assertJsonPath('address', '123 Coastal Road')
            ->assertJsonPath('water_source', 'Deep well, filtered.')
            ->assertJsonPath('years_experience', 8)
            ->assertJsonPath('certifications', 'BFAR Aquaculture Certificate')
            ->assertJsonPath('gallery', ['https://example.test/photo1.jpg', 'https://example.test/photo2.jpg']);

        $this->assertDatabaseHas('seller_profiles', ['id' => $sellerProfile->id, 'hatchery_name' => 'Updated Hatchery', 'address' => '123 Coastal Road', 'years_experience' => 8]);
        $this->assertDatabaseHas('users', ['id' => $seller->id, 'phone' => '0917-000-0000', 'name' => 'Updated Seller Name', 'email' => 'updated-seller@example.test']);

        $publicShow = $this->getJson("/api/sellers/{$sellerProfile->id}");
        $publicShow->assertOk()
            ->assertJsonPath('seller.farming_methods', 'Recirculating aquaculture system.')
            ->assertJsonPath('seller.feeding_practices', 'Twice daily, commercial pellet feed.')
            ->assertJsonPath('seller.user.name', 'Updated Seller Name')
            ->assertJsonStructure(['completed_sales']);
    }

    public function test_seller_can_upload_and_remove_profile_picture_and_cover_photo(): void
    {
        Storage::fake('public');
        $sellerProfile = $this->makeSeller();
        $seller = $sellerProfile->user;
        Sanctum::actingAs($seller);

        $picture = $this->postJson('/api/seller/profile/picture', [
            'photo' => UploadedFile::fake()->image('hatchery-avatar.jpg', 300, 300)->size(500),
        ]);
        $picture->assertOk();
        $pictureUrl = $picture->json('profile_picture');
        $this->assertNotEmpty($pictureUrl);
        $this->assertDatabaseHas('seller_profiles', ['id' => $sellerProfile->id, 'profile_picture' => $pictureUrl]);
        $this->assertDatabaseHas('users', ['id' => $seller->id, 'profile_picture' => $pictureUrl]);

        $cover = $this->postJson('/api/seller/profile/cover-photo', [
            'photo' => UploadedFile::fake()->image('farm-cover.jpg', 1200, 400)->size(1000),
        ]);
        $cover->assertOk();
        $coverUrl = $cover->json('cover_photo');
        $this->assertNotEmpty($coverUrl);
        $this->assertDatabaseHas('seller_profiles', ['id' => $sellerProfile->id, 'cover_photo' => $coverUrl]);

        $this->deleteJson('/api/seller/profile/picture')->assertOk()->assertJsonPath('profile_picture', null);
        $this->deleteJson('/api/seller/profile/cover-photo')->assertOk()->assertJsonPath('cover_photo', null);
        $this->assertDatabaseHas('seller_profiles', ['id' => $sellerProfile->id, 'profile_picture' => null, 'cover_photo' => null]);
        $this->assertDatabaseHas('users', ['id' => $seller->id, 'profile_picture' => null]);
    }

    public function test_seller_can_upload_reorder_and_delete_listing_images_up_to_the_limit(): void
    {
        Storage::fake('public');
        $sellerProfile = $this->makeSeller();
        $listing = $this->makeListing($sellerProfile);
        Sanctum::actingAs($sellerProfile->user);

        $upload = $this->postJson("/api/listings/{$listing->id}/media", [
            'photos' => [
                UploadedFile::fake()->image('one.jpg')->size(500),
                UploadedFile::fake()->image('two.jpg')->size(500),
            ],
        ]);
        $upload->assertOk();
        $media = $upload->json('media');
        $this->assertCount(2, $media);
        $this->assertSame(0, $media[0]['position']);
        $this->assertSame(1, $media[1]['position']);

        $reorder = $this->patchJson("/api/listings/{$listing->id}/media/reorder", [
            'order' => [$media[1]['id'], $media[0]['id']],
        ]);
        $reorder->assertOk();
        $reordered = $reorder->json('media');
        $this->assertSame($media[1]['id'], $reordered[0]['id']);
        $this->assertSame($media[0]['id'], $reordered[1]['id']);

        $tooMany = $this->postJson("/api/listings/{$listing->id}/media", [
            'photos' => array_fill(0, 4, UploadedFile::fake()->image('extra.jpg')->size(500)),
        ]);
        $tooMany->assertStatus(422);

        $delete = $this->deleteJson("/api/listings/{$listing->id}/media/{$media[0]['id']}");
        $delete->assertOk();
        $this->assertCount(1, $delete->json('media'));
    }

    public function test_seller_cannot_upload_images_to_another_sellers_listing(): void
    {
        Storage::fake('public');
        $owner = $this->makeSeller();
        $intruder = $this->makeSeller();
        $listing = $this->makeListing($owner);
        Sanctum::actingAs($intruder->user);

        $this->postJson("/api/listings/{$listing->id}/media", [
            'photos' => [UploadedFile::fake()->image('sneaky.jpg')->size(500)],
        ])->assertStatus(403);
    }

    public function test_uploaded_listing_photo_does_not_store_the_original_filename_as_its_title(): void
    {
        Storage::fake('public');
        $sellerProfile = $this->makeSeller();
        $listing = $this->makeListing($sellerProfile);
        Sanctum::actingAs($sellerProfile->user);

        $upload = $this->postJson("/api/listings/{$listing->id}/media", [
            'photos' => [UploadedFile::fake()->image('my-secret-vacation-photo.jpg')->size(500)],
        ]);

        $upload->assertOk();
        $media = $upload->json('media');
        $this->assertSame('photo', $media[0]['type']);
        $this->assertStringNotContainsString('my-secret-vacation-photo', $media[0]['title']);
    }

    public function test_seller_can_upload_a_video_to_a_listing(): void
    {
        Storage::fake('public');
        $sellerProfile = $this->makeSeller();
        $listing = $this->makeListing($sellerProfile);
        Sanctum::actingAs($sellerProfile->user);

        $upload = $this->postJson("/api/listings/{$listing->id}/media", [
            'photos' => [UploadedFile::fake()->create('hatchery-tour.mp4', 2048, 'video/mp4')],
        ]);

        $upload->assertOk();
        $media = $upload->json('media');
        $this->assertCount(1, $media);
        $this->assertSame('video', $media[0]['type']);
        $this->assertNotEmpty($media[0]['url']);
    }

    public function test_listing_media_upload_rejects_unsupported_file_types(): void
    {
        Storage::fake('public');
        $sellerProfile = $this->makeSeller();
        $listing = $this->makeListing($sellerProfile);
        Sanctum::actingAs($sellerProfile->user);

        $upload = $this->postJson("/api/listings/{$listing->id}/media", [
            'photos' => [UploadedFile::fake()->create('installer.exe', 100, 'application/x-msdownload')],
        ]);

        $upload->assertStatus(422);
        $this->assertDatabaseCount('listing_media', 0);
    }

    public function test_listing_video_upload_rejects_files_over_the_100mb_limit(): void
    {
        Storage::fake('public');
        $sellerProfile = $this->makeSeller();
        $listing = $this->makeListing($sellerProfile);
        Sanctum::actingAs($sellerProfile->user);

        $upload = $this->postJson("/api/listings/{$listing->id}/media", [
            'photos' => [UploadedFile::fake()->create('huge-tour.mp4', 102401, 'video/mp4')],
        ]);

        $upload->assertStatus(422);
        $this->assertDatabaseCount('listing_media', 0);
    }

    public function test_seller_cannot_update_profile_email_to_one_already_in_use(): void
    {
        $this->makeSeller(['email' => 'taken@example.test']);
        $sellerProfile = $this->makeSeller();
        Sanctum::actingAs($sellerProfile->user);

        $this->patchJson('/api/seller/profile', ['email' => 'taken@example.test'])->assertStatus(422);
    }

    public function test_buyer_can_update_their_own_profile(): void
    {
        $buyer = $this->makeBuyer();
        Sanctum::actingAs($buyer);

        $response = $this->patchJson('/api/buyer/profile', [
            'name' => 'Updated Buyer Name',
            'email' => 'updated-buyer@example.test',
            'phone' => '0917-111-2222',
            'address' => '456 Riverside Ave',
            'bio' => 'Small-scale tilapia farmer.',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.name', 'Updated Buyer Name')
            ->assertJsonPath('user.email', 'updated-buyer@example.test')
            ->assertJsonPath('buyer_profile.address', '456 Riverside Ave')
            ->assertJsonPath('buyer_profile.bio', 'Small-scale tilapia farmer.');

        $this->assertDatabaseHas('users', ['id' => $buyer->id, 'name' => 'Updated Buyer Name', 'email' => 'updated-buyer@example.test']);
        $this->assertDatabaseHas('buyer_profiles', ['user_id' => $buyer->id, 'address' => '456 Riverside Ave', 'bio' => 'Small-scale tilapia farmer.']);
    }

    public function test_buyer_can_upload_replace_and_remove_their_profile_picture(): void
    {
        Storage::fake('public');
        $buyer = $this->makeBuyer();
        Sanctum::actingAs($buyer);

        $upload = $this->postJson('/api/buyer/profile/picture', [
            'photo' => UploadedFile::fake()->image('avatar.jpg', 300, 300)->size(500),
        ]);
        $upload->assertOk();
        $firstUrl = $upload->json('profile_picture');
        $this->assertNotEmpty($firstUrl);
        $this->assertDatabaseHas('users', ['id' => $buyer->id, 'profile_picture' => $firstUrl]);

        $replace = $this->postJson('/api/buyer/profile/picture', [
            'photo' => UploadedFile::fake()->image('avatar2.png', 300, 300)->size(500),
        ]);
        $replace->assertOk();
        $this->assertNotSame($firstUrl, $replace->json('profile_picture'));

        $remove = $this->deleteJson('/api/buyer/profile/picture');
        $remove->assertOk()->assertJsonPath('profile_picture', null);
        $this->assertDatabaseHas('users', ['id' => $buyer->id, 'profile_picture' => null]);
    }

    public function test_buyer_profile_picture_upload_rejects_oversized_and_non_image_files(): void
    {
        Storage::fake('public');
        $buyer = $this->makeBuyer();
        Sanctum::actingAs($buyer);

        $this->postJson('/api/buyer/profile/picture', [
            'photo' => UploadedFile::fake()->create('resume.pdf', 500, 'application/pdf'),
        ])->assertStatus(422);

        $this->postJson('/api/buyer/profile/picture', [
            'photo' => UploadedFile::fake()->image('too-big.jpg')->size(6000),
        ])->assertStatus(422);
    }

    public function test_buyer_cannot_update_profile_email_to_one_already_in_use(): void
    {
        $this->makeBuyer(['email' => 'taken-buyer@example.test']);
        $buyer = $this->makeBuyer();
        Sanctum::actingAs($buyer);

        $this->patchJson('/api/buyer/profile', ['email' => 'taken-buyer@example.test'])->assertStatus(422);
    }

    public function test_buyer_cannot_change_their_municipality_via_profile_settings(): void
    {
        $municipalities = Municipality::all();
        $buyer = $this->makeBuyer(['municipality_id' => $municipalities->first()->id]);
        $otherMunicipality = $municipalities->skip(1)->first();
        Sanctum::actingAs($buyer);

        $this->patchJson('/api/buyer/profile', ['municipality_id' => $otherMunicipality->id, 'bio' => 'Trying to switch municipalities.'])->assertOk();

        $this->assertDatabaseHas('users', ['id' => $buyer->id, 'municipality_id' => $municipalities->first()->id]);
    }

    public function test_user_can_change_their_password_with_correct_current_password(): void
    {
        $buyer = $this->makeBuyer();
        Sanctum::actingAs($buyer);

        $this->patchJson('/api/auth/password', [
            'current_password' => 'password',
            'password' => 'new-secure-password',
        ])->assertOk();

        $this->postJson('/api/auth/login', ['email' => $buyer->email, 'password' => 'new-secure-password'])->assertOk();
    }

    public function test_password_change_is_rejected_with_wrong_current_password(): void
    {
        $buyer = $this->makeBuyer();
        Sanctum::actingAs($buyer);

        $this->patchJson('/api/auth/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-secure-password',
        ])->assertStatus(422);

        $this->postJson('/api/auth/login', ['email' => $buyer->email, 'password' => 'password'])->assertOk();
    }

    public function test_seller_registration_requires_municipality_and_is_correctly_assigned(): void
    {
        $municipality = Municipality::firstOrFail();

        $missingMunicipality = $this->postJson('/api/auth/register', [
            'name' => 'New Hatchery',
            'email' => 'new-hatchery@fishmarket.test',
            'password' => 'password123',
            'role' => 'seller',
        ]);
        $missingMunicipality->assertStatus(422);

        $response = $this->postJson('/api/auth/register', [
            'name' => 'New Hatchery',
            'email' => 'new-hatchery@fishmarket.test',
            'password' => 'password123',
            'role' => 'seller',
            'municipality_id' => $municipality->id,
        ]);
        $response->assertCreated();

        $this->assertDatabaseHas('seller_profiles', [
            'user_id' => $response->json('user.id'),
            'municipality_id' => $municipality->id,
        ]);
    }

    public function test_buyer_registration_still_works_without_municipality(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'New Buyer',
            'email' => 'new-buyer@fishmarket.test',
            'password' => 'password123',
            'role' => 'buyer',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('buyer_profiles', ['user_id' => $response->json('user.id')]);
    }

    public function test_seller_can_update_status_of_their_own_order(): void
    {
        $sellerProfile = $this->makeSeller();
        $seller = $sellerProfile->user;
        $buyer = $this->makeBuyer();
        $listing = $this->makeListing($sellerProfile);
        $order = $this->makeOrder($buyer, $listing);
        Sanctum::actingAs($seller);

        $response = $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'confirmed']);

        $response->assertOk()->assertJsonPath('status', 'confirmed');
    }

    public function test_seller_cannot_update_status_of_another_sellers_order(): void
    {
        $sellerProfile = $this->makeSeller();
        $seller = $sellerProfile->user;
        $otherSellerProfile = $this->makeSeller();
        $buyer = $this->makeBuyer();
        $otherListing = $this->makeListing($otherSellerProfile);
        $order = $this->makeOrder($buyer, $otherListing);
        Sanctum::actingAs($seller);

        $response = $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'cancelled']);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('orders', ['id' => $order->id, 'status' => 'cancelled']);
    }

    public function test_seller_dashboard_returns_listings_and_orders(): void
    {
        Sanctum::actingAs($this->makeSeller()->user);

        $response = $this->getJson('/api/seller/dashboard');

        $response->assertOk()
            ->assertJsonStructure(['seller', 'active_listings', 'pending_orders', 'listings', 'orders']);
    }

    public function test_seller_dashboard_counts_pending_listings_as_active(): void
    {
        $seller = $this->makeSeller();
        $this->makeListing($seller, ['approval_status' => 'pending', 'title' => 'Pending Listing']);
        $this->makeListing($seller, ['approval_status' => 'approved', 'title' => 'Approved Listing']);
        Sanctum::actingAs($seller->user);

        $response = $this->getJson('/api/seller/dashboard');

        $response->assertOk()
            ->assertJsonPath('active_listings', 2)
            ->assertJsonCount(2, 'listings');
    }

    public function test_seller_dashboard_counts_pending_listings_as_active_but_marketplace_hides_them(): void
    {
        $seller = $this->makeSeller();
        $this->makeListing($seller, ['approval_status' => 'pending', 'title' => 'Nemo Fingerlings']);
        Sanctum::actingAs($seller->user);

        $dashboard = $this->getJson('/api/seller/dashboard');
        $marketplace = $this->getJson('/api/listings');

        $marketplaceCountForSeller = collect($marketplace->json())
            ->where('seller_profile_id', $seller->id)
            ->count();

        // The seller's own dashboard still counts a pending listing as "active" inventory,
        // but the public marketplace must not show it until an LGU admin approves it.
        $this->assertSame(1, $dashboard->json('active_listings'));
        $this->assertSame(0, $marketplaceCountForSeller);
    }

    public function test_seller_can_view_buyer_profile_scoped_to_their_own_orders_and_reviews(): void
    {
        $seller = $this->makeSeller();
        $otherSeller = $this->makeSeller();
        $buyer = $this->makeBuyer(['name' => 'Carla Buyer']);
        $listing = $this->makeListing($seller, ['price_per_piece' => 10]);

        $completedOrder = $this->makeOrder($buyer, $listing, ['status' => 'completed', 'total_amount' => 100]);
        $this->makeOrder($buyer, $listing, ['status' => 'placed', 'total_amount' => 20]);
        Review::create([
            'order_id' => $completedOrder->id,
            'buyer_id' => $buyer->id,
            'seller_profile_id' => $seller->id,
            'rating' => 5,
            'comment' => 'Great seller!',
        ]);

        // Order with a different seller must not leak into this seller's view of the buyer.
        $otherListing = $this->makeListing($otherSeller);
        $this->makeOrder($buyer, $otherListing, ['status' => 'completed', 'total_amount' => 9999]);

        Sanctum::actingAs($seller->user);
        $response = $this->getJson("/api/seller/buyers/{$buyer->id}");

        $response->assertOk()
            ->assertJsonPath('buyer.name', 'Carla Buyer')
            ->assertJsonPath('stats.total_orders', 2)
            ->assertJsonPath('stats.completed_orders', 1)
            ->assertJsonPath('stats.pending_orders', 1)
            ->assertJsonPath('stats.total_spent', 100)
            ->assertJsonCount(1, 'reviews')
            ->assertJsonPath('reviews.0.comment', 'Great seller!');
    }

    public function test_seller_can_view_buyer_profile_via_existing_conversation_without_an_order(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        Message::create(['sender_id' => $buyer->id, 'receiver_id' => $seller->user_id, 'body' => 'Hi, is this still available?']);

        Sanctum::actingAs($seller->user);
        $response = $this->getJson("/api/seller/buyers/{$buyer->id}");

        $response->assertOk()->assertJsonPath('stats.total_orders', 0)->assertJsonPath('has_conversation', true);
    }

    public function test_seller_cannot_view_buyer_profile_without_order_or_conversation(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();

        Sanctum::actingAs($seller->user);
        $this->getJson("/api/seller/buyers/{$buyer->id}")->assertStatus(403);
    }

    public function test_seller_cannot_view_a_non_buyer_profile_through_the_buyer_profile_endpoint(): void
    {
        $seller = $this->makeSeller();
        $otherSeller = $this->makeSeller();

        Sanctum::actingAs($seller->user);
        $this->getJson("/api/seller/buyers/{$otherSeller->user_id}")->assertStatus(404);
    }

    public function test_lgu_admin_can_suspend_and_reinstate_a_seller_in_their_municipality(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $seller = $this->makeSeller(
            ['municipality_id' => $lguAdmin->municipality_id],
            ['municipality_id' => $lguAdmin->municipality_id]
        );
        Sanctum::actingAs($lguAdmin);

        $suspend = $this->patchJson("/api/lgu/sellers/{$seller->id}/suspend");
        $suspend->assertOk()->assertJsonPath('status', 'suspended');

        $reinstate = $this->patchJson("/api/lgu/sellers/{$seller->id}/reinstate");
        $reinstate->assertOk();
        $this->assertNotEquals('suspended', $reinstate->json('status'));
    }

    public function test_suspended_seller_cannot_log_in(): void
    {
        $sellerProfile = $this->makeSeller();
        $sellerUser = $sellerProfile->user;
        $sellerProfile->update(['status' => 'suspended']);

        $this->postJson('/api/auth/login', [
            'email' => $sellerUser->email,
            'password' => 'password',
        ])->assertStatus(403);
    }

    public function test_suspended_seller_cannot_create_listing_and_listings_are_hidden_from_marketplace(): void
    {
        $sellerProfile = $this->makeSeller();
        $sellerUser = $sellerProfile->user;
        $visibleListingId = $this->makeListing($sellerProfile, ['approval_status' => 'approved'])->id;

        $sellerProfile->update(['status' => 'suspended']);

        Sanctum::actingAs($sellerUser);
        $this->postJson('/api/listings', [
            'municipality_id' => $sellerProfile->municipality_id,
            'species' => 'Tilapia',
            'title' => 'Should not be created',
            'quantity' => 100,
            'price_per_piece' => 3,
        ])->assertStatus(403);

        $browse = $this->getJson('/api/listings');
        $browse->assertOk();
        $this->assertFalse(collect($browse->json())->contains('id', $visibleListingId));

        $this->getJson("/api/listings/{$visibleListingId}")->assertStatus(404);
        $this->getJson("/api/sellers/{$sellerProfile->id}")->assertStatus(404);
    }

    public function test_lgu_can_manage_users_and_sellers_directory(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        Sanctum::actingAs($lguAdmin);

        $this->getJson('/api/lgu/users')->assertOk()->assertJsonStructure(['buyers', 'sellers']);
        $this->getJson('/api/lgu/sellers')->assertOk();
    }

    public function test_lgu_reports_are_scoped_to_their_municipality(): void
    {
        Sanctum::actingAs(User::where('role', 'lgu_admin')->firstOrFail());

        $response = $this->getJson('/api/lgu/reports');

        $response->assertOk()
            ->assertJsonStructure(['registered_sellers', 'buyers', 'listings', 'pending_approvals']);
    }

    public function test_lgu_reviews_endpoint_returns_detailed_information_scoped_to_municipality(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $ownMunicipality = $lguAdmin->municipality;
        $otherMunicipality = Municipality::where('id', '!=', $ownMunicipality->id)->firstOrFail();

        $buyer = $this->makeBuyer(['name' => 'Pedro Buyer']);
        $seller = $this->makeSeller(
            ['name' => 'Ana Seller'],
            ['hatchery_name' => "Ana's Hatchery", 'municipality_id' => $ownMunicipality->id]
        );
        $listing = $this->makeListing($seller, ['species' => 'Tilapia', 'title' => 'Tilapia Fingerlings']);
        $order = $this->makeOrder($buyer, $listing, ['status' => 'completed']);
        Review::create([
            'order_id' => $order->id,
            'buyer_id' => $buyer->id,
            'seller_profile_id' => $seller->id,
            'rating' => 4,
            'title' => 'Good stock',
            'comment' => 'Healthy fingerlings.',
        ]);

        $otherSeller = $this->makeSeller([], ['municipality_id' => $otherMunicipality->id]);
        $otherListing = $this->makeListing($otherSeller);
        $otherOrder = $this->makeOrder($this->makeBuyer(), $otherListing, ['status' => 'completed']);
        Review::create([
            'order_id' => $otherOrder->id,
            'buyer_id' => $otherOrder->buyer_id,
            'seller_profile_id' => $otherSeller->id,
            'rating' => 2,
            'comment' => 'Should not appear.',
        ]);

        Sanctum::actingAs($lguAdmin);
        $response = $this->getJson('/api/lgu/reviews');

        $response->assertOk()->assertJsonCount(1);
        $response->assertJsonPath('0.rating', 4)
            ->assertJsonPath('0.buyer.name', 'Pedro Buyer')
            ->assertJsonPath('0.sellerProfile.hatchery_name', "Ana's Hatchery")
            ->assertJsonPath('0.sellerProfile.user.name', 'Ana Seller')
            ->assertJsonPath('0.order.listing.species', 'Tilapia')
            ->assertJsonPath('0.order.order_number', $order->order_number);
    }

    public function test_lgu_users_endpoint_confirms_email_verified_at_is_always_null(): void
    {
        // Documents the root cause of the frontend "null" display bug: this app has no
        // email-verification flow, so this field is always null and must not be rendered
        // as a raw value by the LGU Users table.
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $this->makeSeller(['name' => 'Null Field Seller', 'municipality_id' => $lguAdmin->municipality_id], ['municipality_id' => $lguAdmin->municipality_id]);
        Sanctum::actingAs($lguAdmin);

        $response = $this->getJson('/api/lgu/users');

        $response->assertOk();
        $sellers = $response->json('sellers');
        $this->assertNotEmpty($sellers);
        $this->assertNull($sellers[0]['email_verified_at']);
    }

    public function test_super_admin_can_create_edit_and_disable_lgu_admin(): void
    {
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        $municipality = Municipality::firstOrFail();
        Sanctum::actingAs($superAdmin);

        $create = $this->postJson('/api/super-admin/lgu-admins', [
            'name' => 'New LGU Officer',
            'email' => 'new-lgu@fishmarket.test',
            'password' => 'password123',
            'municipality_id' => $municipality->id,
        ]);
        $create->assertCreated()->assertJsonPath('role', 'lgu_admin');
        $adminId = $create->json('id');

        $update = $this->patchJson("/api/super-admin/lgu-admins/{$adminId}", ['name' => 'Renamed Officer']);
        $update->assertOk()->assertJsonPath('name', 'Renamed Officer');

        $disable = $this->patchJson("/api/super-admin/lgu-admins/{$adminId}/disable");
        $disable->assertOk()->assertJsonPath('status', 'disabled');

        $this->postJson('/api/auth/login', [
            'email' => 'new-lgu@fishmarket.test',
            'password' => 'password123',
        ])->assertStatus(403);

        $enable = $this->patchJson("/api/super-admin/lgu-admins/{$adminId}/enable");
        $enable->assertOk()->assertJsonPath('status', 'active');

        $this->postJson('/api/auth/login', [
            'email' => 'new-lgu@fishmarket.test',
            'password' => 'password123',
        ])->assertOk();
    }

    public function test_super_admin_can_view_all_sellers_including_suspended(): void
    {
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        $seller = $this->makeSeller();
        $seller->update(['status' => 'suspended']);

        Sanctum::actingAs($superAdmin);
        $response = $this->getJson('/api/super-admin/sellers');
        $response->assertOk()->assertJsonFragment(['id' => $seller->id, 'status' => 'suspended']);
    }

    public function test_public_municipalities_endpoint_works(): void
    {
        $this->getJson('/api/municipalities')->assertOk()->assertJsonStructure([['id', 'name']]);
    }

    public function test_cordova_municipality_is_available_and_existing_municipalities_are_preserved(): void
    {
        $names = collect($this->getJson('/api/municipalities')->json())->pluck('name');

        $this->assertTrue($names->contains('Cordova'));
        foreach (['Mandaue', 'Consolacion', 'Compostela', 'Liloan', 'Lapu-Lapu', 'Talisay', 'Carmen'] as $existing) {
            $this->assertTrue($names->contains($existing), "Expected existing municipality [{$existing}] to still be present.");
        }
    }

    public function test_super_admin_can_create_lgu_admin_assigned_to_cordova(): void
    {
        $cordova = Municipality::where('name', 'Cordova')->firstOrFail();
        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());

        $response = $this->postJson('/api/super-admin/lgu-admins', [
            'name' => 'Cordova LGU Admin',
            'email' => 'cordova-lgu@example.test',
            'password' => 'password123',
            'municipality_id' => $cordova->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('municipality_id', $cordova->id);

        $this->assertDatabaseHas('users', [
            'email' => 'cordova-lgu@example.test',
            'role' => 'lgu_admin',
            'municipality_id' => $cordova->id,
        ]);
    }

    public function test_super_admin_reports_and_admin_lists_work(): void
    {
        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());

        $reports = $this->getJson('/api/super-admin/reports');
        $admins = $this->getJson('/api/super-admin/lgu-admins');

        $reports->assertOk()->assertJsonStructure(['total_lgus', 'total_sellers', 'total_buyers', 'total_listings', 'total_transactions', 'pending_payouts']);
        $admins->assertOk();
    }

    public function test_buyer_can_review_a_completed_order_and_seller_rating_updates(): void
    {
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller);
        $order = $this->makeOrder($buyer, $listing, ['status' => 'completed']);
        Sanctum::actingAs($buyer);

        $response = $this->postJson("/api/orders/{$order->id}/review", [
            'rating' => 5,
            'title' => 'Excellent hatchery',
            'comment' => 'Great fingerlings, healthy and on time.',
        ]);

        $response->assertCreated()->assertJsonPath('rating', 5)->assertJsonPath('title', 'Excellent hatchery');

        $this->assertDatabaseHas('reviews', [
            'order_id' => $order->id,
            'buyer_id' => $buyer->id,
            'rating' => 5,
            'title' => 'Excellent hatchery',
        ]);

        $expectedAverage = round(Review::where('seller_profile_id', $order->seller_profile_id)->avg('rating'), 2);
        $this->assertEquals($expectedAverage, $order->sellerProfile->fresh()->rating);
    }

    public function test_seller_marking_order_completed_makes_it_eligible_for_review_and_profile_shows_reviewer_details(): void
    {
        $buyer = $this->makeBuyer(['name' => 'Ana Reyes']);
        $sellerProfile = $this->makeSeller();
        $seller = $sellerProfile->user;
        $listing = $this->makeListing($sellerProfile);
        $order = $this->makeOrder($buyer, $listing, ['status' => 'in_transit']);

        Sanctum::actingAs($seller);
        $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $sellerDashboard = $this->getJson('/api/seller/dashboard');
        $sellerDashboard->assertOk();
        $orderRow = collect($sellerDashboard->json('orders'))->firstWhere('id', $order->id);
        $this->assertSame('Ana Reyes', $orderRow['buyer']['name']);

        Sanctum::actingAs($buyer);
        $this->postJson("/api/orders/{$order->id}/review", [
            'rating' => 4,
            'title' => 'Solid fingerlings',
            'comment' => 'Would buy again.',
        ])->assertCreated();

        $profile = $this->getJson("/api/sellers/{$sellerProfile->id}");
        $profile->assertOk();
        $this->assertSame(1, $profile->json('completed_sales'));
        $review = $profile->json('reviews')[0];
        $this->assertSame('Ana Reyes', $review['buyer']['name']);
        $this->assertSame('Solid fingerlings', $review['title']);
        $this->assertSame(4, $review['rating']);
    }

    public function test_buyer_cannot_review_an_order_that_is_not_completed(): void
    {
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller);
        $order = $this->makeOrder($buyer, $listing, ['status' => 'placed']);
        Sanctum::actingAs($buyer);

        $this->postJson("/api/orders/{$order->id}/review", ['rating' => 4])
            ->assertStatus(422);
    }

    public function test_public_seller_directory_and_profile_endpoints_work(): void
    {
        $seller = $this->makeSeller();
        $this->makeListing($seller, ['approval_status' => 'approved']);
        $this->makeListing($seller, ['approval_status' => 'pending', 'title' => 'Pending Listing']);

        $index = $this->getJson('/api/sellers');
        $index->assertOk()->assertJsonStructure([['id', 'hatchery_name', 'rating', 'verified', 'listings_count']]);
        $this->assertSame(1, collect($index->json())->firstWhere('id', $seller->id)['listings_count']);

        $show = $this->getJson("/api/sellers/{$seller->id}");
        $show->assertOk()->assertJsonStructure(['seller' => ['id', 'hatchery_name'], 'listings', 'reviews']);

        // Only the approved listing should be visible on the public profile; the pending one is hidden.
        $listings = $show->json('listings');
        $this->assertCount(1, $listings);
        $this->assertSame('approved', $listings[0]['approval_status']);
    }

    public function test_seller_profile_hides_pending_listings_from_buyers_including_the_owners_own(): void
    {
        $seller = $this->makeSeller();
        $otherSeller = $this->makeSeller();
        $approvedOwnListing = $this->makeListing($seller, ['approval_status' => 'approved', 'title' => 'My Approved Listing']);
        $this->makeListing($seller, ['approval_status' => 'pending', 'title' => 'My Pending Listing']);
        $this->makeListing($otherSeller, ['approval_status' => 'approved', 'title' => 'Someone Elses Listing']);

        $show = $this->getJson("/api/sellers/{$seller->id}");
        $listings = $show->json('listings');

        $this->assertCount(1, $listings);
        $this->assertSame($approvedOwnListing->id, $listings[0]['id']);
    }

    public function test_pending_listing_is_hidden_from_marketplace_detail_and_orders_but_visible_on_seller_dashboard(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        $listing = $this->makeListing($seller, ['approval_status' => 'pending', 'title' => 'Awaiting Approval']);

        // Hidden from the public marketplace index and search.
        $this->getJson('/api/listings')->assertOk()->assertJsonMissing(['id' => $listing->id]);
        $this->getJson('/api/listings?species='.$listing->species)->assertOk()->assertJsonMissing(['id' => $listing->id]);

        // Hidden from direct detail access.
        $this->getJson("/api/listings/{$listing->id}")->assertStatus(404);

        // Cannot be ordered even via a direct API call with a known id.
        Sanctum::actingAs($buyer);
        $this->postJson('/api/orders', [
            'fingerling_listing_id' => $listing->id,
            'quantity' => 1,
        ])->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);

        // Still fully visible to the seller on their own dashboard.
        Sanctum::actingAs($seller->user);
        $this->getJson('/api/seller/dashboard')->assertOk()->assertJsonFragment(['id' => $listing->id]);
    }

    public function test_buyer_cannot_submit_duplicate_review_for_same_order(): void
    {
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller);
        $order = $this->makeOrder($buyer, $listing, ['status' => 'completed']);
        Sanctum::actingAs($buyer);

        $this->postJson("/api/orders/{$order->id}/review", ['rating' => 4])->assertCreated();
        $this->postJson("/api/orders/{$order->id}/review", ['rating' => 3])->assertStatus(422);

        $this->assertEquals(1, Review::where('order_id', $order->id)->count());
    }
}
