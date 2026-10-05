<?php

namespace Tests\Feature;

use App\Mail\AccountReinstatedMail;
use App\Mail\AccountRemovedMail;
use App\Mail\AccountSuspendedMail;
use App\Mail\ListingApprovedMail;
use App\Mail\ListingRejectedMail;
use App\Mail\LguWithdrawalApprovedMail;
use App\Mail\LguWithdrawalReleasedMail;
use App\Mail\NewOrderReceivedMail;
use App\Mail\OrderConfirmedMail;
use App\Mail\OrderDeliveredMail;
use App\Mail\PaymentReceiptMail;
use App\Mail\SellerEarningsApprovedMail;
use App\Mail\SellerRegistrationReviewedMail;
use App\Mail\SellerWithdrawalApprovedMail;
use App\Mail\SupportTicketUpdatedMail;
use App\Mail\WithdrawalReleasedMail;
use App\Models\ActivityLogEntry;
use App\Models\AppNotification;
use App\Models\BuyerProfile;
use App\Models\FingerlingListing;
use App\Models\LguWithdrawalRequest;
use App\Models\ListingMedia;
use App\Models\Message;
use App\Models\ModerationLog;
use App\Models\MockPayment;
use App\Models\Municipality;
use App\Models\Order;
use App\Models\PaymentLog;
use App\Models\Review;
use App\Models\SellerNotice;
use App\Models\SellerProfile;
use App\Models\Settlement;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Support\AccountModeration;
use App\Support\CommissionCalculator;
use App\Support\PaymentReturnToken;
use App\Support\PayoutAccount;
use App\Support\SellerApproval;
use App\Support\SellerReputation;
use App\Support\SellerSanctions;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
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
            'email_verified_at' => now(),
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
            'email_verified_at' => now(),
        ], $userOverrides));

        return SellerProfile::create(array_merge([
            'user_id' => $user->id,
            'municipality_id' => $user->municipality_id,
            'hatchery_name' => $user->name,
            'description' => 'Test hatchery for automated tests.',
            'verified' => true,
            'status' => 'verified',
            // Test sellers default to an approved registration so existing
            // listing/order tests are unaffected by the approval workflow.
            // Tests that exercise the workflow itself override
            // approval_status explicitly (see makePendingSeller).
            'approval_status' => SellerApproval::APPROVED,
        ], $profileOverrides));
    }

    /**
     * Complete an order the way the app now does it: the BUYER confirms the
     * fingerlings arrived. Sellers can no longer mark their own deliveries
     * complete -- that let them start their own payout without the buyer ever
     * confirming receipt -- so tests that merely need a completed order go
     * through here rather than PATCHing status to 'completed'.
     *
     * $resumeAs restores the acting user afterwards, since Sanctum::actingAs is
     * global and most callers carry on as the seller or an admin.
     */
    protected function buyerConfirmsReceipt(Order|int $order, ?User $resumeAs = null): void
    {
        $order = $order instanceof Order ? $order : Order::findOrFail($order);
        $order->loadMissing('buyer');

        Sanctum::actingAs($order->buyer);
        $this->patchJson("/api/orders/{$order->id}/confirm-received")->assertOk();

        if ($resumeAs) {
            Sanctum::actingAs($resumeAs);
        }
    }

    protected function makeLguAdmin(array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => 'Test LGU Admin',
            'email' => 'lgu-'.Str::random(10).'@example.test',
            'password' => Hash::make('password'),
            'role' => 'lgu_admin',
            'municipality_id' => Municipality::first()->id,
            'status' => 'active',
            'email_verified_at' => now(),
        ], $overrides));
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
            // Every listing states a bulk size now, so the factory does too --
            // otherwise editing one trips the "state your bulk size" rule that
            // exists for listings predating the field.
            'pieces_per_unit' => 10,
            'average_size' => '4-5 inches',
            'availability_status' => 'in_stock',
            'approval_status' => 'approved',
        ], $overrides));
    }

    /**
     * Create a listing through the API. A photo is mandatory now, and a JSON
     * body cannot carry a file -- so this goes through post() with an explicit
     * Accept header, which keeps validation failures coming back as 422 JSON
     * rather than a redirect.
     */
    protected function postListing(array $payload)
    {
        return $this->post('/api/listings', array_merge([
            'photos' => [UploadedFile::fake()->image('fingerlings.jpg')->size(300)],
            // Every listing states its bulk size now -- the buyer chooses
            // quantity or bulk at order time, so it is always needed.
            'pieces_per_unit' => 10,
        ], $payload), ['Accept' => 'application/json']);
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

    /**
     * Creates the immutable Settlement row a real LguController::approveEarnings
     * call would produce, for tests that need a seller's Available Balance to
     * already be populated without going through the full HTTP approval flow.
     * Uses the fixed marketplace commission split unless overridden, exactly
     * like the real endpoint.
     */
    protected function makeSettlement(Order $order, MockPayment $payment, array $overrides = []): Settlement
    {
        $order->loadMissing('sellerProfile');
        $split = CommissionCalculator::split((float) $payment->amount);

        return Settlement::create(array_merge([
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'seller_profile_id' => $order->seller_profile_id,
            'municipality_id' => $order->sellerProfile->municipality_id,
            'approved_by' => User::where('role', 'lgu_admin')->first()->id,
            'gross_amount' => $payment->amount,
            'seller_share' => $split['seller_share'],
            'lgu_share' => $split['lgu_share'],
            'platform_share' => $split['platform_share'],
            'seller_percent' => $split['seller_percent'],
            'lgu_percent' => $split['lgu_percent'],
            'platform_percent' => $split['platform_percent'],
            'status' => 'settled',
            'settled_at' => now(),
        ], $overrides));
    }

    /**
     * Creates a withdrawal request with its platform_fee computed the same
     * way the real SellerController::requestWithdrawal endpoint would.
     */
    protected function makeWithdrawal(SellerProfile $seller, array $overrides = []): WithdrawalRequest
    {
        $amount = $overrides['amount'] ?? 100;
        $fee = CommissionCalculator::withdrawalFee((float) $amount);

        return WithdrawalRequest::create(array_merge([
            'seller_profile_id' => $seller->id,
            'method' => 'gcash',
            'account_name' => 'Test Seller',
            'account_number' => '09171234567',
            'amount' => $amount,
            'platform_fee' => $fee['fee'],
            'status' => 'pending',
        ], $overrides));
    }

    /**
     * Creates an LGU withdrawal request directly, for tests that need one to
     * already exist without going through the full HTTP request flow. No
     * platform fee -- LGU withdrawals aren't charged one (see
     * App\Support\LguWallet).
     */
    protected function makeLguWithdrawal(int $municipalityId, array $overrides = []): LguWithdrawalRequest
    {
        return LguWithdrawalRequest::create(array_merge([
            'municipality_id' => $municipalityId,
            'requested_by' => User::where('role', 'lgu_admin')->where('municipality_id', $municipalityId)->first()?->id,
            'method' => 'gcash',
            'account_name' => 'Test LGU',
            'account_number' => '09171234567',
            'amount' => 100,
            'status' => 'pending',
        ], $overrides));
    }

    public function test_database_heartbeat_endpoint_reports_a_reachable_connection(): void
    {
        // An external uptime pinger hits this every few minutes so the free
        // Aiven plan never sees an idle window and powers the database off.
        $response = $this->getJson('/up/db');

        $response->assertOk();
        $response->assertJson(['status' => 'ok', 'database' => 'reachable']);
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

    public function test_lgu_admin_can_view_an_approved_listing_outside_their_municipality_read_only(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $otherMunicipality = Municipality::where('id', '!=', $lguAdmin->municipality_id)->firstOrFail();
        $seller = $this->makeSeller([], ['municipality_id' => $otherMunicipality->id]);
        $listing = $this->makeListing($seller, ['approval_status' => 'approved']);
        Sanctum::actingAs($lguAdmin);

        $this->getJson("/api/lgu/listings/{$listing->id}")->assertOk()->assertJsonPath('approval_status', 'approved');

        // Read access does not imply management access -- mutating endpoints stay municipality-scoped.
        $this->patchJson("/api/lgu/listings/{$listing->id}/approve")->assertStatus(403);
        $this->patchJson("/api/lgu/listings/{$listing->id}/archive")->assertStatus(403);
        $this->deleteJson("/api/lgu/listings/{$listing->id}", ['reason' => 'Test'])->assertStatus(403);
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

    public function test_lgu_listing_management_index_includes_approved_listings_and_is_scoped_to_municipality(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $seller = $this->makeSeller(
            ['municipality_id' => $lguAdmin->municipality_id],
            ['municipality_id' => $lguAdmin->municipality_id]
        );
        $approvedListing = $this->makeListing($seller, ['approval_status' => 'approved']);
        $otherMunicipality = Municipality::where('id', '!=', $lguAdmin->municipality_id)->firstOrFail();
        $otherSeller = $this->makeSeller([], ['municipality_id' => $otherMunicipality->id]);
        $outsideListing = $this->makeListing($otherSeller, ['approval_status' => 'approved']);

        Sanctum::actingAs($lguAdmin);
        $response = $this->getJson('/api/lgu/listings');

        $response->assertOk()->assertJsonFragment(['id' => $approvedListing->id]);
        $ids = collect($response->json())->pluck('id');
        $this->assertFalse($ids->contains($outsideListing->id));
    }

    public function test_lgu_admin_can_archive_a_listing_in_their_municipality_and_seller_is_notified(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $seller = $this->makeSeller(
            ['municipality_id' => $lguAdmin->municipality_id],
            ['municipality_id' => $lguAdmin->municipality_id]
        );
        $listing = $this->makeListing($seller, ['approval_status' => 'approved', 'title' => 'Bangus Fingerlings Batch A']);
        Sanctum::actingAs($lguAdmin);

        $response = $this->patchJson("/api/lgu/listings/{$listing->id}/archive", ['reason' => 'Seller requested a pause.']);

        $response->assertOk()->assertJsonPath('approval_status', 'archived');
        $this->assertDatabaseHas('notifications', [
            'user_id' => $seller->user_id,
            'type' => 'listing_archived',
        ]);
        $notification = AppNotification::where('user_id', $seller->user_id)->where('type', 'listing_archived')->firstOrFail();
        $this->assertStringContainsString('Bangus Fingerlings Batch A', $notification->body);
        $this->assertStringContainsString('Seller requested a pause.', $notification->body);
        $this->assertStringContainsString($lguAdmin->name, $notification->body);
    }

    public function test_lgu_admin_cannot_archive_a_listing_outside_their_municipality(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $otherMunicipality = Municipality::where('id', '!=', $lguAdmin->municipality_id)->firstOrFail();
        $seller = $this->makeSeller([], ['municipality_id' => $otherMunicipality->id]);
        $listing = $this->makeListing($seller);
        Sanctum::actingAs($lguAdmin);

        $this->patchJson("/api/lgu/listings/{$listing->id}/archive")->assertStatus(403);
    }

    public function test_lgu_admin_delete_requires_a_reason(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $seller = $this->makeSeller(
            ['municipality_id' => $lguAdmin->municipality_id],
            ['municipality_id' => $lguAdmin->municipality_id]
        );
        $listing = $this->makeListing($seller);
        Sanctum::actingAs($lguAdmin);

        $this->deleteJson("/api/lgu/listings/{$listing->id}")->assertStatus(422);
        $this->assertDatabaseHas('listings', ['id' => $listing->id]);
    }

    public function test_lgu_admin_can_delete_a_listing_with_no_orders_and_seller_is_notified(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $seller = $this->makeSeller(
            ['municipality_id' => $lguAdmin->municipality_id],
            ['municipality_id' => $lguAdmin->municipality_id]
        );
        $listing = $this->makeListing($seller, ['title' => 'Tilapia Fingerlings Batch B']);
        Sanctum::actingAs($lguAdmin);

        $response = $this->deleteJson("/api/lgu/listings/{$listing->id}", ['reason' => 'Violates listing guidelines.']);

        $response->assertOk();
        $this->assertDatabaseMissing('listings', ['id' => $listing->id]);
        $notification = AppNotification::where('user_id', $seller->user_id)->where('type', 'listing_deleted')->firstOrFail();
        $this->assertStringContainsString('Tilapia Fingerlings Batch B', $notification->body);
        $this->assertStringContainsString('Violates listing guidelines.', $notification->body);
    }

    public function test_lgu_admin_cannot_delete_a_listing_that_has_existing_orders(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $seller = $this->makeSeller(
            ['municipality_id' => $lguAdmin->municipality_id],
            ['municipality_id' => $lguAdmin->municipality_id]
        );
        $listing = $this->makeListing($seller);
        $buyer = $this->makeBuyer();
        $this->makeOrder($buyer, $listing);
        Sanctum::actingAs($lguAdmin);

        $this->deleteJson("/api/lgu/listings/{$listing->id}", ['reason' => 'Test'])->assertStatus(422);
        $this->assertDatabaseHas('listings', ['id' => $listing->id]);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_lgu_admin_cannot_delete_a_listing_outside_their_municipality(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $otherMunicipality = Municipality::where('id', '!=', $lguAdmin->municipality_id)->firstOrFail();
        $seller = $this->makeSeller([], ['municipality_id' => $otherMunicipality->id]);
        $listing = $this->makeListing($seller);
        Sanctum::actingAs($lguAdmin);

        $this->deleteJson("/api/lgu/listings/{$listing->id}", ['reason' => 'Test'])->assertStatus(403);
        $this->assertDatabaseHas('listings', ['id' => $listing->id]);
    }

    public function test_super_admin_listing_management_index_spans_every_municipality(): void
    {
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        $sellerA = $this->makeSeller();
        $listingA = $this->makeListing($sellerA);
        $municipalityB = Municipality::where('id', '!=', $sellerA->municipality_id)->firstOrFail();
        $sellerB = $this->makeSeller([], ['municipality_id' => $municipalityB->id]);
        $listingB = $this->makeListing($sellerB);

        Sanctum::actingAs($superAdmin);
        $response = $this->getJson('/api/super-admin/listings');

        $response->assertOk()
            ->assertJsonFragment(['id' => $listingA->id])
            ->assertJsonFragment(['id' => $listingB->id]);
    }

    public function test_super_admin_can_edit_any_listing(): void
    {
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller, ['title' => 'Old Title']);
        Sanctum::actingAs($superAdmin);

        $response = $this->patchJson("/api/super-admin/listings/{$listing->id}", ['title' => 'Updated Title']);

        $response->assertOk()->assertJsonPath('title', 'Updated Title');
    }

    public function test_super_admin_can_approve_and_reject_any_listing_regardless_of_municipality(): void
    {
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller, ['approval_status' => 'pending']);
        Sanctum::actingAs($superAdmin);

        $this->patchJson("/api/super-admin/listings/{$listing->id}/approve")->assertOk()->assertJsonPath('approval_status', 'approved');
        $this->patchJson("/api/super-admin/listings/{$listing->id}/reject", ['reason' => 'Not compliant.'])
            ->assertOk()->assertJsonPath('approval_status', 'rejected');
    }

    public function test_super_admin_can_archive_and_delete_any_listing_with_notification(): void
    {
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller, ['title' => 'Carp Fingerlings']);
        Sanctum::actingAs($superAdmin);

        $this->patchJson("/api/super-admin/listings/{$listing->id}/archive", ['reason' => 'Platform review.'])
            ->assertOk()->assertJsonPath('approval_status', 'archived');

        $this->deleteJson("/api/super-admin/listings/{$listing->id}")->assertStatus(422);
        $response = $this->deleteJson("/api/super-admin/listings/{$listing->id}", ['reason' => 'Repeated violations.']);
        $response->assertOk();
        $this->assertDatabaseMissing('listings', ['id' => $listing->id]);
        $this->assertDatabaseHas('notifications', ['user_id' => $seller->user_id, 'type' => 'listing_deleted']);
    }

    public function test_super_admin_cannot_delete_a_listing_that_has_existing_orders(): void
    {
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller);
        $buyer = $this->makeBuyer();
        $this->makeOrder($buyer, $listing);
        Sanctum::actingAs($superAdmin);

        $this->deleteJson("/api/super-admin/listings/{$listing->id}", ['reason' => 'Test'])->assertStatus(422);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_super_admin_users_endpoint_returns_platform_wide_buyers(): void
    {
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        $buyer = $this->makeBuyer();
        Sanctum::actingAs($superAdmin);

        $this->getJson('/api/super-admin/users')->assertOk()->assertJsonFragment(['id' => $buyer->id]);
    }

    public function test_super_admin_can_view_and_mark_notifications_read(): void
    {
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        AppNotification::create(['user_id' => $superAdmin->id, 'type' => 'test', 'title' => 'Test', 'body' => 'Body']);
        Sanctum::actingAs($superAdmin);

        $list = $this->getJson('/api/super-admin/notifications');
        $list->assertOk()->assertJsonCount(1);
        $id = $list->json('0.id');

        $this->patchJson("/api/super-admin/notifications/{$id}/read")->assertOk();
        $this->getJson('/api/super-admin/notifications')->assertJsonCount(0);
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

    /**
     * Seller Earnings for the Super Admin: the LGU's queue and approval,
     * across every municipality, through the same settlement path -- not the
     * old direct release the test above keeps closed.
     */
    public function test_super_admin_sees_every_municipalitys_earnings_and_can_approve_them(): void
    {
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $other = Municipality::where('id', '!=', $lguAdmin->municipality_id)->firstOrFail();
        $localSeller = $this->makeSeller([], ['municipality_id' => $lguAdmin->municipality_id]);
        $outsideSeller = $this->makeSeller(['municipality_id' => $other->id], ['municipality_id' => $other->id]);
        $localOrder = $this->makeOrder($this->makeBuyer(), $this->makeListing($localSeller), ['status' => 'completed']);
        $outsideOrder = $this->makeOrder($this->makeBuyer(), $this->makeListing($outsideSeller), ['status' => 'completed']);
        $localPayment = $this->makePayment($localOrder, ['status' => 'paid_held', 'amount' => $localOrder->total_amount]);
        $outsidePayment = $this->makePayment($outsideOrder, ['status' => 'paid_held', 'amount' => $outsideOrder->total_amount]);

        // The LGU still only sees its own municipality.
        Sanctum::actingAs($lguAdmin);
        $lguIds = collect($this->getJson('/api/lgu/earnings')->assertOk()->json())->pluck('id');
        $this->assertTrue($lguIds->contains($localPayment->id));
        $this->assertFalse($lguIds->contains($outsidePayment->id));
        $this->patchJson("/api/lgu/payments/{$outsidePayment->id}/approve")->assertStatus(403);

        Sanctum::actingAs($superAdmin);
        $adminIds = collect($this->getJson('/api/super-admin/earnings')->assertOk()->json())->pluck('id');
        $this->assertTrue($adminIds->contains($localPayment->id));
        $this->assertTrue($adminIds->contains($outsidePayment->id));

        $this->patchJson("/api/super-admin/payments/{$outsidePayment->id}/approve")->assertOk();

        $this->assertDatabaseHas('payments', ['id' => $outsidePayment->id, 'status' => 'released']);
        $this->assertDatabaseHas('settlements', [
            'payment_id' => $outsidePayment->id,
            'municipality_id' => $other->id,
            'approved_by' => $superAdmin->id,
        ]);
        // The seller is told who actually approved it.
        $this->assertStringStartsWith(
            'The platform administrator has approved',
            AppNotification::where('user_id', $outsideSeller->user_id)->where('type', 'earnings_approved')->firstOrFail()->body
        );
    }

    public function test_super_admin_can_reject_earnings_with_a_reason(): void
    {
        $seller = $this->makeSeller();
        $order = $this->makeOrder($this->makeBuyer(), $this->makeListing($seller), ['status' => 'completed']);
        $payment = $this->makePayment($order, ['status' => 'paid_held']);

        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
        $this->patchJson("/api/super-admin/payments/{$payment->id}/reject", [])->assertStatus(422);
        $this->patchJson("/api/super-admin/payments/{$payment->id}/reject", ['reason' => 'Delivery not verified.'])->assertOk();

        $this->assertSame('rejected', $order->fresh()->lgu_review_status);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'paid_held']);
        $this->assertTrue(collect($this->getJson('/api/super-admin/earnings/rejected')->json())->pluck('id')->contains($payment->id));
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

        $this->buyerConfirmsReceipt($order, $seller);

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

        // Confirming twice is refused outright now, so the LGU can never be
        // asked to approve the same earnings twice. Scoped to this LGU admin
        // rather than counting the whole table -- the buyer is notified on
        // delivery too, so a global count measures two behaviours at once.
        Sanctum::actingAs($order->buyer);
        $this->patchJson("/api/orders/{$order->id}/confirm-received")->assertStatus(422);
        $this->assertSame(1, AppNotification::where('user_id', $lguAdmin->id)
            ->where('type', "earnings_pending_approval:{$payment->id}")->count());
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

        $this->buyerConfirmsReceipt($order);

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

        $this->buyerConfirmsReceipt($order);

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

        // LGU-approved (Settlement exists): Seller Share (96% of ₱1000 = ₱960) is available.
        $releasedOrder = $this->makeOrder($buyer, $listing, ['status' => 'completed']);
        $releasedPayment = $this->makePayment($releasedOrder, ['status' => 'released', 'amount' => 1000]);
        $this->makeSettlement($releasedOrder, $releasedPayment);

        // Delivered but not yet LGU-approved: still pending, projected at the Seller Share (96% of ₱500 = ₱480).
        $deliveredOrder = $this->makeOrder($buyer, $listing, ['status' => 'completed']);
        $this->makePayment($deliveredOrder, ['status' => 'paid_held', 'amount' => 500]);

        // Buyer already paid but the order hasn't been delivered yet: earnings
        // must still be recognized in Pending Balance (Step 1 of the corrected
        // workflow) even though delivery (Step 2) hasn't happened. Projected at
        // the Seller Share (96% of ₱200 = ₱192).
        $inTransitOrder = $this->makeOrder($buyer, $listing, ['status' => 'in_transit']);
        $this->makePayment($inTransitOrder, ['status' => 'paid_held', 'amount' => 200]);

        // Not yet actually paid by the buyer (still at checkout): must not count anywhere.
        $unpaidOrder = $this->makeOrder($buyer, $listing, ['status' => 'placed']);
        $this->makePayment($unpaidOrder, ['status' => 'pending', 'amount' => 12345]);

        Sanctum::actingAs($seller->user);
        $response = $this->getJson('/api/seller/wallet');

        $response->assertOk()
            ->assertJsonPath('available_balance', 960)
            ->assertJsonPath('pending_balance', 672)
            ->assertJsonPath('processing_amount', 0)
            ->assertJsonPath('total_earnings', 1632)
            ->assertJsonPath('withdrawn_amount', 0);
    }

    public function test_seller_can_submit_withdrawal_request_within_available_balance(): void
    {
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller);
        $order = $this->makeOrder($buyer, $listing, ['status' => 'completed']);
        $payment = $this->makePayment($order, ['status' => 'released', 'amount' => 200]);
        $this->makeSettlement($order, $payment); // Seller Share: 96% of ₱200 = ₱192.

        Sanctum::actingAs($seller->user);

        $response = $this->postJson('/api/seller/withdrawals', [
            'method' => 'gcash',
            'account_name' => 'Test Seller',
            'account_number' => '09171234567',
            'amount' => 100,
        ]);

        // Platform Payout Fee: 6% of ₱100 = ₱6, so the seller nets ₱94.
        $response->assertCreated()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('platform_fee', '6.00')
            ->assertJsonPath('net_amount', 94);
        $this->assertDatabaseHas('withdrawal_requests', [
            'seller_profile_id' => $seller->id,
            'amount' => 100,
            'platform_fee' => 6,
            'status' => 'pending',
        ]);

        // Available Balance is drawn down by the full requested amount (₱100),
        // not the net -- the fee is realized separately once paid, not here.
        $wallet = $this->getJson('/api/seller/wallet');
        $wallet->assertOk()->assertJsonPath('available_balance', 92);
    }

    public function test_withdrawal_account_number_must_match_the_payout_method(): void
    {
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller);
        $order = $this->makeOrder($buyer, $listing, ['status' => 'completed']);
        $payment = $this->makePayment($order, ['status' => 'released', 'amount' => 200]);
        $this->makeSettlement($order, $payment);

        Sanctum::actingAs($seller->user);
        $request = fn (string $method, string $number) => $this->postJson('/api/seller/withdrawals', [
            'method' => $method, 'account_name' => 'Test Seller', 'account_number' => $number, 'amount' => 10,
        ]);

        // GCash / Maya: an 11-digit mobile number starting with 09.
        foreach (['9954757102', '08954757102', '0995475710', '099547571023', '0995-475-7102'] as $bad) {
            $request('gcash', $bad)->assertStatus(422)
                ->assertJsonPath('errors.account_number.0', PayoutAccount::MOBILE_MESSAGE);
        }
        $request('maya', '12345678901')->assertStatus(422);

        // Bank transfer: 10 to 16 digits, numbers only.
        foreach (['123456789', '12345678901234567', '12345abcde12'] as $bad) {
            $request('bank_transfer', $bad)->assertStatus(422)
                ->assertJsonPath('errors.account_number.0', PayoutAccount::BANK_MESSAGE);
        }

        $request('gcash', '09954757102')->assertCreated();
        $request('maya', '09954757102')->assertCreated();
        $request('bank_transfer', '001234567890')->assertCreated();
    }

    public function test_withdrawal_rejects_negative_or_zero_amount_with_a_clear_message(): void
    {
        $seller = $this->makeSeller();
        Sanctum::actingAs($seller->user);

        foreach ([-50, 0, 'abc'] as $amount) {
            $this->postJson('/api/seller/withdrawals', [
                'method' => 'gcash', 'account_name' => 'Test Seller', 'account_number' => '09171234567', 'amount' => $amount,
            ])->assertStatus(422)->assertJsonPath('errors.amount.0', PayoutAccount::AMOUNT_MESSAGE);
        }
        $this->assertDatabaseCount('withdrawal_requests', 0);
    }

    public function test_seller_cannot_submit_withdrawal_request_exceeding_available_balance(): void
    {
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller);
        $order = $this->makeOrder($buyer, $listing, ['status' => 'completed']);
        $payment = $this->makePayment($order, ['status' => 'released', 'amount' => 100]);
        $this->makeSettlement($order, $payment); // Seller Share: 96% of ₱100 = ₱96.

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
        $payment = $this->makePayment($order, ['status' => 'released', 'amount' => 200]);
        $this->makeSettlement($order, $payment); // Seller Share: 96% of ₱200 = ₱192.

        Sanctum::actingAs($seller->user);
        $this->postJson('/api/seller/withdrawals', [
            'method' => 'gcash',
            'account_name' => 'Test Seller',
            'account_number' => '09171234567',
            'amount' => 100,
        ])->assertCreated();
        $withdrawal = WithdrawalRequest::firstOrFail();

        $before = $this->getJson('/api/seller/wallet');
        $before->assertOk()
            ->assertJsonPath('available_balance', 92)
            ->assertJsonPath('withdrawn_amount', 0);

        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
        $this->patchJson("/api/super-admin/withdrawals/{$withdrawal->id}/approve")->assertOk();
        $this->patchJson("/api/super-admin/withdrawals/{$withdrawal->id}/paid")->assertOk();

        Sanctum::actingAs($seller->user);
        $after = $this->getJson('/api/seller/wallet');
        $after->assertOk()
            // Available Balance must NOT bounce back to 192 -- the ₱100 the
            // seller drew down stays drawn down, regardless of the fee.
            ->assertJsonPath('available_balance', 92)
            // Withdrawn Amount tracks the NET amount actually received (₱100
            // requested - 6% fee of ₱6 = ₱94), not the gross requested amount.
            ->assertJsonPath('withdrawn_amount', 94);
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

        // Withdrawn Amount is net of the platform's payout fee, so once a
        // withdrawal is paid, the fee itself has to be added back in to make
        // the books balance -- it's real money that left Total Earnings but
        // never became cash in the seller's hands (it became Platform Revenue).
        $assertReconciles = function (array $wallet) use ($seller) {
            $feesPaid = (float) WithdrawalRequest::where('seller_profile_id', $seller->id)->where('status', 'paid')->sum('platform_fee');
            $this->assertEquals(
                $wallet['total_earnings'],
                round($wallet['available_balance'] + $wallet['pending_balance'] + $wallet['processing_amount'] + $wallet['withdrawn_amount'] + $feesPaid, 2),
                'Total Earnings must equal Available + Pending + Processing + Withdrawn(net) + Platform Fees Paid.'
            );
        };

        // 1. Buyer places an order (100 pcs @ ₱1 = ₱100 gross) and 2. payment succeeds.
        // The fixed settlement split (96/4) means the seller's earnings are
        // always projected/settled at the ₱96 Seller Share, never the ₱100 gross.
        // The Platform takes nothing at settlement -- only a fee on withdrawal.
        Sanctum::actingAs($buyer);
        $order = $this->postJson('/api/orders', ['fingerling_listing_id' => $listing->id, 'quantity' => 100])->assertCreated()->json();
        $this->postJson("/api/orders/{$order['order_number']}/payment-success")->assertOk();

        // 3. Earnings sit in Pending Balance (Seller Share projection), untouched by anything else yet.
        Sanctum::actingAs($seller->user);
        $wallet = $this->getJson('/api/seller/wallet')->assertOk()->json();
        $this->assertEquals(96, $wallet['pending_balance']);
        $this->assertEquals(0, $wallet['available_balance']);
        $this->assertEquals(0, $wallet['processing_amount']);
        $this->assertEquals(0, $wallet['withdrawn_amount']);
        $this->assertEquals(96, $wallet['total_earnings']);
        $assertReconciles($wallet);

        // 4-5. Seller ships, buyer's delivery is confirmed (order marked completed).
        $this->patchJson("/api/orders/{$order['id']}/status", ['status' => 'confirmed'])->assertOk();
        $this->patchJson("/api/orders/{$order['id']}/status", ['status' => 'in_transit'])->assertOk();
        $this->buyerConfirmsReceipt($order['id'], $seller->user);

        // 6-7. Until LGU approves, Pending must still hold the projected Seller Share and Available must stay at 0.
        $wallet = $this->getJson('/api/seller/wallet')->assertOk()->json();
        $this->assertEquals(96, $wallet['pending_balance']);
        $this->assertEquals(0, $wallet['available_balance']);
        $assertReconciles($wallet);

        $payment = MockPayment::whereHas('order', fn ($q) => $q->where('id', $order['id']))->firstOrFail();
        Sanctum::actingAs($lguAdmin);
        $this->patchJson("/api/lgu/payments/{$payment->id}/approve")->assertOk();

        // 8. Pending -> Available, at the Settlement's frozen Seller Share. Nothing lost, nothing duplicated.
        Sanctum::actingAs($seller->user);
        $wallet = $this->getJson('/api/seller/wallet')->assertOk()->json();
        $this->assertEquals(0, $wallet['pending_balance']);
        $this->assertEquals(96, $wallet['available_balance']);
        $this->assertEquals(96, $wallet['total_earnings']);
        $assertReconciles($wallet);
        $this->assertDatabaseHas('settlements', [
            'order_id' => $order['id'],
            'gross_amount' => 100,
            'seller_share' => 96,
            'lgu_share' => 4,
            'platform_share' => 0,
        ]);

        // Nothing has been withdrawn yet, so Platform Revenue must still be zero.
        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
        $this->assertEquals(0, $this->getJson('/api/super-admin/dashboard')->json('platform_revenue.total_platform_revenue'));

        // 9. Seller requests a partial payout.
        Sanctum::actingAs($seller->user);
        $withdrawalResponse = $this->postJson('/api/seller/withdrawals', [
            'method' => 'gcash', 'account_name' => 'Seller', 'account_number' => '09000000000', 'amount' => 54,
        ])->assertCreated()->json();
        // Platform Payout Fee: 6% of ₱54 = ₱3.24, so the seller nets ₱50.76.
        $this->assertEquals(3.24, $withdrawalResponse['platform_fee']);
        $withdrawal = WithdrawalRequest::where('seller_profile_id', $seller->id)->firstOrFail();

        // While requested-but-unpaid, the ₱54 must show as Processing, NOT vanish from the total.
        $wallet = $this->getJson('/api/seller/wallet')->assertOk()->json();
        $this->assertEquals(42, $wallet['available_balance']);
        $this->assertEquals(54, $wallet['processing_amount']);
        $this->assertEquals(96, $wallet['total_earnings']);
        $assertReconciles($wallet);

        // Same must hold once the Super Admin approves it but hasn't paid it yet.
        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
        $this->patchJson("/api/super-admin/withdrawals/{$withdrawal->id}/approve")->assertOk();
        Sanctum::actingAs($seller->user);
        $wallet = $this->getJson('/api/seller/wallet')->assertOk()->json();
        $this->assertEquals(42, $wallet['available_balance']);
        $this->assertEquals(54, $wallet['processing_amount']);
        $this->assertEquals(0, $wallet['withdrawn_amount']);
        $assertReconciles($wallet);
        // Still not realized -- "approved" is not "paid" yet.
        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
        $this->assertEquals(0, $this->getJson('/api/super-admin/dashboard')->json('platform_revenue.total_platform_revenue'));

        // 10. Super Admin marks it paid: Processing -> Withdrawn (net of the fee). Available must NOT change again.
        $this->patchJson("/api/super-admin/withdrawals/{$withdrawal->id}/paid")->assertOk();
        Sanctum::actingAs($seller->user);
        $wallet = $this->getJson('/api/seller/wallet')->assertOk()->json();
        $this->assertEquals(42, $wallet['available_balance']);
        $this->assertEquals(0, $wallet['processing_amount']);
        // ₱54 requested - ₱3.24 platform fee = ₱50.76 actually received.
        $this->assertEquals(50.76, $wallet['withdrawn_amount']);
        $this->assertEquals(96, $wallet['total_earnings']);
        $assertReconciles($wallet);

        // Platform Revenue is now realized: exactly the ₱3.24 fee on the paid withdrawal.
        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
        $this->assertEquals(3.24, $this->getJson('/api/super-admin/dashboard')->json('platform_revenue.total_platform_revenue'));
    }

    /**
     * Marketplace Revenue Sharing: for a seller with NO prior earnings
     * history, a fresh ₱120 order must raise Available Balance by only the
     * Seller Share (96% of ₱120 = ₱115.20) after LGU approval -- never the
     * full ₱120 gross amount, since the LGU Share is carved out first (see
     * App\Support\CommissionCalculator). The Platform takes nothing here at
     * all -- its revenue comes later, from a fee on withdrawal. The buyer is
     * still charged, and the payment still captures, the full gross amount;
     * only the wallet crediting is split.
     */
    public function test_lgu_approved_earnings_credit_only_the_sellers_share_not_the_full_gross_amount(): void
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
        $this->assertEquals(115.2, $wallet['pending_balance'], 'Pending Balance must project the Seller Share (96% of ₱120), not the gross amount.');
        $this->assertEquals(0, $wallet['available_balance']);

        $this->buyerConfirmsReceipt($order['id'], $seller->user);

        $payment = MockPayment::whereHas('order', fn ($q) => $q->where('id', $order['id']))->firstOrFail();
        $this->assertEquals(120, $payment->amount, 'The captured payment amount must equal the gross order total -- the buyer pays the full price.');

        Sanctum::actingAs($lguAdmin);
        $this->patchJson("/api/lgu/payments/{$payment->id}/approve")->assertOk();

        Sanctum::actingAs($seller->user);
        $wallet = $this->getJson('/api/seller/wallet')->assertOk()->json();
        $this->assertEquals(0, $wallet['pending_balance']);
        $this->assertEquals(115.2, $wallet['available_balance'], 'Available Balance must equal only the Seller Share (96% of ₱120 = ₱115.20).');
        $this->assertEquals(115.2, $wallet['total_earnings']);

        $this->assertDatabaseHas('settlements', [
            'order_id' => $order['id'],
            'gross_amount' => 120,
            'seller_share' => 115.2,
            'lgu_share' => 4.8,
            'platform_share' => 0,
            'seller_percent' => 96,
            'lgu_percent' => 4,
            'platform_percent' => 0,
        ]);
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

    public function test_seller_can_mark_all_notifications_read_without_affecting_other_sellers(): void
    {
        $seller = $this->makeSeller();
        $otherSeller = $this->makeSeller();

        foreach (range(1, 3) as $i) {
            AppNotification::create([
                'user_id' => $seller->user_id,
                'type' => 'payment_released',
                'title' => "Notification {$i}",
                'body' => 'Test notification body.',
            ]);
        }
        $otherNotification = AppNotification::create([
            'user_id' => $otherSeller->user_id,
            'type' => 'payment_released',
            'title' => "Other seller's notification",
            'body' => 'Test notification body.',
        ]);

        Sanctum::actingAs($seller->user);

        $this->getJson('/api/seller/notifications')->assertJsonCount(3);

        $this->patchJson('/api/seller/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('updated', 3);

        $this->getJson('/api/seller/notifications')->assertJsonCount(0);
        $this->assertNull($otherNotification->fresh()->read_at);
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

    public function test_ai_assistant_is_available_to_every_authenticated_role(): void
    {
        config(['services.gemini.api_key' => null]);

        Sanctum::actingAs($this->makeBuyer());
        $this->postJson('/api/ai-assistant/ask', ['question' => 'Hi'])->assertCreated();

        Sanctum::actingAs($this->makeSeller()->user);
        $this->postJson('/api/ai-assistant/ask', ['question' => 'Hi'])->assertCreated();

        Sanctum::actingAs($this->makeLguAdmin());
        $this->postJson('/api/ai-assistant/ask', ['question' => 'Hi'])->assertCreated();

        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
        $this->postJson('/api/ai-assistant/ask', ['question' => 'Hi'])->assertCreated();
    }

    public function test_ai_assistant_rejects_unauthenticated_requests(): void
    {
        $this->postJson('/api/ai-assistant/ask', ['question' => 'Hi'])->assertStatus(401);
    }

    public function test_ai_assistant_history_returns_only_authenticated_buyers_own_conversations(): void
    {
        $buyer = $this->makeBuyer();
        $otherBuyer = $this->makeBuyer();

        \App\Models\AiConversation::create(['user_id' => $buyer->id, 'language' => 'English', 'message' => 'First question', 'response' => 'First answer']);
        \App\Models\AiConversation::create(['user_id' => $buyer->id, 'language' => 'English', 'message' => 'Second question', 'response' => 'Second answer']);
        \App\Models\AiConversation::create(['user_id' => $otherBuyer->id, 'language' => 'English', 'message' => 'Someone elses question', 'response' => 'Someone elses answer']);

        Sanctum::actingAs($buyer);
        $response = $this->getJson('/api/ai-assistant/history');

        $response->assertOk()->assertJsonCount(2);
        $this->assertSame('First question', $response->json('0.message'));
        $this->assertSame('Second question', $response->json('1.message'));
        $this->assertFalse(collect($response->json())->contains('message', 'Someone elses question'));
    }

    public function test_gemini_fallback_answers_marketplace_questions_when_provider_is_unavailable(): void
    {
        config(['services.gemini.api_key' => null]);
        $service = new \App\Services\GeminiService();

        $this->assertStringContainsString('Chat Seller', $service->answer('How do I contact a seller?', 'English'));
        $this->assertStringContainsString('PayMongo', $service->answer('How do I buy fingerlings?', 'English'));
        $this->assertStringContainsString('review', strtolower($service->answer('How do I leave a review?', 'English')));
        $this->assertStringContainsString('wallet', strtolower($service->answer('What is the wallet?', 'English')));
    }

    public function test_ai_intent_classifier_categorizes_messages_correctly(): void
    {
        $cases = [
            'How do I buy fingerlings from a listing?' => 'Marketplace',
            'What is the wallet for?' => 'Marketplace',
            'How do refunds work?' => 'Payments',
            'How do I pay for my order?' => 'Payments',
            'How does delivery work?' => 'Delivery',
            'How do I leave a review for a seller?' => 'Reviews',
            'How do I contact a seller?' => 'Messaging',
            'Is this seller verified and trustworthy?' => 'Seller Information',
            'What species is good for a beginner?' => 'Fish Care',
            'How often should I feed my fingerlings?' => 'Fish Care',
            'Where can I track my orders?' => 'Orders',
            // Two-pass matching: a specific phrase wins over a broad single word
            // in an earlier topic, so these no longer shadow to Marketplace.
            'How do I create a listing?' => 'Listings',
            'How do I approve a listing?' => 'Listings',
            'How do I sell fingerlings?' => 'Listings',
            'How do I withdraw my earnings?' => 'Withdrawals',
            'How do I add items to my cart?' => 'Marketplace',
            'How do I leave feedback?' => 'Reviews',
            'Why are my fingerlings dying?' => 'Fish Care',
            'Hello there!' => 'Greeting',
            'Kumusta!' => 'Greeting',
            'Who won the last World Cup?' => 'Unknown',
            'Can you write me a Python script?' => 'Unknown',
            'What is the capital of France?' => 'Unknown',
            'Help me with my math homework' => 'Unknown',
        ];

        foreach ($cases as $message => $expectedCategory) {
            $result = \App\Support\AiIntentClassifier::classify($message);
            $this->assertSame($expectedCategory, $result['category'], "Expected \"{$message}\" to classify as {$expectedCategory}, got {$result['category']}.");
        }
    }

    public function test_ai_intent_classifier_prioritizes_a_real_question_over_an_opening_greeting(): void
    {
        $result = \App\Support\AiIntentClassifier::classify('Hi, how do I buy fingerlings?');

        $this->assertSame('Marketplace', $result['category']);
    }

    public function test_ai_intent_classifier_does_not_misclassify_fish_related_words_as_greetings(): void
    {
        // "fish" contains the substring "hi" -- must not trip the greeting pattern.
        $result = \App\Support\AiIntentClassifier::classify('Tell me about fish farming');

        $this->assertNotSame('Greeting', $result['category']);
        // "fish farming" is now a recognized Fish Care topic, not an off-topic refusal.
        $this->assertSame('Fish Care', $result['category']);
    }

    /**
     * Keyword matching anchors on a word boundary, so a short keyword like
     * "rate" no longer fires on the middle of an unrelated word ("accurate").
     */
    public function test_ai_intent_classifier_ignores_keywords_hidden_inside_unrelated_words(): void
    {
        foreach ([
            'Is the listed weight accurate?',   // "rate" inside "accurate" must NOT be Reviews
            'Can you generate a summary?',       // "rate" inside "generate" must NOT be Reviews
        ] as $message) {
            $this->assertNotSame('Reviews', \App\Support\AiIntentClassifier::classify($message)['category'], $message);
        }
    }

    public function test_gemini_service_politely_refuses_off_topic_questions_without_fabricating_answers(): void
    {
        $service = new \App\Services\GeminiService();

        foreach ([
            'Who won the last World Cup?',
            'What do you think about the upcoming election?',
            'Can you write me a Python script to sort a list?',
            'Help me with my algebra homework',
        ] as $offTopicQuestion) {
            $response = $service->answer($offTopicQuestion, 'English');
            $this->assertStringContainsString('AbaiMarket fisheries marketplace', $response);
            $this->assertStringNotContainsString('World Cup', $response);
            $this->assertStringNotContainsString('Python', $response);
        }
    }

    /**
     * Farmers don't phrase questions the way a keyword list expects. Every
     * message below is a real fish-farming question that matched no curated
     * keyword and was therefore refused outright as "Unknown" -- the assistant
     * told a farmer with dying stock that it couldn't help. They now reach the
     * Fish Care path instead.
     */
    public function test_naturally_phrased_farming_questions_are_no_longer_refused(): void
    {
        foreach ([
            'my tilapia have white spots on their fins',
            'what pH is right for bangus?',
            'can I put hito and tilapia in one pond?',
            'how many fingerlings per square meter?',
            'my pond water turned murky',
            'what should I feed shrimp',
            'how do I raise dissolved oxygen',
            // The same urgency, in the languages farmers actually use.
            'bakit nangamatay ang mga isda ko?',
            'nganong naggasping ang akong bangus?',
        ] as $question) {
            $result = \App\Support\AiIntentClassifier::classify($question);
            $this->assertSame('Fish Care', $result['category'], "Expected \"{$question}\" to reach Fish Care, got {$result['category']}.");
        }
    }

    /**
     * Widening the gate must not widen it onto everything. Off-topic messages
     * share no vocabulary with fish farming and still refuse -- including the
     * near-misses that short abbreviations invite: "ph" must not fire on
     * "phone"/"photo", and no two-letter term may match ordinary English.
     */
    public function test_widening_the_farming_gate_did_not_let_off_topic_questions_through(): void
    {
        foreach ([
            'Who won the last World Cup?',
            'What do you think about the upcoming election?',
            'Can you write me a Python script to sort a list?',
            'Help me with my algebra homework',
            'What is the capital of France?',
            'Can I use my phone to take photos?',
        ] as $question) {
            $result = \App\Support\AiIntentClassifier::classify($question);
            $this->assertSame('Unknown', $result['category'], "Expected \"{$question}\" to stay Unknown, got {$result['category']}.");
        }
    }

    /**
     * A fish-farming question is the one case where Gemini answers from its own
     * domain knowledge rather than paraphrasing a scripted paragraph. The
     * instruction it receives must therefore NOT carry the strict "use only
     * this context" clause -- and must still forbid it from asserting anything
     * about AbaiMarket itself, which is the grounding guarantee that actually
     * matters.
     */
    public function test_a_farming_question_is_answered_as_an_aquaculture_adviser(): void
    {
        config(['services.gemini.api_key' => 'test-key']);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'White spots are usually ich. Raise the water temperature slowly and improve aeration.']]]]],
            ], 200),
        ]);

        Sanctum::actingAs($this->makeBuyer());

        $this->postJson('/api/ai-assistant/ask', ['question' => 'my tilapia have white spots on their fins'])
            ->assertCreated()
            ->assertJsonFragment(['response' => 'White spots are usually ich. Raise the water temperature slowly and improve aeration.']);

        Http::assertSent(function ($request) {
            $instruction = $request->data()['systemInstruction']['parts'][0]['text'];

            return str_contains($instruction, 'fish-farming question')
                // Free to use real aquaculture knowledge...
                && ! str_contains($instruction, 'Use ONLY the following application knowledge')
                // ...but never to invent AbaiMarket facts, and never to let a
                // serious die-off rest on a chat answer alone.
                && str_contains($instruction, 'Do NOT state facts about the AbaiMarket app')
                && str_contains($instruction, 'BFAR');
        });
    }

    /**
     * An app question keeps the original strict grounding -- opening up fish
     * care must not have opened up anything else.
     */
    public function test_an_app_question_is_still_strictly_grounded(): void
    {
        config(['services.gemini.api_key' => 'test-key']);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'Tap Chat Seller on the listing.']]]]],
            ], 200),
        ]);

        Sanctum::actingAs($this->makeBuyer());

        $this->postJson('/api/ai-assistant/ask', ['question' => 'How do I contact a seller?'])->assertCreated();

        Http::assertSent(function ($request) {
            $instruction = $request->data()['systemInstruction']['parts'][0]['text'];

            return str_contains($instruction, 'Use ONLY the following application knowledge')
                && str_contains($instruction, 'never invent or estimate anything beyond it');
        });
    }

    /**
     * With the provider down, an open farming question has no scripted answer
     * to fall back to. It must still get fish-care guidance rather than the
     * off-topic refusal it would have received before.
     */
    public function test_an_open_farming_question_falls_back_to_fish_care_not_a_refusal(): void
    {
        config(['services.gemini.api_key' => null]);
        $service = new \App\Services\GeminiService();

        $answer = $service->answer('my tilapia have white spots on their fins', 'English');

        $this->assertStringContainsString('fish-farming', strtolower($answer));
        $this->assertStringNotContainsString("can't answer unrelated general knowledge", $answer);
        $this->assertSame('Fish Care', $service->lastCategory());
    }

    /**
     * The assistant used to answer "there is no mention of any fees" when a
     * seller asked what withdrawing costs -- faithful to its scripted context,
     * and the exact opposite of what CommissionCalculator deducts. The fee now
     * lives in that context for every role that can be told about it.
     */
    /**
     * "How do I know if my fish are sick?" is the question a farmer asks
     * BEFORE they know the diagnosis, so naming conditions (ich, fin rot) in
     * the keyword list never caught it -- and 'sick fish' only fired in that
     * exact word order, missing "fish IS sick" and "fish ARE sick".
     */
    public function test_a_farmer_can_ask_whether_their_fish_are_sick_in_any_phrasing(): void
    {
        foreach ([
            'how to know if the fish is sick',
            'how do I know if my fish are sick',
            'is my fish sick?',
            'what are the signs of a sick fish',
            'how to tell if fish is healthy',
            'paano malaman kung may sakit ang isda',
            'unsaon nako mahibaw-an kung naay sakit ang isda',
        ] as $question) {
            $result = \App\Support\AiIntentClassifier::classify($question);
            $this->assertSame('Fish Care', $result['category'], "Expected \"{$question}\" to reach Fish Care, got {$result['category']}.");
        }
    }

    /**
     * Guards the trap that catching those phrasings first created: keywords
     * describing question FORM rather than subject. 'how to know if' matched
     * any question shaped that way, and because the phrase pass runs across
     * every topic before any single word, it outranked the real topic --
     * sending withdrawal and payment questions to Fish Care.
     */
    public function test_app_questions_phrased_as_how_do_i_know_if_still_reach_their_own_topic(): void
    {
        foreach ([
            'how do I know if my order was shipped' => 'Orders',
            'how do I know if my withdrawal was approved' => 'Withdrawals',
            'how do i know if my payment went through' => 'Payments',
        ] as $question => $expected) {
            $result = \App\Support\AiIntentClassifier::classify($question);
            $this->assertSame($expected, $result['category'], "Expected \"{$question}\" to stay {$expected}, got {$result['category']}.");
        }
    }

    /**
     * The offline answer has to stand on its own: when the provider is down
     * this text IS the answer, and a farmer standing over a pond needs
     * something concrete to look for, not an invitation to rephrase.
     */
    public function test_the_offline_sick_fish_answer_lists_real_warning_signs(): void
    {
        config(['services.gemini.api_key' => null]);
        $service = new \App\Services\GeminiService();

        $answer = $service->answer('how to know if the fish is sick', 'English');

        $this->assertStringContainsString('gasping', strtolower($answer));
        $this->assertStringContainsString('ammonia', strtolower($answer));
        $this->assertStringContainsString('BFAR', $answer);
        // Not the generic "ask me a specific question" prompt it used to give.
        $this->assertStringNotContainsString('Ask a specific question', $answer);

        $tagalog = $service->answer('paano malaman kung may sakit ang isda', 'Tagalog');
        $this->assertStringContainsString('malusog', strtolower($tagalog));
    }

    /**
     * "Recommend" means two different things. Because the recommendation
     * engine runs ahead of the intent classifier, "what feeds do you recommend
     * for bangus?" was answered with a sales ranking -- "Your species
     * performance, ranked by revenue" -- instead of advice about feed. The
     * engine now declines husbandry questions, while keeping the business ones
     * it exists for.
     */
    public function test_asking_for_feed_advice_is_not_answered_with_a_sales_ranking(): void
    {
        $seller = $this->makeSeller();

        foreach ([
            'what feeds do you recommend for bangus?',
            'can you suggest a treatment for sick tilapia?',
            'what do you recommend for water quality?',
        ] as $question) {
            $this->assertNull(
                \App\Support\AiRecommendationEngine::resolve($question, $seller->user, null),
                "\"{$question}\" is husbandry advice and must fall through to Fish Care."
            );
            $this->assertSame('Fish Care', \App\Support\AiIntentClassifier::classify($question)['category']);
        }

        // The business sense still belongs to the engine.
        foreach ([
            'what do you recommend I restock?',
            'which species do you recommend for my listings?',
        ] as $question) {
            $this->assertNotNull(
                \App\Support\AiRecommendationEngine::resolve($question, $seller->user, null),
                "\"{$question}\" is a business question and must stay with the recommendation engine."
            );
        }
    }

    /**
     * The storefront's "Partnered LGUs" section answers "is my town covered?".
     * It must list only municipalities that really have an LGU partner -- the
     * `municipalities` table is the seeded list of every Cebu municipality and
     * backs the registration dropdown, so returning all of them would claim
     * coverage the platform does not have.
     */
    /**
     * Completing an order is what releases its payment into the LGU earnings
     * queue and, from there, to the seller. A seller who could mark their own
     * delivery complete could therefore start their own payout without the
     * buyer ever confirming anything arrived -- so that decision belongs to
     * whoever paid.
     */
    public function test_a_seller_can_no_longer_mark_their_own_delivery_complete(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        $listing = $this->makeListing($seller);
        $order = $this->makeOrder($buyer, $listing, ['status' => 'in_transit']);
        $this->makePayment($order, ['status' => 'paid_held']);

        Sanctum::actingAs($seller->user);
        $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'completed'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        // The seller's own confirm route does not exist for them either.
        $this->patchJson("/api/orders/{$order->id}/confirm-received")->assertStatus(403);

        $this->assertSame('in_transit', $order->fresh()->status);

        // The buyer can, and doing so is what notifies the LGU.
        Sanctum::actingAs($buyer);
        $this->patchJson("/api/orders/{$order->id}/confirm-received")->assertOk();
        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_a_buyer_cannot_confirm_someone_elses_order(): void
    {
        $seller = $this->makeSeller();
        $order = $this->makeOrder($this->makeBuyer(), $this->makeListing($seller), ['status' => 'in_transit']);
        $this->makePayment($order, ['status' => 'paid_held']);

        Sanctum::actingAs($this->makeBuyer());
        $this->patchJson("/api/orders/{$order->id}/confirm-received")->assertStatus(403);
        $this->assertSame('in_transit', $order->fresh()->status);
    }

    /**
     * The release valve for the failure mode the change above introduces: a
     * buyer who never confirms would otherwise freeze the seller's money in
     * escrow forever, since only a completed order can reach the earnings
     * queue. Deliberately a human decision, not a timer.
     */
    public function test_an_lgu_admin_can_mark_an_order_received_for_a_silent_buyer(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $seller = $this->makeSeller(
            ['municipality_id' => $lguAdmin->municipality_id],
            ['municipality_id' => $lguAdmin->municipality_id]
        );
        $order = $this->makeOrder($this->makeBuyer(), $this->makeListing($seller), ['status' => 'in_transit']);
        $payment = $this->makePayment($order, ['status' => 'paid_held']);

        Sanctum::actingAs($lguAdmin);
        $this->patchJson("/api/lgu/orders/{$order->id}/mark-delivered")->assertOk();

        $this->assertSame('completed', $order->fresh()->status);
        // It really did enter the earnings queue -- that is the whole point.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $lguAdmin->id,
            'type' => "earnings_pending_approval:{$payment->id}",
        ]);
        // And it is attributed, not silent.
        $this->assertDatabaseHas('activity_logs', [
            'actor_id' => $lguAdmin->id,
            'action' => 'order_marked_received_by_admin',
            'reference_number' => $order->order_number,
        ]);
    }

    public function test_lgu_orders_list_only_contains_orders_from_their_municipality(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $other = Municipality::where('id', '!=', $lguAdmin->municipality_id)->firstOrFail();
        $localSeller = $this->makeSeller(
            ['municipality_id' => $lguAdmin->municipality_id],
            ['municipality_id' => $lguAdmin->municipality_id]
        );
        $outsideSeller = $this->makeSeller(['municipality_id' => $other->id], ['municipality_id' => $other->id]);
        $local = $this->makeOrder($this->makeBuyer(), $this->makeListing($localSeller), ['status' => 'in_transit']);
        $outside = $this->makeOrder($this->makeBuyer(), $this->makeListing($outsideSeller), ['status' => 'in_transit']);

        Sanctum::actingAs($lguAdmin);
        $ids = collect($this->getJson('/api/lgu/orders')->assertOk()->json())->pluck('id');

        $this->assertTrue($ids->contains($local->id));
        $this->assertFalse($ids->contains($outside->id));
    }

    public function test_an_lgu_admin_cannot_mark_an_order_received_outside_their_municipality(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $other = Municipality::where('id', '!=', $lguAdmin->municipality_id)->firstOrFail();
        $seller = $this->makeSeller(['municipality_id' => $other->id], ['municipality_id' => $other->id]);
        $order = $this->makeOrder($this->makeBuyer(), $this->makeListing($seller), ['status' => 'in_transit']);
        $this->makePayment($order, ['status' => 'paid_held']);

        Sanctum::actingAs($lguAdmin);
        $this->patchJson("/api/lgu/orders/{$order->id}/mark-delivered")->assertStatus(403);
        $this->assertSame('in_transit', $order->fresh()->status);

        // The Super Admin is unscoped, for a municipality with no active admin.
        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
        $this->patchJson("/api/super-admin/orders/{$order->id}/mark-delivered")->assertOk();
        $this->assertSame('completed', $order->fresh()->status);
    }

    /**
     * The harvest projection multiplies a real purchase by survival and price
     * assumptions. With nothing bought, every figure it produces is a multiple
     * of zero -- which renders as a real (and terrible) forecast rather than
     * "no data yet", so the calculator is locked until the buyer has ordered.
     */
    public function test_the_earnings_calculator_is_locked_until_the_buyer_has_an_order(): void
    {
        $buyer = $this->makeBuyer();
        Sanctum::actingAs($buyer);

        $this->assertFalse(
            $this->getJson('/api/buyer/analytics?period=yearly')->assertOk()->json('investment.has_orders'),
            'A buyer who has never ordered must not get the calculator.'
        );

        // Any order unlocks it -- not just a completed one. The fish are bought
        // and paid for; waiting on delivery does not make the projection less
        // meaningful, and the flag must not depend on the selected period.
        $seller = $this->makeSeller();
        $this->makeOrder($buyer, $this->makeListing($seller), ['status' => 'in_transit']);

        $this->assertTrue($this->getJson('/api/buyer/analytics?period=yearly')->assertOk()->json('investment.has_orders'));
        $this->assertTrue($this->getJson('/api/buyer/analytics?period=weekly')->assertOk()->json('investment.has_orders'));
    }

    /**
     * A rejected earnings review used to be the end of the conversation: the
     * seller was told the reason and had no way to answer it. The LGU could
     * reopen a rejection, but nothing ever prompted them to. Disputes close
     * that loop in the direction it was missing.
     */
    public function test_a_seller_can_dispute_rejected_earnings_and_the_lgu_can_accept_it(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $seller = $this->makeSeller(
            ['municipality_id' => $lguAdmin->municipality_id],
            ['municipality_id' => $lguAdmin->municipality_id]
        );
        $order = $this->makeOrder($this->makeBuyer(), $this->makeListing($seller), ['status' => 'in_transit']);
        $payment = $this->makePayment($order, ['status' => 'paid_held']);

        $this->buyerConfirmsReceipt($order, $lguAdmin);
        $this->patchJson("/api/lgu/payments/{$payment->id}/reject", ['reason' => 'Delivery photo does not match the listing.'])->assertOk();
        $this->assertSame('rejected', $order->fresh()->lgu_review_status);

        // The seller explains.
        Sanctum::actingAs($seller->user);
        $this->postJson("/api/orders/{$order->id}/dispute-earnings", ['reason' => 'The photo was of the second batch; here is the delivery receipt.'])
            ->assertCreated()
            ->assertJsonPath('status', 'open');

        // No duplicate while it is still open.
        $this->postJson("/api/orders/{$order->id}/dispute-earnings", ['reason' => 'Again.'])->assertStatus(422);

        // The LGU sees it and accepts.
        Sanctum::actingAs($lguAdmin);
        $dispute = collect($this->getJson('/api/lgu/disputes')->assertOk()->json())->firstWhere('status', 'open');
        $this->assertNotNull($dispute);

        $this->patchJson("/api/lgu/disputes/{$dispute['id']}/accept", ['note' => 'Receipt checks out.'])
            ->assertOk()
            ->assertJsonPath('status', 'accepted');

        // Accepting REOPENS the review -- it does not approve the earnings.
        // The money must still be sitting in escrow, undecided.
        $this->assertNull($order->fresh()->lgu_review_status);
        $this->assertSame('paid_held', $payment->fresh()->status);
    }

    public function test_rejecting_a_dispute_leaves_the_original_decision_standing(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $seller = $this->makeSeller(
            ['municipality_id' => $lguAdmin->municipality_id],
            ['municipality_id' => $lguAdmin->municipality_id]
        );
        $order = $this->makeOrder($this->makeBuyer(), $this->makeListing($seller), ['status' => 'in_transit']);
        $payment = $this->makePayment($order, ['status' => 'paid_held']);

        $this->buyerConfirmsReceipt($order, $lguAdmin);
        $this->patchJson("/api/lgu/payments/{$payment->id}/reject", ['reason' => 'Wrong species delivered.'])->assertOk();

        Sanctum::actingAs($seller->user);
        $id = $this->postJson("/api/orders/{$order->id}/dispute-earnings", ['reason' => 'It was the right species.'])
            ->assertCreated()->json('id');

        Sanctum::actingAs($lguAdmin);
        // A reason is mandatory to reject -- an unexplained refusal is exactly
        // what made the original rejection disputable.
        $this->patchJson("/api/lgu/disputes/{$id}/reject")->assertStatus(422);
        $this->patchJson("/api/lgu/disputes/{$id}/reject", ['note' => 'Buyer confirmed the species was wrong.'])
            ->assertOk()
            ->assertJsonPath('status', 'rejected');

        $this->assertSame('rejected', $order->fresh()->lgu_review_status);
        $this->assertSame('Wrong species delivered.', $order->fresh()->lgu_review_reason);

        // Already resolved -- it cannot be decided twice.
        $this->patchJson("/api/lgu/disputes/{$id}/accept")->assertStatus(422);
    }

    /**
     * The same mechanism on a rejected withdrawal. Accepting puts it back in
     * the payout queue as 'pending' -- the state a fresh request starts in --
     * rather than approving or paying it.
     */
    public function test_a_seller_can_dispute_a_rejected_withdrawal_and_acceptance_requeues_it(): void
    {
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        $seller = $this->makeSeller();
        $order = $this->makeOrder($this->makeBuyer(), $this->makeListing($seller), ['status' => 'completed']);
        $payment = $this->makePayment($order, ['status' => 'released', 'amount' => 200]);
        $this->makeSettlement($order, $payment); // Seller Share: 96% of 200 = 192.

        Sanctum::actingAs($seller->user);
        $withdrawalId = $this->postJson('/api/seller/withdrawals', [
            'method' => 'gcash', 'account_name' => 'Test Seller', 'account_number' => '09170000000', 'amount' => 50,
        ])->assertCreated()->json('id');

        Sanctum::actingAs($superAdmin);
        $this->patchJson("/api/super-admin/withdrawals/{$withdrawalId}/reject", ['reason' => 'Account name does not match.'])->assertOk();

        Sanctum::actingAs($seller->user);
        $disputeId = $this->postJson("/api/withdrawals/{$withdrawalId}/dispute", ['reason' => 'It is my married name; ID attached.'])
            ->assertCreated()->json('id');

        Sanctum::actingAs($superAdmin);
        $this->patchJson("/api/super-admin/disputes/{$disputeId}/accept")->assertOk();

        $withdrawal = WithdrawalRequest::findOrFail($withdrawalId);
        $this->assertSame('pending', $withdrawal->status);
        $this->assertNull($withdrawal->rejection_reason);
    }

    public function test_only_a_rejected_item_can_be_disputed_and_only_by_its_owner(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $seller = $this->makeSeller(
            ['municipality_id' => $lguAdmin->municipality_id],
            ['municipality_id' => $lguAdmin->municipality_id]
        );
        $order = $this->makeOrder($this->makeBuyer(), $this->makeListing($seller), ['status' => 'in_transit']);
        $this->makePayment($order, ['status' => 'paid_held']);
        $this->buyerConfirmsReceipt($order);

        // Nothing has been rejected, so there is nothing to answer.
        Sanctum::actingAs($seller->user);
        $this->postJson("/api/orders/{$order->id}/dispute-earnings", ['reason' => 'Pre-emptive.'])->assertStatus(422);

        // And another seller cannot appeal on this seller's behalf.
        Sanctum::actingAs($this->makeSeller()->user);
        $this->postJson("/api/orders/{$order->id}/dispute-earnings", ['reason' => 'Not mine.'])->assertStatus(403);
    }

    public function test_an_lgu_admin_only_sees_disputes_from_their_own_municipality(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $other = Municipality::where('id', '!=', $lguAdmin->municipality_id)->firstOrFail();
        $outsideSeller = $this->makeSeller(['municipality_id' => $other->id], ['municipality_id' => $other->id]);

        $order = $this->makeOrder($this->makeBuyer(), $this->makeListing($outsideSeller), ['status' => 'in_transit']);
        $payment = $this->makePayment($order, ['status' => 'paid_held']);
        $this->buyerConfirmsReceipt($order);
        $order->forceFill(['lgu_review_status' => 'rejected', 'lgu_review_reason' => 'Out of area.'])->save();

        Sanctum::actingAs($outsideSeller->user);
        $id = $this->postJson("/api/orders/{$order->id}/dispute-earnings", ['reason' => 'Please reconsider.'])
            ->assertCreated()->json('id');

        Sanctum::actingAs($lguAdmin);
        $this->assertCount(0, $this->getJson('/api/lgu/disputes')->assertOk()->json());
        $this->patchJson("/api/lgu/disputes/{$id}/accept")->assertStatus(403);

        // The Super Admin is the unscoped fallback and can resolve it.
        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
        $this->assertCount(1, $this->getJson('/api/super-admin/disputes')->assertOk()->json());
        $this->patchJson("/api/super-admin/disputes/{$id}/accept")->assertOk();
        $this->assertSame('paid_held', $payment->fresh()->status);
    }

    /**
     * Cancelling was silent: the buyer was told their order was gone and never
     * why. On a PAID order this is the one seller action that sends money back
     * through the refund queue, so it belongs on the record.
     */
    public function test_a_seller_must_give_a_reason_when_cancelling_an_order(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        $order = $this->makeOrder($buyer, $this->makeListing($seller), ['status' => 'placed']);
        $this->makePayment($order, ['status' => 'checkout_created']);

        Sanctum::actingAs($seller->user);
        $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'cancelled'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cancellation_reason');
        $this->assertSame('placed', $order->fresh()->status);

        $this->patchJson("/api/orders/{$order->id}/status", [
            'status' => 'cancelled',
            'cancellation_reason' => 'Our pond had a fish kill overnight.',
        ])->assertOk();

        $order->refresh();
        $this->assertSame('cancelled', $order->status);
        $this->assertSame('Our pond had a fish kill overnight.', $order->cancellation_reason);

        // The buyer is told why, not just that it happened.
        $notification = AppNotification::where('user_id', $buyer->id)->where('type', 'order_cancelled')->firstOrFail();
        $this->assertStringContainsString('Our pond had a fish kill overnight.', $notification->body);
    }

    public function test_a_reason_is_only_required_when_cancelling(): void
    {
        $seller = $this->makeSeller();
        $order = $this->makeOrder($this->makeBuyer(), $this->makeListing($seller), ['status' => 'placed']);
        $this->makePayment($order, ['status' => 'paid_held']);

        Sanctum::actingAs($seller->user);
        $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'confirmed'])->assertOk();

        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertNull($order->fresh()->cancellation_reason);
    }

    public function test_the_public_partner_list_shows_only_municipalities_with_an_active_lgu_admin(): void
    {
        // Named explicitly rather than picked with whereDoesntHave()->first():
        // that has no ORDER BY, so it once returned the very municipality this
        // test had just made covered.
        $covered = Municipality::where('name', 'Cordova')->firstOrFail();
        $uncovered = Municipality::where('name', 'Compostela')->firstOrFail();
        $this->assertSame(0, $uncovered->lguAdmins()->count(), 'Precondition: Compostela is seeded without an LGU Admin.');

        $this->makeLguAdmin(['municipality_id' => $covered->id]);
        $seller = $this->makeSeller(
            ['municipality_id' => $covered->id],
            ['municipality_id' => $covered->id]
        );

        // Public -- no token at all.
        $body = $this->getJson('/api/partner-municipalities')->assertOk()->json();
        $names = collect($body)->pluck('name');

        $this->assertTrue($names->contains($covered->name));
        $this->assertFalse($names->contains($uncovered->name), 'A municipality with no LGU Admin must not be advertised as a partner.');
        $this->assertSame(1, collect($body)->firstWhere('name', $covered->name)['verified_sellers_count']);

        // Suspending the only LGU Admin removes the municipality: with nobody
        // to verify sellers or approve earnings, it is no longer covered.
        AccountModeration::suspendLguAdmin(
            User::where('role', 'lgu_admin')->where('municipality_id', $covered->id)->firstOrFail(),
            User::where('role', 'super_admin')->firstOrFail(),
            'Under review.'
        );

        $this->assertFalse(
            collect($this->getJson('/api/partner-municipalities')->assertOk()->json())->pluck('name')->contains($covered->name)
        );
        $this->assertNotNull($seller->fresh());
    }

    public function test_the_assistant_states_the_withdrawal_payout_fee(): void
    {
        $topic = \App\Support\AiIntentClassifier::classify('how do I withdraw my earnings')['topic'];
        $expected = \App\Support\CommissionCalculator::WITHDRAWAL_FEE_PERCENT.'% payout fee';

        foreach (['buyer', 'seller'] as $role) {
            $this->assertStringContainsString(
                $expected,
                \App\Support\AiIntentClassifier::topicContext($topic, $role),
                "The {$role} withdrawal answer must state the payout fee."
            );
        }
    }

    public function test_gemini_service_responds_to_greetings_without_calling_the_provider(): void
    {
        config(['services.gemini.api_key' => null]);
        $service = new \App\Services\GeminiService();

        $this->assertStringContainsString('AbaiMarket assistant', $service->answer('Hello!', 'English'));
        $this->assertStringContainsString('AbaiMarket assistant', $service->answer('Kumusta!', 'Bisaya'));
    }

    public function test_ai_assistant_grounds_live_gemini_with_real_database_counts(): void
    {
        $cordova = Municipality::where('name', 'Cordova')->firstOrFail();
        $this->makeSeller(['municipality_id' => $cordova->id], ['municipality_id' => $cordova->id]);
        $this->makeSeller();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'There are 2 registered sellers.']]]]],
            ], 200),
        ]);

        Sanctum::actingAs($this->makeBuyer());

        $response = $this->postJson('/api/ai-assistant/ask', ['question' => 'How many sellers are registered?']);

        $response->assertCreated()->assertJsonFragment(['response' => 'There are 2 registered sellers.']);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($body['systemInstruction']['parts'][0]['text'], 'There are 2 registered sellers');
        });
    }

    public function test_ai_assistant_falls_back_to_data_driven_answer_when_gemini_is_unavailable(): void
    {
        config(['services.gemini.api_key' => null]);

        $cordova = Municipality::where('name', 'Cordova')->firstOrFail();
        $this->makeSeller(['municipality_id' => $cordova->id], ['municipality_id' => $cordova->id]);
        $this->makeSeller();

        Sanctum::actingAs($this->makeBuyer());

        $response = $this->postJson('/api/ai-assistant/ask', ['question' => 'How many sellers are registered?']);

        $response->assertCreated();
        $this->assertStringContainsString('2', $response->json('response'));
        $this->assertStringContainsString('sellers', $response->json('response'));
    }

    public function test_ai_assistant_follow_up_question_carries_over_the_previous_subject(): void
    {
        config(['services.gemini.api_key' => null]);

        $cordova = Municipality::where('name', 'Cordova')->firstOrFail();
        $mandaue = Municipality::where('name', 'Mandaue')->firstOrFail();
        $this->makeSeller(['municipality_id' => $cordova->id], ['municipality_id' => $cordova->id]);
        $this->makeSeller(['municipality_id' => $mandaue->id], ['municipality_id' => $mandaue->id]);
        $this->makeSeller(['municipality_id' => $mandaue->id], ['municipality_id' => $mandaue->id]);

        Sanctum::actingAs($this->makeBuyer());

        $this->postJson('/api/ai-assistant/ask', ['question' => 'How many sellers are registered?'])
            ->assertCreated()
            ->assertJsonFragment(['data_subject' => 'seller_count']);

        $followUp = $this->postJson('/api/ai-assistant/ask', ['question' => 'How many are in Cordova?']);

        $followUp->assertCreated()->assertJsonFragment(['data_subject' => 'seller_count']);
        $this->assertStringContainsString('1', $followUp->json('response'));
        $this->assertStringContainsString('Cordova', $followUp->json('response'));
    }

    public function test_ai_assistant_resolves_own_account_facts_scoped_to_the_authenticated_buyer(): void
    {
        config(['services.gemini.api_key' => null]);

        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller, ['species' => 'Grouper']);

        $buyerA = $this->makeBuyer();
        $buyerB = $this->makeBuyer();
        $this->makeOrder($buyerA, $listing, ['status' => 'completed']);
        $this->makeOrder($buyerB, $listing, ['status' => 'placed']);

        Sanctum::actingAs($buyerA);
        $responseA = $this->postJson('/api/ai-assistant/ask', ['question' => 'What is my latest order?']);
        $responseA->assertCreated();
        $this->assertStringContainsString('Grouper', $responseA->json('response'));
        $this->assertStringContainsString('completed', $responseA->json('response'));

        Sanctum::actingAs($buyerB);
        $responseB = $this->postJson('/api/ai-assistant/ask', ['question' => 'What is my latest order?']);
        $responseB->assertCreated();
        $this->assertStringContainsString('placed', $responseB->json('response'));
        $this->assertStringNotContainsString('completed', $responseB->json('response'));
    }

    public function test_ai_assistant_clarifies_buyers_have_no_wallet_instead_of_fabricating_a_balance(): void
    {
        config(['services.gemini.api_key' => null]);

        Sanctum::actingAs($this->makeBuyer());

        $response = $this->postJson('/api/ai-assistant/ask', ['question' => 'How much is my available balance?']);

        $response->assertCreated();
        $this->assertStringContainsString("don't have a wallet", $response->json('response'));
    }

    public function test_ai_assistant_auto_detects_language_without_a_client_supplied_value(): void
    {
        config(['services.gemini.api_key' => null]);

        Sanctum::actingAs($this->makeBuyer());
        $english = $this->postJson('/api/ai-assistant/ask', ['question' => 'How many sellers are registered?']);
        $english->assertCreated()->assertJsonFragment(['language' => 'English']);

        Sanctum::actingAs($this->makeBuyer());
        $tagalog = $this->postJson('/api/ai-assistant/ask', ['question' => 'Ilan ang mga rehistradong seller?']);
        $tagalog->assertCreated()->assertJsonFragment(['language' => 'Tagalog']);

        Sanctum::actingAs($this->makeBuyer());
        $bisaya = $this->postJson('/api/ai-assistant/ask', ['question' => 'Pila ka seller ang naa?']);
        $bisaya->assertCreated()->assertJsonFragment(['language' => 'Bisaya']);
    }

    public function test_seller_ai_assistant_reports_its_own_real_wallet_balance(): void
    {
        config(['services.gemini.api_key' => null]);

        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller);
        $order = $this->makeOrder($this->makeBuyer(), $listing, ['status' => 'completed', 'total_amount' => 500]);
        $payment = $this->makePayment($order, ['status' => 'released', 'amount' => 500]);
        $this->makeSettlement($order, $payment); // Seller Share: 96% of ₱500 = ₱480.

        Sanctum::actingAs($seller->user);
        $response = $this->postJson('/api/ai-assistant/ask', ['question' => 'How much is my available balance?']);

        $response->assertCreated();
        $this->assertStringContainsString('480', $response->json('response'));
        $this->assertSame('seller_wallet', $response->json('data_subject'));
    }

    public function test_seller_ai_assistant_never_reveals_another_sellers_wallet(): void
    {
        config(['services.gemini.api_key' => null]);

        $sellerA = $this->makeSeller();
        $sellerB = $this->makeSeller();
        $listingB = $this->makeListing($sellerB);
        $orderB = $this->makeOrder($this->makeBuyer(), $listingB, ['status' => 'completed', 'total_amount' => 9999]);
        $paymentB = $this->makePayment($orderB, ['status' => 'released', 'amount' => 9999]);
        $this->makeSettlement($orderB, $paymentB);

        Sanctum::actingAs($sellerA->user);
        $response = $this->postJson('/api/ai-assistant/ask', ['question' => 'How much is my available balance?']);

        $response->assertCreated();
        $this->assertStringNotContainsString('9999', $response->json('response'));
        $this->assertStringNotContainsString('9599', $response->json('response')); // Seller B's Seller Share (96% of ₱9999) must not leak either.
    }

    public function test_seller_ai_assistant_reports_listing_counts_by_status(): void
    {
        config(['services.gemini.api_key' => null]);

        $seller = $this->makeSeller();
        $this->makeListing($seller, ['species' => 'Bangus', 'approval_status' => 'approved']);
        $this->makeListing($seller, ['species' => 'Tilapia', 'approval_status' => 'approved']);
        $this->makeListing($seller, ['species' => 'Carp', 'approval_status' => 'pending']);

        Sanctum::actingAs($seller->user);

        $active = $this->postJson('/api/ai-assistant/ask', ['question' => 'How many active listings do I have?']);
        $active->assertCreated();
        $this->assertStringContainsString('2', $active->json('response'));

        $pending = $this->postJson('/api/ai-assistant/ask', ['question' => 'How many pending listings do I have?']);
        $pending->assertCreated();
        $this->assertStringContainsString('1', $pending->json('response'));
    }

    public function test_lgu_ai_assistant_never_reveals_another_municipalitys_seller_count(): void
    {
        config(['services.gemini.api_key' => null]);

        $cordova = Municipality::where('name', 'Cordova')->firstOrFail();
        $mandaue = Municipality::where('name', 'Mandaue')->firstOrFail();
        $this->makeSeller(['municipality_id' => $cordova->id], ['municipality_id' => $cordova->id]);
        $this->makeSeller(['municipality_id' => $mandaue->id], ['municipality_id' => $mandaue->id]);
        $mandaueAdmin = $this->makeLguAdmin(['municipality_id' => $mandaue->id]);

        Sanctum::actingAs($mandaueAdmin);

        // Even though Cordova is named explicitly, the answer must stay
        // pinned to the admin's own municipality (Mandaue: 1 seller), never
        // leaking Cordova's count.
        $response = $this->postJson('/api/ai-assistant/ask', ['question' => 'How many sellers are in Cordova?']);

        $response->assertCreated();
        $this->assertStringContainsString('Mandaue', $response->json('response'));
        $this->assertStringNotContainsString('Cordova', $response->json('response'));
        $this->assertStringContainsString('1', $response->json('response'));
    }

    public function test_lgu_ai_assistant_reports_pending_earnings_awaiting_approval_in_its_own_municipality(): void
    {
        config(['services.gemini.api_key' => null]);

        $mandaue = Municipality::where('name', 'Mandaue')->firstOrFail();
        $seller = $this->makeSeller(['municipality_id' => $mandaue->id], ['municipality_id' => $mandaue->id]);
        $listing = $this->makeListing($seller, ['municipality_id' => $mandaue->id]);
        $order = $this->makeOrder($this->makeBuyer(), $listing, ['status' => 'completed']);
        $this->makePayment($order, ['status' => 'paid_held']);

        $mandaueAdmin = $this->makeLguAdmin(['municipality_id' => $mandaue->id]);
        Sanctum::actingAs($mandaueAdmin);

        $response = $this->postJson('/api/ai-assistant/ask', ['question' => 'Which completed deliveries still need earnings approval?']);

        $response->assertCreated();
        $this->assertStringContainsString('1', $response->json('response'));
    }

    public function test_super_admin_ai_assistant_sees_platform_wide_totals_unrestricted(): void
    {
        config(['services.gemini.api_key' => null]);

        $cordova = Municipality::where('name', 'Cordova')->firstOrFail();
        $mandaue = Municipality::where('name', 'Mandaue')->firstOrFail();
        $this->makeSeller(['municipality_id' => $cordova->id], ['municipality_id' => $cordova->id]);
        $this->makeSeller(['municipality_id' => $mandaue->id], ['municipality_id' => $mandaue->id]);

        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());

        $response = $this->postJson('/api/ai-assistant/ask', ['question' => 'How many sellers are registered?']);

        $response->assertCreated();
        $this->assertStringContainsString('2', $response->json('response'));
    }

    public function test_ai_assistant_off_topic_refusal_applies_to_every_role(): void
    {
        config(['services.gemini.api_key' => null]);

        Sanctum::actingAs($this->makeLguAdmin());
        $response = $this->postJson('/api/ai-assistant/ask', ['question' => 'Who won the last World Cup?']);

        $response->assertCreated();
        $this->assertStringContainsString('AbaiMarket', $response->json('response'));
        $this->assertStringNotContainsString('World Cup', $response->json('response'));
    }

    public function test_buyer_ai_assistant_recommends_listings_ranked_by_a_balanced_score(): void
    {
        config(['services.gemini.api_key' => null]);

        $strongSeller = $this->makeSeller(['name' => 'Strong Hatchery'], ['hatchery_name' => 'Strong Hatchery', 'rating' => 4.9, 'status' => 'verified']);
        $strongListing = $this->makeListing($strongSeller, ['species' => 'Tilapia', 'quantity' => 500]);
        $weakSeller = $this->makeSeller(['name' => 'Weak Hatchery'], ['hatchery_name' => 'Weak Hatchery', 'rating' => 2.0, 'status' => 'pending']);
        $this->makeListing($weakSeller, ['species' => 'Bangus', 'quantity' => 20]);

        $buyer = $this->makeBuyer();
        $completedOrder = $this->makeOrder($buyer, $strongListing, ['status' => 'completed']);
        Review::create(['order_id' => $completedOrder->id, 'buyer_id' => $buyer->id, 'seller_profile_id' => $strongSeller->id, 'rating' => 5]);

        Sanctum::actingAs($this->makeBuyer());
        $response = $this->postJson('/api/ai-assistant/ask', ['question' => 'Can you recommend a good listing for me to buy?']);

        $response->assertCreated();
        $body = $response->json('response');
        $this->assertStringContainsString('Strong Hatchery', $body);
        $this->assertStringContainsString('Weak Hatchery', $body);
        // Higher-scored seller (rating, reviews, completed orders, verification, stock) must rank first.
        $this->assertTrue(strpos($body, 'Strong Hatchery') < strpos($body, 'Weak Hatchery'));
    }

    public function test_seller_ai_assistant_gives_business_recommendations_from_real_sales_data(): void
    {
        config(['services.gemini.api_key' => null]);

        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller, ['species' => 'Tilapia']);
        $this->makeOrder($this->makeBuyer(), $listing, ['status' => 'completed', 'quantity' => 300, 'total_amount' => 1000]);

        Sanctum::actingAs($seller->user);
        $response = $this->postJson('/api/ai-assistant/ask', ['question' => 'How can I improve my sales?']);

        $response->assertCreated();
        $this->assertStringContainsString('Tilapia', $response->json('response'));
    }

    public function test_lgu_ai_assistant_recommends_sellers_needing_assistance_in_its_own_municipality(): void
    {
        config(['services.gemini.api_key' => null]);

        $mandaue = Municipality::where('name', 'Mandaue')->firstOrFail();
        $strugglingSeller = $this->makeSeller(
            ['municipality_id' => $mandaue->id],
            ['municipality_id' => $mandaue->id, 'hatchery_name' => 'Struggling Hatchery', 'status' => 'verified', 'rating' => 1.5]
        );

        $mandaueAdmin = $this->makeLguAdmin(['municipality_id' => $mandaue->id]);
        Sanctum::actingAs($mandaueAdmin);

        $response = $this->postJson('/api/ai-assistant/ask', ['question' => 'Which sellers need assistance?']);

        $response->assertCreated();
        $this->assertStringContainsString('Struggling Hatchery', $response->json('response'));
    }

    public function test_super_admin_ai_assistant_ranks_top_performing_sellers_platform_wide(): void
    {
        config(['services.gemini.api_key' => null]);

        $seller = $this->makeSeller(['name' => 'Top Hatchery'], ['hatchery_name' => 'Top Hatchery']);
        $listing = $this->makeListing($seller);
        $this->makeOrder($this->makeBuyer(), $listing, ['status' => 'completed', 'total_amount' => 2500]);

        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
        $response = $this->postJson('/api/ai-assistant/ask', ['question' => 'Show me the top-performing sellers platform-wide.']);

        $response->assertCreated();
        $this->assertStringContainsString('Top Hatchery', $response->json('response'));
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

    public function test_buyer_can_mark_all_notifications_read_without_affecting_other_buyers(): void
    {
        $buyer = $this->makeBuyer();
        $otherBuyer = $this->makeBuyer();

        foreach (range(1, 2) as $i) {
            AppNotification::create([
                'user_id' => $buyer->id,
                'type' => 'order_created',
                'title' => "Notification {$i}",
                'body' => 'Your order is now being reviewed by the seller.',
            ]);
        }
        $otherNotification = AppNotification::create([
            'user_id' => $otherBuyer->id,
            'type' => 'order_created',
            'title' => "Other buyer's notification",
            'body' => 'Your order is now being reviewed by the seller.',
        ]);

        Sanctum::actingAs($buyer);

        $this->getJson('/api/buyer/notifications')->assertJsonCount(2);

        $this->patchJson('/api/buyer/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('updated', 2);

        $this->getJson('/api/buyer/notifications')->assertJsonCount(0);
        $this->assertNull($otherNotification->fresh()->read_at);
    }

    public function test_reset_test_data_keeps_one_seller_and_rebuilds_test_accounts(): void
    {
        Storage::fake('public');
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        $roido = $this->makeSeller(['name' => 'Roido'], ['hatchery_name' => "Roido's Fisheries", 'rating' => 4.5]);
        $roidoListing = $this->makeListing($roido);
        Storage::disk('public')->put('listings/'.$roidoListing->id.'/keep.jpg', 'x');
        ListingMedia::create(['listing_id' => $roidoListing->id, 'type' => 'image', 'title' => 'Fingerlings', 'url' => '/storage/listings/'.$roidoListing->id.'/keep.jpg']);
        $other = $this->makeSeller();
        $otherListing = $this->makeListing($other);
        Storage::disk('public')->put('listings/'.$otherListing->id.'/gone.jpg', 'x');
        $buyer = $this->makeBuyer();
        $order = $this->makeOrder($buyer, $roidoListing, ['status' => 'completed']);
        $this->makePayment($order, ['status' => 'paid_held']);

        // Dry run changes nothing.
        $this->artisan('app:reset-test-data', ['--email' => 'tester@example.test'])->assertSuccessful();
        $this->assertDatabaseHas('users', ['id' => $buyer->id]);
        $this->assertSame(1, Order::count());

        $this->artisan('app:reset-test-data', ['--email' => 'tester@example.test', '--execute' => true])
            ->expectsConfirmation('Permanently delete the data above and create the test accounts?', 'yes')
            ->assertSuccessful();

        // Kept: the Super Admin, Roido's Fisheries and its listing + image.
        $this->assertDatabaseHas('users', ['id' => $superAdmin->id]);
        $this->assertDatabaseHas('seller_profiles', ['id' => $roido->id, 'rating' => 0]);
        $this->assertDatabaseHas('listings', ['id' => $roidoListing->id]);
        Storage::disk('public')->assertExists('listings/'.$roidoListing->id.'/keep.jpg');

        // Gone: other accounts, their listings and files, every transaction.
        $this->assertDatabaseMissing('users', ['id' => $buyer->id]);
        $this->assertDatabaseMissing('users', ['id' => $other->user_id]);
        $this->assertDatabaseMissing('listings', ['id' => $otherListing->id]);
        Storage::disk('public')->assertMissing('listings/'.$otherListing->id.'/gone.jpg');
        $this->assertSame(0, Order::count());
        $this->assertSame(0, MockPayment::count());

        // Rebuilt: one verified LGU Admin per municipality, three buyers.
        $this->assertSame(Municipality::count(), User::where('role', 'lgu_admin')->count());
        $this->assertSame(0, User::where('role', 'lgu_admin')->where('email', 'lgu@gmail.com')->count());
        $this->assertSame(3, User::where('role', 'buyer')->whereNotNull('email_verified_at')->count());
        $this->assertDatabaseHas('users', ['email' => 'tester+buyer1@example.test', 'role' => 'buyer']);
        $this->assertDatabaseHas('users', ['email' => 'tester+lgu.lapulapu@example.test', 'role' => 'lgu_admin']);
        $this->assertSame(3, BuyerProfile::count());
    }

    public function test_reset_test_data_with_no_accounts_only_wipes(): void
    {
        $roido = $this->makeSeller([], ['hatchery_name' => "Roido's Fisheries"]);
        $buyer = $this->makeBuyer();

        $this->artisan('app:reset-test-data', ['--no-accounts' => true, '--execute' => true])
            ->expectsConfirmation('Permanently delete the data above and create the test accounts?', 'yes')
            ->assertSuccessful();

        $this->assertDatabaseMissing('users', ['id' => $buyer->id]);
        $this->assertSame(0, User::whereIn('role', ['buyer', 'lgu_admin'])->count());
        $this->assertDatabaseHas('users', ['id' => $roido->user_id]);
        $this->assertSame(1, User::where('role', 'super_admin')->count());
    }

    public function test_reset_test_data_refuses_without_exactly_one_kept_seller(): void
    {
        $buyer = $this->makeBuyer();

        $this->artisan('app:reset-test-data', ['--email' => 'tester@example.test', '--execute' => true])->assertFailed();

        $this->assertDatabaseHas('users', ['id' => $buyer->id]);
    }

    public function test_lgu_and_super_admin_can_mark_all_their_own_notifications_read(): void
    {
        $lguAdmin = $this->makeLguAdmin();
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();

        foreach ([$lguAdmin, $superAdmin] as $admin) {
            foreach (range(1, 2) as $i) {
                AppNotification::create([
                    'user_id' => $admin->id,
                    'type' => 'earnings_pending_approval',
                    'title' => "Notification {$i}",
                    'body' => 'Test notification body.',
                ]);
            }
        }

        Sanctum::actingAs($lguAdmin);
        $this->patchJson('/api/lgu/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('updated', 2);
        $this->assertSame(0, AppNotification::where('user_id', $lguAdmin->id)->whereNull('read_at')->count());
        // Another admin's notifications are untouched.
        $this->assertSame(2, AppNotification::where('user_id', $superAdmin->id)->whereNull('read_at')->count());

        Sanctum::actingAs($superAdmin);
        $this->getJson('/api/super-admin/notifications')->assertJsonCount(2);
        $this->patchJson('/api/super-admin/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('updated', 2);
        $this->getJson('/api/super-admin/notifications')->assertJsonCount(0);
    }

    public function test_seller_analytics_returns_period_scoped_summary_and_series(): void
    {
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller, ['species' => 'Bangus', 'quantity' => 5000]);
        $buyer = $this->makeBuyer();

        $this->makeOrder($buyer, $listing, ['status' => 'completed', 'quantity' => 100, 'total_amount' => 350]);
        $this->makeOrder($buyer, $listing, ['status' => 'placed', 'quantity' => 50, 'total_amount' => 175]);
        $this->makeOrder($buyer, $listing, ['status' => 'cancelled', 'quantity' => 20, 'total_amount' => 70]);

        Sanctum::actingAs($seller->user);

        $response = $this->getJson('/api/seller/analytics');

        $response->assertOk()
            ->assertJsonPath('period', 'monthly')
            ->assertJsonPath('summary.total_sales', 1)
            ->assertJsonPath('summary.total_revenue', 350)
            ->assertJsonPath('summary.total_orders', 3)
            ->assertJsonPath('summary.active_listings', 1)
            ->assertJsonPath('top_species.0.species', 'Bangus')
            ->assertJsonStructure(['sales_over_time', 'orders_by_status']);
        $this->assertArrayNotHasKey('monthly_earnings', $response->json());
    }

    public function test_seller_analytics_accepts_every_supported_period_and_falls_back_on_invalid_input(): void
    {
        $seller = $this->makeSeller();
        Sanctum::actingAs($seller->user);

        foreach (['daily', 'weekly', 'monthly', 'yearly'] as $period) {
            $this->getJson("/api/seller/analytics?period={$period}")
                ->assertOk()
                ->assertJsonPath('period', $period);
        }

        $this->getJson('/api/seller/analytics?period=not-a-real-period')
            ->assertOk()
            ->assertJsonPath('period', 'monthly');
    }

    public function test_seller_analytics_sales_over_time_bucket_count_changes_with_the_selected_period(): void
    {
        $seller = $this->makeSeller();
        Sanctum::actingAs($seller->user);

        $bucketCounts = [];
        foreach (['daily', 'weekly', 'monthly', 'yearly'] as $period) {
            $response = $this->getJson("/api/seller/analytics?period={$period}")->assertOk();
            $bucketCounts[$period] = count($response->json('sales_over_time'));
        }

        // Each period must produce a genuinely different bucket granularity/count
        // (14 days, 12 weeks, 12 months, 5 years) -- this is what proves the
        // "Total Earnings" chart, which reads this same series, actually reacts
        // to the filter instead of staying pinned to a fixed monthly view.
        $this->assertEquals(14, $bucketCounts['daily']);
        $this->assertEquals(12, $bucketCounts['weekly']);
        $this->assertEquals(12, $bucketCounts['monthly']);
        $this->assertEquals(5, $bucketCounts['yearly']);
    }

    public function test_buyer_analytics_returns_period_scoped_summary_and_favorite_species(): void
    {
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller, ['species' => 'Tilapia']);
        $buyer = $this->makeBuyer();

        $this->makeOrder($buyer, $listing, ['status' => 'completed', 'quantity' => 100, 'total_amount' => 350]);
        $this->makeOrder($buyer, $listing, ['status' => 'placed', 'quantity' => 50, 'total_amount' => 175]);

        Sanctum::actingAs($buyer);

        $response = $this->getJson('/api/buyer/analytics');

        $response->assertOk()
            ->assertJsonPath('period', 'monthly')
            ->assertJsonPath('summary.total_purchases', 1)
            ->assertJsonPath('summary.total_orders', 2)
            ->assertJsonPath('summary.total_spending', 350)
            ->assertJsonPath('summary.favorite_species', 'Tilapia')
            ->assertJsonStructure(['purchases_over_time', 'orders_by_status', 'top_species']);
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

        // Abandoning checkout leaves the order payable rather than failing it;
        // orders:expire-unpaid is what eventually fails it. See the dedicated
        // test below for the full re-payment path.
        $this->postJson("/api/orders/{$cancelledOrder->order_number}/payment-cancelled")->assertOk()->assertJsonPath('status', 'payable');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $buyer->id,
            'type' => 'payment_incomplete',
            'title' => 'Payment not completed',
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

    public function test_messaging_is_rejected_between_two_buyers(): void
    {
        $buyer = $this->makeBuyer();
        $otherBuyer = $this->makeBuyer();

        Sanctum::actingAs($buyer);

        $this->postJson('/api/messages', [
            'receiver_id' => $otherBuyer->id,
            'body' => 'Hello?',
        ])->assertStatus(422);
    }

    public function test_lgu_admin_can_message_a_buyer_in_the_same_municipality(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $buyer = $this->makeBuyer(['municipality_id' => $lguAdmin->municipality_id]);
        Sanctum::actingAs($lguAdmin);

        $this->postJson('/api/messages', [
            'receiver_id' => $buyer->id,
            'body' => 'We received a complaint about a recent order.',
        ])->assertCreated();

        // Reply direction must also work (admin's message is not one-way).
        Sanctum::actingAs($buyer);
        $this->postJson('/api/messages', [
            'receiver_id' => $lguAdmin->id,
            'body' => 'Sure, happy to explain.',
        ])->assertCreated();
    }

    public function test_lgu_admin_can_message_a_seller_in_the_same_municipality(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $seller = $this->makeSeller(['municipality_id' => $lguAdmin->municipality_id], ['municipality_id' => $lguAdmin->municipality_id])->user;
        Sanctum::actingAs($lguAdmin);

        $this->postJson('/api/messages', [
            'receiver_id' => $seller->id,
            'body' => 'Please update your listing photos.',
        ])->assertCreated();
    }

    public function test_lgu_admin_cannot_message_a_buyer_outside_their_municipality(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $otherMunicipality = Municipality::where('id', '!=', $lguAdmin->municipality_id)->firstOrFail();
        $buyer = $this->makeBuyer(['municipality_id' => $otherMunicipality->id]);
        Sanctum::actingAs($lguAdmin);

        $this->postJson('/api/messages', [
            'receiver_id' => $buyer->id,
            'body' => 'Hello?',
        ])->assertStatus(403);
    }

    public function test_lgu_admin_cannot_message_a_seller_outside_their_municipality(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $otherMunicipality = Municipality::where('id', '!=', $lguAdmin->municipality_id)->firstOrFail();
        $seller = $this->makeSeller([], ['municipality_id' => $otherMunicipality->id])->user;
        Sanctum::actingAs($lguAdmin);

        $this->postJson('/api/messages', [
            'receiver_id' => $seller->id,
            'body' => 'Hello?',
        ])->assertStatus(403);
    }

    public function test_super_admin_can_message_any_buyer_seller_or_lgu_admin_regardless_of_municipality(): void
    {
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $otherMunicipality = Municipality::where('id', '!=', $lguAdmin->municipality_id)->firstOrFail();
        $buyer = $this->makeBuyer(['municipality_id' => $otherMunicipality->id]);
        $seller = $this->makeSeller([], ['municipality_id' => $otherMunicipality->id])->user;

        Sanctum::actingAs($superAdmin);

        $this->postJson('/api/messages', ['receiver_id' => $buyer->id, 'body' => 'Platform notice.'])->assertCreated();
        $this->postJson('/api/messages', ['receiver_id' => $seller->id, 'body' => 'Platform notice.'])->assertCreated();
        $this->postJson('/api/messages', ['receiver_id' => $lguAdmin->id, 'body' => 'Platform notice.'])->assertCreated();

        // LGU admin must be able to reply back to the Super Admin regardless of municipality scoping.
        Sanctum::actingAs($lguAdmin);
        $this->postJson('/api/messages', ['receiver_id' => $superAdmin->id, 'body' => 'Acknowledged.'])->assertCreated();
    }

    public function test_existing_buyer_seller_conversation_is_unaffected_by_admin_messaging_changes(): void
    {
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller()->user;

        Sanctum::actingAs($buyer);
        $this->postJson('/api/messages', ['receiver_id' => $seller->id, 'body' => 'Still works?'])->assertCreated();

        Sanctum::actingAs($seller);
        $this->getJson('/api/messages/threads')->assertOk()->assertJsonFragment(['id' => $buyer->id]);
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

        $response = $this->postListing([
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

        $response = $this->postListing([
            'species' => 'Tilapia',
            'title' => 'Tilapia Fingerlings',
            'description' => 'Healthy tilapia fingerlings raised in aerated freshwater ponds.',
            'quantity' => 200,
            'price_per_piece' => 1.75,
        ]);

        $response->assertCreated()->assertJsonPath('description', 'Healthy tilapia fingerlings raised in aerated freshwater ponds.');

        // A verified seller's listing goes live immediately -- there is no
        // per-listing approval queue any more.
        $this->assertSame('approved', $response->json('approval_status'));

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
            'photo' => UploadedFile::fake()->image('too-big.jpg')->size(26000),
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
            'password' => 'New-Secure1',
        ])->assertOk();

        $this->postJson('/api/auth/login', ['email' => $buyer->email, 'password' => 'New-Secure1'])->assertOk();
    }

    public function test_password_change_is_rejected_with_wrong_current_password(): void
    {
        $buyer = $this->makeBuyer();
        Sanctum::actingAs($buyer);

        $this->patchJson('/api/auth/password', [
            'current_password' => 'wrong-password',
            'password' => 'New-Secure1',
        ])->assertStatus(422);

        $this->postJson('/api/auth/login', ['email' => $buyer->email, 'password' => 'password'])->assertOk();
    }

    public function test_passwords_may_not_contain_spaces_but_allow_symbols(): void
    {
        // Registration: a spaced password is rejected, a symbol-rich one is fine.
        $this->postJson('/api/auth/register', [
            'name' => 'Spacey', 'email' => 'spacey-'.Str::random(6).'@example.test',
            'password' => 'pass word123', 'role' => 'buyer',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->postJson('/api/auth/register', [
            'name' => 'Secure', 'email' => 'secure-'.Str::random(6).'@example.test',
            'password' => 'P@ssw0rd!#', 'role' => 'buyer',
        ])->assertCreated();

        // Change password: same rule.
        $buyer = $this->makeBuyer();
        Sanctum::actingAs($buyer);
        $this->patchJson('/api/auth/password', [
            'current_password' => 'password',
            'password' => 'new pass 123',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->patchJson('/api/auth/password', [
            'current_password' => 'password',
            'password' => 'New$ecure1',
        ])->assertOk();
    }

    public function test_password_policy_enforces_strength_requirements(): void
    {
        // Each of these fails exactly one requirement of the shared policy.
        $weakPasswords = [
            'Ab1!',            // too short (< 8)
            'password',        // no uppercase, number, or symbol
            'PASSWORD123!',    // no lowercase
            'password123!',    // no uppercase
            'Password!!!',     // no number
            'Password123',     // no special character
            'Fish 123!Aa',     // contains a space
        ];

        foreach ($weakPasswords as $weak) {
            $this->postJson('/api/auth/register', [
                'name' => 'Weak', 'email' => 'weak-'.Str::random(8).'@example.test',
                'password' => $weak, 'role' => 'buyer',
            ])->assertStatus(422)->assertJsonValidationErrors('password');
        }

        // A password longer than 64 characters is rejected...
        $this->postJson('/api/auth/register', [
            'name' => 'TooLong', 'email' => 'toolong-'.Str::random(8).'@example.test',
            'password' => 'Aa1!'.str_repeat('x', 61), 'role' => 'buyer', // 65 chars
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        // ...while a fully compliant strong password is accepted.
        foreach (['AbaiMarket123!', 'MySecure@2026', 'Seller#Wallet90'] as $strong) {
            $this->postJson('/api/auth/register', [
                'name' => 'Strong', 'email' => 'strong-'.Str::random(8).'@example.test',
                'password' => $strong, 'role' => 'buyer',
            ])->assertCreated();
        }
    }

    public function test_email_validation_is_consistent_and_friendly_across_registration(): void
    {
        // An internal space anywhere in the address is rejected.
        $this->postJson('/api/auth/register', [
            'name' => 'Spacey', 'email' => 'john doe@example.test',
            'password' => 'AbaiMarket123!', 'role' => 'buyer',
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        // A malformed address is rejected with a friendly message.
        $badFormat = $this->postJson('/api/auth/register', [
            'name' => 'Bad', 'email' => 'not-an-email',
            'password' => 'AbaiMarket123!', 'role' => 'buyer',
        ]);
        $badFormat->assertStatus(422);
        $this->assertSame('Please enter a valid email address.', $badFormat->json('errors.email.0'));

        // Leading/trailing spaces are trimmed, so the account is created and
        // the address is stored clean.
        $this->postJson('/api/auth/register', [
            'name' => 'Trim', 'email' => '  trim-me@example.test  ',
            'password' => 'AbaiMarket123!', 'role' => 'buyer',
        ])->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'trim-me@example.test']);

        // Registering the same (now-existing) address returns the friendly
        // duplicate message, never a raw SQL/server error.
        $dupe = $this->postJson('/api/auth/register', [
            'name' => 'Dupe', 'email' => 'trim-me@example.test',
            'password' => 'AbaiMarket123!', 'role' => 'buyer',
        ]);
        $dupe->assertStatus(422);
        $this->assertSame('This email address is already registered.', $dupe->json('errors.email.0'));
        $this->assertStringNotContainsString('SQLSTATE', $dupe->json('message') ?? '');
    }

    public function test_login_trims_password_ends_and_rejects_whitespace_in_credentials(): void
    {
        $this->makeBuyer(['email' => 'login-clean@example.test']);

        // Accidental leading/trailing spaces around the password are trimmed,
        // so a correct password still logs in.
        $this->postJson('/api/auth/login', [
            'email' => 'login-clean@example.test', 'password' => '  password  ',
        ])->assertOk();

        // An email with an internal space is a validation error, not a generic
        // "invalid credentials".
        $this->postJson('/api/auth/login', [
            'email' => 'login clean@example.test', 'password' => 'password',
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        // A password with an internal space is rejected up front.
        $this->postJson('/api/auth/login', [
            'email' => 'login-clean@example.test', 'password' => 'pass word',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_lgu_admin_creation_uses_the_same_email_and_password_validation_as_registration(): void
    {
        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
        $municipality = Municipality::firstOrFail();

        // Weak password rejected by the shared strength policy.
        $this->postJson('/api/super-admin/lgu-admins', [
            'name' => 'Weak Admin', 'email' => 'weak-admin@example.test',
            'password' => 'password', 'municipality_id' => $municipality->id,
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        // Email with an internal space rejected.
        $this->postJson('/api/super-admin/lgu-admins', [
            'name' => 'Spaced Admin', 'email' => 'sp ace@example.test',
            'password' => 'AbaiMarket123!', 'municipality_id' => $municipality->id,
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        // Duplicate email returns the same friendly message as registration.
        $this->makeBuyer(['email' => 'taken@example.test']);
        $dupe = $this->postJson('/api/super-admin/lgu-admins', [
            'name' => 'Dupe Admin', 'email' => 'taken@example.test',
            'password' => 'AbaiMarket123!', 'municipality_id' => $municipality->id,
        ]);
        $dupe->assertStatus(422);
        $this->assertSame('This email address is already registered.', $dupe->json('errors.email.0'));

        // A clean, compliant account is created.
        $this->postJson('/api/super-admin/lgu-admins', [
            'name' => 'Good Admin', 'email' => '  good-admin@example.test  ',
            'password' => 'AbaiMarket123!', 'municipality_id' => $municipality->id,
        ])->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'good-admin@example.test', 'role' => 'lgu_admin']);
    }

    public function test_seller_registration_requires_municipality_and_is_correctly_assigned(): void
    {
        $municipality = Municipality::firstOrFail();

        $missingMunicipality = $this->postJson('/api/auth/register', [
            'name' => 'New Hatchery',
            'email' => 'new-hatchery@fishmarket.test',
            'password' => 'Password123!',
            'role' => 'seller',
        ]);
        $missingMunicipality->assertStatus(422);

        $response = $this->postJson('/api/auth/register', [
            'name' => 'New Hatchery',
            'email' => 'new-hatchery@fishmarket.test',
            'password' => 'Password123!',
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
            'password' => 'Password123!',
            'role' => 'buyer',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('buyer_profiles', ['user_id' => $response->json('user.id')]);
    }

    public function test_registration_sends_verification_email_and_issues_no_token(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Pending Buyer',
            'email' => 'pending-buyer@fishmarket.test',
            'password' => 'Password123!',
            'role' => 'buyer',
        ]);

        $response->assertCreated();
        $this->assertArrayNotHasKey('token', $response->json());
        $this->assertNull($response->json('user.email_verified_at'));

        $user = User::where('email', 'pending-buyer@fishmarket.test')->firstOrFail();
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_duplicate_email_registration_returns_a_friendly_validation_message(): void
    {
        $existing = $this->makeBuyer();

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Someone Else',
            'email' => $existing->email,
            'password' => 'Password123!',
            'role' => 'buyer',
        ]);

        $response->assertStatus(422);
        $this->assertSame('This email address is already registered.', $response->json('errors.email.0'));
        $this->assertStringNotContainsString('SQLSTATE', $response->json('message') ?? '');
    }

    public function test_registration_still_succeeds_when_the_verification_email_transport_fails(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP unreachable'));

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Resilient Buyer',
            'email' => 'resilient-buyer@fishmarket.test',
            'password' => 'Password123!',
            'role' => 'buyer',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'resilient-buyer@fishmarket.test']);
    }

    public function test_resend_verification_still_succeeds_when_the_verification_email_transport_fails(): void
    {
        $user = $this->makeBuyer(['email_verified_at' => null]);
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP unreachable'));

        $response = $this->postJson('/api/email/resend', ['email' => $user->email]);

        $response->assertOk();
    }

    public function test_forgot_password_emails_a_link_to_the_frontend_reset_page(): void
    {
        Notification::fake();
        $user = $this->makeBuyer();

        $response = $this->postJson('/api/auth/forgot-password', ['email' => $user->email]);

        $response->assertOk();
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $mail = $notification->toMail($user);

            return str_starts_with($mail->actionUrl, rtrim(config('app.frontend_url'), '/').'/reset-password?token=')
                && str_contains($mail->actionUrl, 'email='.urlencode($user->email));
        });
    }

    public function test_forgot_password_does_not_reveal_whether_an_email_is_registered(): void
    {
        Notification::fake();
        $user = $this->makeBuyer();

        $registered = $this->postJson('/api/auth/forgot-password', ['email' => $user->email]);
        $unregistered = $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@fishmarket.test']);

        $registered->assertOk();
        $unregistered->assertOk();
        $this->assertSame($registered->json('message'), $unregistered->json('message'));
    }

    public function test_forgot_password_still_succeeds_when_the_email_transport_fails(): void
    {
        $user = $this->makeBuyer();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP unreachable'));

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();
    }

    public function test_password_reset_sets_the_new_password_and_revokes_existing_tokens(): void
    {
        $user = $this->makeBuyer();
        $user->createToken('fishmarket');
        $token = Password::createToken($user);

        $response = $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $response->assertOk();
        $this->assertSame(0, $user->tokens()->count());
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->assertStatus(422);
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'NewPassword123!'])->assertOk();

        // The token is single-use.
        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'Another123!',
            'password_confirmation' => 'Another123!',
        ])->assertStatus(422);
    }

    public function test_google_registered_account_can_reset_and_log_in_with_a_password(): void
    {
        $user = $this->makeBuyer(['google_id' => 'google-123', 'password' => Hash::make(Str::random(40))]);

        $this->postJson('/api/auth/reset-password', [
            'token' => Password::createToken($user),
            'email' => $user->email,
            'password' => 'GooglePass123!',
            'password_confirmation' => 'GooglePass123!',
        ])->assertOk();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'GooglePass123!'])->assertOk();
        $this->assertSame('google-123', $user->fresh()->google_id);
    }

    public function test_password_reset_rejects_invalid_tokens_and_weak_or_mismatched_passwords(): void
    {
        $user = $this->makeBuyer();

        $this->postJson('/api/auth/reset-password', [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])->assertStatus(422)->assertJsonFragment(['message' => 'This password reset link is invalid or has expired. Please request a new one.']);

        $token = Password::createToken($user);
        $this->postJson('/api/auth/reset-password', [
            'token' => $token, 'email' => $user->email,
            'password' => 'weak', 'password_confirmation' => 'weak',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->postJson('/api/auth/reset-password', [
            'token' => $token, 'email' => $user->email,
            'password' => 'NewPassword123!', 'password_confirmation' => 'Different123!',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
    }

    public function test_password_reset_verifies_an_unverified_account(): void
    {
        $user = $this->makeBuyer(['email_verified_at' => null]);

        $this->postJson('/api/auth/reset-password', [
            'token' => Password::createToken($user),
            'email' => $user->email,
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])->assertOk();

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    private function signedWebhook(array $payload, string $secret = 'whsk_test_secret'): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

        return $this->call('POST', '/api/paymongo/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_PAYMONGO_SIGNATURE' => "t={$timestamp},te={$signature},li=",
        ], $body);
    }

    private function paidWebhookPayload(string $checkoutSessionId): array
    {
        return ['data' => ['id' => 'evt_1', 'type' => 'event', 'attributes' => [
            'type' => 'checkout_session.payment.paid',
            'livemode' => false,
            'data' => ['id' => $checkoutSessionId, 'type' => 'checkout_session'],
        ]]];
    }

    public function test_paymongo_webhook_requires_a_valid_signature(): void
    {
        config(['services.paymongo.webhook_secret' => 'whsk_test_secret']);
        $order = $this->makeOrder($this->makeBuyer(), $this->makeListing($this->makeSeller()));
        $payment = $this->makePayment($order, ['status' => 'checkout_created', 'provider_reference' => 'cs_real_1']);

        // Forged: unsigned, and signed with the wrong secret.
        $this->postJson('/api/paymongo/webhook', $this->paidWebhookPayload('cs_real_1'))->assertStatus(401);
        $this->signedWebhook($this->paidWebhookPayload('cs_real_1'), 'wrong-secret')->assertStatus(401);
        $this->assertSame('checkout_created', $payment->fresh()->status);

        // A signed event that isn't a payment changes nothing.
        $other = $this->paidWebhookPayload('cs_real_1');
        $other['data']['attributes']['type'] = 'payment.failed';
        $this->signedWebhook($other)->assertOk();
        $this->assertSame('checkout_created', $payment->fresh()->status);

        $this->signedWebhook($this->paidWebhookPayload('cs_real_1'))->assertOk();
        $this->assertSame('paid_held', $payment->fresh()->status);
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_paymongo_webhook_is_refused_when_no_secret_is_configured(): void
    {
        config(['services.paymongo.webhook_secret' => null]);
        $order = $this->makeOrder($this->makeBuyer(), $this->makeListing($this->makeSeller()));
        $payment = $this->makePayment($order, ['status' => 'checkout_created', 'provider_reference' => 'cs_real_2']);

        $this->postJson('/api/paymongo/webhook', $this->paidWebhookPayload('cs_real_2'))->assertStatus(503);
        $this->assertSame('checkout_created', $payment->fresh()->status);
    }

    public function test_payment_success_redirect_is_verified_with_paymongo_before_marking_paid(): void
    {
        config(['services.paymongo.secret_key' => 'sk_test_fake']);
        $buyer = $this->makeBuyer();
        $order = $this->makeOrder($buyer, $this->makeListing($this->makeSeller()));
        $payment = $this->makePayment($order, ['status' => 'checkout_created', 'provider_reference' => 'cs_real_3']);
        Sanctum::actingAs($buyer);

        // First check: not paid yet. Second check: paid.
        Http::fake(['api.paymongo.com/v1/checkout_sessions/cs_real_3' => Http::sequence()
            ->push(['data' => ['attributes' => ['payments' => []]]])
            ->push(['data' => ['attributes' => ['payments' => [['attributes' => ['status' => 'paid']]]]]]),
        ]);
        $this->postJson("/api/orders/{$order->order_number}/payment-success")->assertOk()->assertJsonPath('status', 'processing');
        $this->assertSame('checkout_created', $payment->fresh()->status);

        $this->postJson("/api/orders/{$order->order_number}/payment-success")->assertOk()->assertJsonPath('status', 'success');
        $this->assertSame('paid_held', $payment->fresh()->status);
    }

    public function test_seller_cancelling_an_unpaid_order_restores_stock(): void
    {
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller, ['quantity' => 900]);
        $order = $this->makeOrder($this->makeBuyer(), $listing, ['quantity' => 100]);
        $payment = $this->makePayment($order);
        Sanctum::actingAs($seller->user);

        $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'cancelled', 'cancellation_reason' => 'Stock died before dispatch.'])->assertOk()->assertJsonPath('status', 'cancelled');

        $this->assertSame(1000, (int) $listing->fresh()->quantity);
        $this->assertSame('cancelled', $payment->fresh()->status);

        // Cancelling again, or reviving it, never restocks twice.
        $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'cancelled', 'cancellation_reason' => 'Stock died before dispatch.'])->assertOk();
        $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'confirmed'])->assertStatus(422);
        $this->assertSame(1000, (int) $listing->fresh()->quantity);
    }

    public function test_seller_cancelling_a_paid_order_queues_a_refund_the_super_admin_completes(): void
    {
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller, ['quantity' => 900]);
        $buyer = $this->makeBuyer();
        $order = $this->makeOrder($buyer, $listing, ['quantity' => 100, 'status' => 'paid']);
        $payment = $this->makePayment($order, ['status' => 'paid_held']);

        Sanctum::actingAs($seller->user);
        $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'cancelled', 'cancellation_reason' => 'Stock died before dispatch.'])->assertOk();

        $this->assertSame(1000, (int) $listing->fresh()->quantity);
        $this->assertSame('refund_pending', $payment->fresh()->status);
        $this->assertDatabaseHas('notifications', ['user_id' => $buyer->id, 'title' => 'Refund pending']);
        $this->assertSame(0.0, (float) \App\Support\SellerWallet::summary($seller)['pending_balance']);

        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        $this->assertDatabaseHas('notifications', ['user_id' => $superAdmin->id, 'type' => "refund_pending:{$payment->id}"]);

        Sanctum::actingAs($superAdmin);
        $this->getJson('/api/super-admin/refunds')->assertOk()
            ->assertJsonPath('0.order_number', $order->order_number)
            ->assertJsonPath('0.status', 'refund_pending');

        $this->patchJson("/api/super-admin/refunds/{$payment->id}/refunded", ['reference' => 'RF-123'])->assertOk();
        $this->assertSame('refunded', $payment->fresh()->status);
        $this->assertDatabaseHas('notifications', ['user_id' => $buyer->id, 'title' => 'Refund sent']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'order_refunded']);
        $this->getJson('/api/super-admin/refunds')->assertJsonPath('0.refund_reference', 'RF-123');

        $this->patchJson("/api/super-admin/refunds/{$payment->id}/refunded")->assertStatus(422);
    }

    public function test_a_completed_order_cannot_be_cancelled(): void
    {
        $seller = $this->makeSeller();
        $order = $this->makeOrder($this->makeBuyer(), $this->makeListing($seller), ['status' => 'completed']);
        $this->makePayment($order, ['status' => 'paid_held']);
        Sanctum::actingAs($seller->user);

        $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'cancelled', 'cancellation_reason' => 'Stock died before dispatch.'])->assertStatus(422);
        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_a_seller_cannot_cancel_an_order_that_is_out_for_delivery(): void
    {
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller, ['quantity' => 900]);
        $order = $this->makeOrder($this->makeBuyer(), $listing, ['quantity' => 100, 'status' => 'in_transit']);
        $payment = $this->makePayment($order, ['status' => 'paid_held']);
        Sanctum::actingAs($seller->user);

        $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'cancelled', 'cancellation_reason' => 'Changed my mind.'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This order is already out for delivery and can no longer be cancelled.');

        // Nothing moved: the stock was not credited back to a pond it is no
        // longer in, and the buyer's held payment was not sent to refund.
        $this->assertSame('in_transit', $order->fresh()->status);
        $this->assertSame(900, (int) $listing->fresh()->quantity);
        $this->assertSame('paid_held', $payment->fresh()->status);
    }

    public function test_unpaid_orders_expire_and_release_their_stock(): void
    {
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller, ['quantity' => 800]);
        $buyer = $this->makeBuyer();

        $stale = $this->makeOrder($buyer, $listing, ['quantity' => 100]);
        $this->makePayment($stale);
        $stale->forceFill(['created_at' => now()->subMinutes(90)])->save();

        $fresh = $this->makeOrder($buyer, $listing, ['quantity' => 100]);
        $this->makePayment($fresh);

        $paid = $this->makeOrder($buyer, $listing, ['quantity' => 100]);
        $this->makePayment($paid, ['status' => 'paid_held']);
        $paid->forceFill(['created_at' => now()->subMinutes(90)])->save();

        $this->artisan('orders:expire-unpaid')->assertSuccessful();

        $this->assertSame('failed', $stale->fresh()->status);
        $this->assertSame('failed', $stale->payment->fresh()->status);
        $this->assertSame('placed', $fresh->fresh()->status);
        $this->assertSame('placed', $paid->fresh()->status);
        $this->assertSame(900, (int) $listing->fresh()->quantity);
        $this->assertDatabaseHas('notifications', ['user_id' => $buyer->id, 'title' => 'Order expired']);

        // A payment that still lands afterwards is refunded, not revived.
        $buyerPaysLate = $stale->fresh();
        Sanctum::actingAs($buyer);
        $this->postJson("/api/orders/{$buyerPaysLate->order_number}/payment-success")->assertOk();
        $this->assertSame('failed', $buyerPaysLate->fresh()->status);
        $this->assertSame('refund_pending', $buyerPaysLate->payment->fresh()->status);
        $this->assertSame(900, (int) $listing->fresh()->quantity);

        // And it can't be checked out again.
        $this->postJson("/api/orders/{$buyerPaysLate->id}/checkout")->assertStatus(422);
    }

    public function test_payment_cancelled_redirect_cannot_undo_a_paid_order(): void
    {
        $buyer = $this->makeBuyer();
        $listing = $this->makeListing($this->makeSeller(), ['quantity' => 900]);
        $order = $this->makeOrder($buyer, $listing, ['status' => 'paid']);
        $payment = $this->makePayment($order, ['status' => 'paid_held']);
        Sanctum::actingAs($buyer);

        // 410: the return link is spent, so there is no decline left to record.
        $this->postJson("/api/orders/{$order->order_number}/payment-cancelled")
            ->assertStatus(410)
            ->assertJsonPath('status', 'already_confirmed');

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('paid_held', $payment->fresh()->status);
        $this->assertSame(900, (int) $listing->fresh()->quantity);
    }

    public function test_abandoned_checkout_stays_payable_until_the_window_closes(): void
    {
        $buyer = $this->makeBuyer();
        $listing = $this->makeListing($this->makeSeller(), ['quantity' => 900]);
        $order = $this->makeOrder($buyer, $listing, ['status' => 'placed']);
        $payment = $this->makePayment($order, ['status' => 'checkout_created']);
        Sanctum::actingAs($buyer);

        $this->postJson("/api/orders/{$order->order_number}/payment-cancelled")
            ->assertOk()
            ->assertJsonPath('status', 'payable');

        // Still placed, and the stock stays reserved -- releasing it here would
        // let someone else buy the fish this order is still entitled to.
        $this->assertSame('placed', $order->fresh()->status);
        $this->assertSame('checkout_created', $payment->fresh()->status);
        $this->assertSame(900, (int) $listing->fresh()->quantity);

        // The buyer is told how long they have.
        $this->assertNotNull($order->fresh()->payment_expires_at);
    }

    public function test_cancelling_a_paid_order_refunds_it_outright_on_test_keys(): void
    {
        // Live keys keep the manual queue; sk_test_ has nothing to send back.
        config()->set('services.paymongo.auto_refund', true);

        $sellerProfile = $this->makeSeller();
        $listing = $this->makeListing($sellerProfile, ['quantity' => 900]);
        $buyer = $this->makeBuyer();
        $order = $this->makeOrder($buyer, $listing, ['status' => 'paid']);
        $payment = $this->makePayment($order, ['status' => 'paid_held']);

        Sanctum::actingAs($sellerProfile->user);
        $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'cancelled', 'cancellation_reason' => 'Stock died before dispatch.'])->assertOk();

        // Settled outright -- it never passes through the Super Admin queue.
        $this->assertSame('refunded', $payment->fresh()->status);
        $this->assertDatabaseMissing('notifications', ['type' => "refund_pending:{$payment->id}"]);
        $this->assertDatabaseHas('notifications', ['user_id' => $buyer->id, 'title' => 'Refund sent']);

        // Stock still comes back, and the log says the refund was automatic
        // rather than pretending an admin issued it.
        $this->assertSame(900 + (int) $order->quantity, (int) $listing->fresh()->quantity);
        $this->assertDatabaseHas('payment_logs', ['payment_id' => $payment->id, 'event' => 'refund.completed']);
        $this->assertTrue(
            (bool) \App\Models\PaymentLog::where('payment_id', $payment->id)
                ->where('event', 'refund.completed')
                ->value('payload')['automatic']
        );
    }

    public function test_cancelling_a_paid_order_still_queues_a_refund_on_live_keys(): void
    {
        config()->set('services.paymongo.auto_refund', false);

        $sellerProfile = $this->makeSeller();
        $order = $this->makeOrder($this->makeBuyer(), $this->makeListing($sellerProfile), ['status' => 'paid']);
        $payment = $this->makePayment($order, ['status' => 'paid_held']);

        Sanctum::actingAs($sellerProfile->user);
        $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'cancelled', 'cancellation_reason' => 'Stock died before dispatch.'])->assertOk();

        $this->assertSame('refund_pending', $payment->fresh()->status);
    }

    public function test_the_buyer_is_notified_when_an_order_ships_and_when_it_is_delivered(): void
    {
        $sellerProfile = $this->makeSeller();
        $listing = $this->makeListing($sellerProfile);
        $buyer = $this->makeBuyer();
        $order = $this->makeOrder($buyer, $listing, ['status' => 'confirmed']);
        $this->makePayment($order, ['status' => 'paid_held']);

        Sanctum::actingAs($sellerProfile->user);

        $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'in_transit'])->assertOk();
        $this->assertDatabaseHas('notifications', [
            'user_id' => $buyer->id,
            'type' => "order_out_for_delivery:{$order->id}",
            'title' => 'Out for delivery',
        ]);

        $this->buyerConfirmsReceipt($order, $sellerProfile->user);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $buyer->id,
            'type' => "order_delivered:{$order->id}",
            'title' => 'Order completed',
        ]);

        // A second confirmation is refused, so no duplicate can be raised.
        Sanctum::actingAs($buyer);
        $this->patchJson("/api/orders/{$order->id}/confirm-received")->assertStatus(422);
        $this->assertSame(1, \App\Models\AppNotification::where('user_id', $buyer->id)
            ->where('type', "order_delivered:{$order->id}")->count());
    }

    public function test_delivery_notifications_do_not_collide_between_two_orders(): void
    {
        $sellerProfile = $this->makeSeller();
        $listing = $this->makeListing($sellerProfile, ['quantity' => 900]);
        $buyer = $this->makeBuyer();
        $first = $this->makeOrder($buyer, $listing, ['status' => 'confirmed']);
        $second = $this->makeOrder($buyer, $listing, ['status' => 'confirmed']);
        $this->makePayment($first, ['status' => 'paid_held']);
        $this->makePayment($second, ['status' => 'paid_held']);

        Sanctum::actingAs($sellerProfile->user);
        $this->patchJson("/api/orders/{$first->id}/status", ['status' => 'in_transit'])->assertOk();
        $this->patchJson("/api/orders/{$second->id}/status", ['status' => 'in_transit'])->assertOk();

        $this->assertSame(2, \App\Models\AppNotification::where('user_id', $buyer->id)
            ->where('title', 'Out for delivery')->count());
    }

    public function test_a_seller_cannot_advance_an_unpaid_order(): void
    {
        $sellerProfile = $this->makeSeller();
        $listing = $this->makeListing($sellerProfile, ['quantity' => 900]);
        $buyer = $this->makeBuyer();
        $order = $this->makeOrder($buyer, $listing, ['status' => 'placed']);
        $this->makePayment($order, ['status' => 'checkout_created']);

        Sanctum::actingAs($sellerProfile->user);

        // Confirming an unpaid order would take it out of 'placed', locking the
        // buyer out of checkout() and hiding it from orders:expire-unpaid.
        foreach (['confirmed', 'in_transit'] as $status) {
            $this->patchJson("/api/orders/{$order->id}/status", ['status' => $status])
                ->assertStatus(422)
                ->assertJsonPath('message', 'This order has not been paid yet. You can only cancel it until the buyer completes payment.');
        }

        // 'completed' is not even a status this endpoint accepts any more --
        // only the buyer can confirm receipt -- and the same money-first rule
        // holds there, or an unpaid order would reach the LGU earnings queue.
        $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'completed'])->assertStatus(422);
        Sanctum::actingAs($buyer);
        $this->patchJson("/api/orders/{$order->id}/confirm-received")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This order has not been paid yet, so it cannot be marked as received.');
        Sanctum::actingAs($sellerProfile->user);

        $this->assertSame('placed', $order->fresh()->status);

        // Declining is still allowed, and gives the reserved stock back.
        $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'cancelled', 'cancellation_reason' => 'Stock died before dispatch.'])->assertOk();
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(900 + (int) $order->quantity, (int) $listing->fresh()->quantity);
    }

    public function test_a_seller_can_advance_an_order_once_it_is_paid(): void
    {
        $sellerProfile = $this->makeSeller();
        $listing = $this->makeListing($sellerProfile, ['quantity' => 900]);
        $order = $this->makeOrder($this->makeBuyer(), $listing, ['status' => 'paid']);
        $this->makePayment($order, ['status' => 'paid_held']);

        Sanctum::actingAs($sellerProfile->user);

        $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'confirmed'])->assertOk();
        $this->assertSame('confirmed', $order->fresh()->status);
    }

    public function test_an_abandoned_order_expires_once_its_window_closes(): void
    {
        $buyer = $this->makeBuyer();
        $listing = $this->makeListing($this->makeSeller(), ['quantity' => 900]);
        $order = $this->makeOrder($buyer, $listing, ['status' => 'placed']);
        $this->makePayment($order, ['status' => 'pending']);
        Sanctum::actingAs($buyer);

        $this->postJson("/api/orders/{$order->order_number}/payment-cancelled")->assertOk();

        // Inside the window it survives a scheduler tick untouched.
        $this->artisan('orders:expire-unpaid')->assertSuccessful();
        $this->assertSame('placed', $order->fresh()->status);

        // Past it, the scheduler -- not the cancel redirect -- fails the order
        // and gives the reserved stock back.
        $order->forceFill(['created_at' => now()->subMinutes(31)])->save();
        $this->artisan('orders:expire-unpaid')->assertSuccessful();

        $this->assertSame('failed', $order->fresh()->status);
        $this->assertSame(900 + (int) $order->quantity, (int) $listing->fresh()->quantity);
        $this->assertNull($order->fresh()->payment_expires_at);
    }

    public function test_login_is_rate_limited_per_email(): void
    {
        $user = $this->makeBuyer();

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong'])->assertStatus(422);
        }

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(429)
            ->assertJsonFragment(['message' => 'Too many login attempts. Please wait a minute and try again.']);
    }

    public function test_login_tokens_expire_after_the_configured_period(): void
    {
        $this->assertSame(10080, config('sanctum.expiration'));
        $user = $this->makeBuyer();
        $token = $user->createToken('fishmarket')->plainTextToken;

        $this->travel(8)->days();

        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_seller_registration_decisions_are_emailed(): void
    {
        Mail::fake();
        $lgu = $this->makeLguAdmin();
        $approved = $this->makeSeller([], ['approval_status' => 'pending', 'verified' => false, 'status' => 'pending']);
        $rejected = $this->makeSeller([], ['approval_status' => 'pending', 'verified' => false, 'status' => 'pending']);
        Sanctum::actingAs($lgu);

        $this->patchJson("/api/lgu/sellers/{$approved->id}/approve-registration")->assertOk();
        $this->patchJson("/api/lgu/sellers/{$rejected->id}/reject-registration", ['reason' => 'Missing permit.'])->assertOk();

        Mail::assertSent(SellerRegistrationReviewedMail::class, fn ($mail) => $mail->hasTo($approved->user->email)
            && str_contains($mail->envelope()->subject, 'Approved'));
        Mail::assertSent(SellerRegistrationReviewedMail::class, fn ($mail) => $mail->hasTo($rejected->user->email)
            && str_contains($mail->envelope()->subject, 'Not Approved'));
    }

    public function test_seller_withdrawal_approval_is_emailed(): void
    {
        Mail::fake();
        $seller = $this->makeSeller();
        $withdrawal = $this->makeWithdrawal($seller);
        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());

        $this->patchJson("/api/super-admin/withdrawals/{$withdrawal->id}/approve")->assertOk();

        Mail::assertSent(SellerWithdrawalApprovedMail::class, fn ($mail) => $mail->hasTo($seller->user->email));
    }

    public function test_google_login_redirect_requests_the_account_chooser(): void
    {
        $response = $this->get('/api/auth/google/redirect');

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('accounts.google.com', $location);
        $this->assertStringContainsString('prompt=select_account', $location);
    }

    public function test_unverified_user_cannot_log_in(): void
    {
        $user = $this->makeBuyer(['email' => 'unverified@fishmarket.test', 'email_verified_at' => null]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'unverified@fishmarket.test',
            'password' => 'password',
        ]);

        $response->assertStatus(403)->assertJsonFragment(['unverified' => true, 'email' => $user->email]);
        $this->assertArrayNotHasKey('token', $response->json());
    }

    public function test_unverified_user_cannot_access_a_protected_route_even_with_a_token(): void
    {
        $user = $this->makeBuyer(['email_verified_at' => null]);
        Sanctum::actingAs($user);

        $this->getJson('/api/buyer/dashboard')->assertStatus(403);
    }

    public function test_email_verification_link_activates_the_account(): void
    {
        $user = $this->makeBuyer(['email_verified_at' => null]);
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $response = $this->get($url);

        $response->assertOk();
        $response->assertSee('Email Verified');
        $this->assertNotNull($user->fresh()->email_verified_at);

        // The now-verified account can log in immediately.
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
    }

    public function test_reusing_a_verification_link_shows_an_already_verified_message(): void
    {
        $user = $this->makeBuyer(['email_verified_at' => null]);
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->get($url)->assertOk()->assertSee('Email Verified');
        $second = $this->get($url);

        $second->assertOk();
        $second->assertSee('Already Verified');
    }

    public function test_tampered_verification_link_is_rejected(): void
    {
        $user = $this->makeBuyer(['email_verified_at' => null]);
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1('not-this-users-email'),
        ]);

        $response = $this->get($url);

        $response->assertOk();
        $response->assertSee('Invalid Link');
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_expired_verification_link_is_rejected_gracefully(): void
    {
        $user = $this->makeBuyer(['email_verified_at' => null]);
        $url = URL::temporarySignedRoute('verification.verify', now()->subMinutes(5), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $response = $this->get($url);

        $response->assertOk();
        $response->assertSee('Link Expired');
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_resend_verification_sends_a_new_email_for_an_unverified_account(): void
    {
        Notification::fake();
        $user = $this->makeBuyer(['email_verified_at' => null]);

        $response = $this->postJson('/api/email/resend', ['email' => $user->email]);

        $response->assertOk();
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_resend_verification_does_not_reveal_whether_an_email_is_registered(): void
    {
        Notification::fake();
        $verified = $this->makeBuyer();

        $unregistered = $this->postJson('/api/email/resend', ['email' => 'nobody@fishmarket.test']);
        $alreadyVerified = $this->postJson('/api/email/resend', ['email' => $verified->email]);

        $unregistered->assertOk();
        $alreadyVerified->assertOk();
        $this->assertSame($unregistered->json('message'), $alreadyVerified->json('message'));
        Notification::assertNothingSent();
    }

    public function test_google_registration_creates_a_new_verified_buyer_account(): void
    {
        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-new-1',
            'email' => 'new-google-user@fishmarket.test',
            'name' => 'New Google User',
        ]));

        $state = Crypt::encryptString(json_encode([
            'role' => 'buyer',
            'municipality_id' => null,
            'expires_at' => now()->addMinutes(10)->timestamp,
        ]));

        $response = $this->get('/api/auth/google/callback?state='.urlencode($state));

        $response->assertRedirect();
        $this->assertStringStartsWith(rtrim(config('app.frontend_url'), '/').'/auth/google/callback?token=', $response->headers->get('Location'));

        $user = User::where('email', 'new-google-user@fishmarket.test')->firstOrFail();
        $this->assertSame('buyer', $user->role);
        $this->assertSame('google-new-1', $user->google_id);
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseHas('buyer_profiles', ['user_id' => $user->id]);
    }

    public function test_google_sign_in_for_an_unknown_email_asks_for_a_role_instead_of_defaulting_to_buyer(): void
    {
        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-no-intent-1',
            'email' => 'no-intent@fishmarket.test',
            'name' => 'No Intent',
        ]));

        $response = $this->get('/api/auth/google/callback');

        $response->assertRedirect(
            rtrim(config('app.frontend_url'), '/').'/register?google_role_required=1&email='.urlencode('no-intent@fishmarket.test')
        );
        $this->assertDatabaseMissing('users', ['email' => 'no-intent@fishmarket.test']);
    }

    public function test_google_registration_rejects_a_seller_without_a_municipality(): void
    {
        $response = $this->get('/api/auth/google/redirect?registration=1&role=seller');

        $response->assertSessionHasErrors('municipality_id');
        $this->assertDatabaseMissing('users', ['email' => 'new-google-seller@fishmarket.test']);
    }

    public function test_google_registration_creates_a_pending_seller_in_the_selected_municipality(): void
    {
        $municipality = Municipality::firstOrFail();

        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-new-seller-1',
            'email' => 'new-google-seller@fishmarket.test',
            'name' => 'Google Hatchery',
        ]));

        $state = Crypt::encryptString(json_encode([
            'role' => 'seller',
            'municipality_id' => $municipality->id,
            'expires_at' => now()->addMinutes(10)->timestamp,
        ]));

        $response = $this->get('/api/auth/google/callback?state='.urlencode($state));

        $response->assertRedirect();

        $user = User::where('email', 'new-google-seller@fishmarket.test')->firstOrFail();
        $this->assertSame('seller', $user->role);
        $this->assertSame($municipality->id, $user->municipality_id);
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseMissing('buyer_profiles', ['user_id' => $user->id]);
        $this->assertDatabaseHas('seller_profiles', [
            'user_id' => $user->id,
            'municipality_id' => $municipality->id,
            'hatchery_name' => 'Google Hatchery',
            'status' => 'pending',
            'approval_status' => SellerApproval::PENDING,
        ]);
    }

    public function test_google_login_signs_into_the_existing_account_for_a_known_email_without_duplicating(): void
    {
        $existing = $this->makeBuyer(['email' => 'already-fishmarket@fishmarket.test']);
        $countBefore = User::count();

        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-existing-1',
            'email' => 'already-fishmarket@fishmarket.test',
            'name' => 'Ignored Name',
        ]));

        $response = $this->get('/api/auth/google/callback');

        $response->assertRedirect();
        $this->assertSame($countBefore, User::count());
        $this->assertSame('google-existing-1', $existing->fresh()->google_id);
    }

    public function test_google_login_blocks_a_suspended_seller(): void
    {
        $seller = $this->makeSeller(['email' => 'suspended-google-seller@fishmarket.test']);
        $seller->update(['status' => 'suspended']);

        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-seller-1',
            'email' => 'suspended-google-seller@fishmarket.test',
            'name' => 'Suspended Seller',
        ]));

        $response = $this->get('/api/auth/google/callback');

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('google_error=1', $location);
        $this->assertStringNotContainsString('token=', $location);
    }

    public function test_seller_can_update_status_of_their_own_order(): void
    {
        $sellerProfile = $this->makeSeller();
        $seller = $sellerProfile->user;
        $buyer = $this->makeBuyer();
        $listing = $this->makeListing($sellerProfile);
        $order = $this->makeOrder($buyer, $listing);
        // Only a paid order can be advanced at all -- see the unpaid-order
        // guard in OrderController::updateStatus. This test is about who may
        // update an order, not about whether an unpaid one can move.
        $this->makePayment($order, ['status' => 'paid_held']);
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

        $response = $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'cancelled', 'cancellation_reason' => 'Stock died before dispatch.']);

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

        $reinstate = $this->patchJson("/api/lgu/sellers/{$seller->id}/reinstate", ['reason' => 'Issue resolved']);
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
        $this->postListing([
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

    public function test_lgu_reports_graphs_are_scoped_to_municipality_and_respect_period_filter(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $seller = $this->makeSeller(
            ['municipality_id' => $lguAdmin->municipality_id],
            ['municipality_id' => $lguAdmin->municipality_id]
        );
        $listing = $this->makeListing($seller, ['species' => 'Bangus', 'approval_status' => 'approved']);
        $buyer = $this->makeBuyer();
        $this->makeOrder($buyer, $listing, ['status' => 'completed', 'total_amount' => 200]);

        $otherMunicipality = Municipality::where('id', '!=', $lguAdmin->municipality_id)->firstOrFail();
        $otherSeller = $this->makeSeller([], ['municipality_id' => $otherMunicipality->id]);
        $otherListing = $this->makeListing($otherSeller, ['species' => 'Carp', 'approval_status' => 'approved']);
        $this->makeOrder($this->makeBuyer(), $otherListing, ['status' => 'completed', 'total_amount' => 999]);

        Sanctum::actingAs($lguAdmin);
        $response = $this->getJson('/api/lgu/reports');

        $response->assertOk()
            ->assertJsonPath('period', 'monthly')
            ->assertJsonStructure(['listings_by_status', 'listings_by_species', 'sellers_by_status', 'orders_over_time'])
            // Existing all-time fields must remain untouched by this change.
            ->assertJsonStructure(['registered_sellers', 'buyers', 'listings', 'pending_approvals']);

        $species = collect($response->json('listings_by_species'))->pluck('species');
        $this->assertTrue($species->contains('Bangus'));
        $this->assertFalse($species->contains('Carp'));

        foreach (['daily', 'weekly', 'monthly', 'yearly'] as $period) {
            $this->getJson("/api/lgu/reports?period={$period}")->assertOk()->assertJsonPath('period', $period);
        }
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

        // Reviews & Ratings now returns both directions; only this municipality's
        // buyer review appears, and there are no seller-of-buyer ratings yet.
        $response->assertOk()->assertJsonCount(1, 'buyer_reviews')->assertJsonCount(0, 'seller_ratings');
        $response->assertJsonPath('buyer_reviews.0.rating', 4)
            ->assertJsonPath('buyer_reviews.0.buyer.name', 'Pedro Buyer')
            ->assertJsonPath('buyer_reviews.0.sellerProfile.hatchery_name', "Ana's Hatchery")
            ->assertJsonPath('buyer_reviews.0.sellerProfile.user.name', 'Ana Seller')
            ->assertJsonPath('buyer_reviews.0.order.listing.species', 'Tilapia')
            ->assertJsonPath('buyer_reviews.0.order.order_number', $order->order_number);
    }

    public function test_lgu_users_endpoint_reflects_real_email_verification_status(): void
    {
        // email_verified_at now reflects the real Laravel email-verification flow
        // (see AuthController::register/login) instead of always being null.
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $verifiedSeller = $this->makeSeller(['name' => 'Verified Seller', 'municipality_id' => $lguAdmin->municipality_id], ['municipality_id' => $lguAdmin->municipality_id]);
        $unverifiedSellerUser = $this->makeSeller(['name' => 'Unverified Seller', 'municipality_id' => $lguAdmin->municipality_id, 'email_verified_at' => null], ['municipality_id' => $lguAdmin->municipality_id])->user;
        Sanctum::actingAs($lguAdmin);

        $response = $this->getJson('/api/lgu/users');

        $response->assertOk();
        $sellers = collect($response->json('sellers'))->keyBy('id');
        $this->assertNotEmpty($sellers);
        $this->assertNotNull($sellers[$verifiedSeller->user_id]['email_verified_at']);
        $this->assertNull($sellers[$unverifiedSellerUser->id]['email_verified_at']);
    }

    public function test_super_admin_can_create_edit_and_disable_lgu_admin(): void
    {
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        $municipality = Municipality::firstOrFail();
        Sanctum::actingAs($superAdmin);

        $create = $this->postJson('/api/super-admin/lgu-admins', [
            'name' => 'New LGU Officer',
            'email' => 'new-lgu@fishmarket.test',
            'password' => 'Password123!',
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
            'password' => 'Password123!',
        ])->assertStatus(403);

        $enable = $this->patchJson("/api/super-admin/lgu-admins/{$adminId}/enable", ['reason' => 'Review completed']);
        $enable->assertOk()->assertJsonPath('status', 'active');

        $this->postJson('/api/auth/login', [
            'email' => 'new-lgu@fishmarket.test',
            'password' => 'Password123!',
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
            'password' => 'Password123!',
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

    /**
     * pending_payouts must count WithdrawalRequest rows still awaiting Super
     * Admin action (pending or approved-but-unpaid) -- NOT MockPayment rows
     * awaiting LGU earnings approval, which is an entirely separate queue
     * with no payout involved (see LguController::pendingEarnings).
     */
    public function test_pending_payouts_tracks_withdrawal_requests_not_lgu_earnings_approvals(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        $listing = $this->makeListing($seller);

        // A payment awaiting LGU earnings approval must NOT count as a pending payout.
        $order = $this->makeOrder($buyer, $listing, ['status' => 'completed']);
        $this->makePayment($order, ['status' => 'paid_held']);

        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
        $this->assertEquals(0, $this->getJson('/api/super-admin/reports')->json('pending_payouts'));

        // A pending withdrawal request DOES count.
        $pendingWithdrawal = $this->makeWithdrawal($seller, ['amount' => 50]);
        $this->assertEquals(1, $this->getJson('/api/super-admin/reports')->json('pending_payouts'));

        // An approved-but-not-yet-paid withdrawal still counts -- it's not done yet.
        $this->patchJson("/api/super-admin/withdrawals/{$pendingWithdrawal->id}/approve")->assertOk();
        $this->assertEquals(1, $this->getJson('/api/super-admin/reports')->json('pending_payouts'));

        // A second pending withdrawal brings the count to 2.
        $this->makeWithdrawal($seller, ['amount' => 30]);
        $this->assertEquals(2, $this->getJson('/api/super-admin/reports')->json('pending_payouts'));

        // Once paid, it drops out of the pending count.
        $this->patchJson("/api/super-admin/withdrawals/{$pendingWithdrawal->id}/paid")->assertOk();
        $this->assertEquals(1, $this->getJson('/api/super-admin/reports')->json('pending_payouts'));

        // A rejected withdrawal also drops out -- it's resolved, just not paid.
        $rejected = $this->makeWithdrawal($seller, ['amount' => 20]);
        $this->patchJson("/api/super-admin/withdrawals/{$rejected->id}/reject", ['reason' => 'Invalid account details.'])->assertOk();
        $this->assertEquals(1, $this->getJson('/api/super-admin/reports')->json('pending_payouts'));
    }

    public function test_super_admin_reports_graphs_span_every_municipality_and_respect_period_filter(): void
    {
        $sellerA = $this->makeSeller();
        $listingA = $this->makeListing($sellerA, ['species' => 'Bangus', 'approval_status' => 'approved']);
        $this->makeOrder($this->makeBuyer(), $listingA, ['status' => 'completed', 'total_amount' => 150]);

        $municipalityB = Municipality::where('id', '!=', $sellerA->municipality_id)->firstOrFail();
        $sellerB = $this->makeSeller([], ['municipality_id' => $municipalityB->id]);
        $listingB = $this->makeListing($sellerB, ['species' => 'Carp', 'approval_status' => 'pending']);
        $this->makeOrder($this->makeBuyer(), $listingB, ['status' => 'completed', 'total_amount' => 300]);

        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
        $response = $this->getJson('/api/super-admin/reports');

        $response->assertOk()
            ->assertJsonPath('period', 'monthly')
            ->assertJsonStructure([
                'listings_by_status', 'listings_by_species', 'sellers_by_status', 'orders_over_time',
                'listings_by_municipality', 'sellers_by_municipality', 'orders_by_municipality',
            ])
            // Existing all-time fields must remain untouched by this change.
            ->assertJsonStructure(['total_lgus', 'total_sellers', 'total_buyers', 'total_listings', 'total_transactions', 'pending_payouts']);

        $species = collect($response->json('listings_by_species'))->pluck('species');
        $this->assertTrue($species->contains('Bangus'));
        $this->assertTrue($species->contains('Carp'));

        $municipalityNames = collect($response->json('listings_by_municipality'))->pluck('municipality');
        $this->assertTrue($municipalityNames->contains($sellerA->municipality->name));
        $this->assertTrue($municipalityNames->contains($municipalityB->name));

        foreach (['daily', 'weekly', 'monthly', 'yearly'] as $period) {
            $this->getJson("/api/super-admin/reports?period={$period}")->assertOk()->assertJsonPath('period', $period);
        }
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
        // Advancing an order requires its payment to have reached escrow.
        $this->makePayment($order, ['status' => 'paid_held']);

        $this->buyerConfirmsReceipt($order, $seller);
        $this->assertSame('completed', $order->fresh()->status);

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

    public function test_lgu_admin_and_super_admin_can_browse_the_public_marketplace_read_only(): void
    {
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller, ['approval_status' => 'approved']);

        foreach (['lgu_admin', 'super_admin'] as $role) {
            Sanctum::actingAs(User::where('role', $role)->firstOrFail());

            $this->getJson('/api/listings')->assertOk()->assertJsonFragment(['id' => $listing->id]);
            $this->getJson("/api/listings/{$listing->id}")->assertOk();
            $this->getJson('/api/sellers')->assertOk()->assertJsonFragment(['id' => $seller->id]);
            $this->getJson("/api/sellers/{$seller->id}")->assertOk();

            // Neither role may place an order (purchase is a buyer-only action, enforced server-side).
            $this->postJson('/api/orders', ['fingerling_listing_id' => $listing->id, 'quantity' => 1])->assertStatus(403);
        }
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

    public function test_payment_success_emails_the_buyer_a_receipt_and_the_seller_a_new_order_notice(): void
    {
        Mail::fake();

        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        $listing = $this->makeListing($seller);
        $order = $this->makeOrder($buyer, $listing);
        $this->makePayment($order);
        Sanctum::actingAs($buyer);

        $this->postJson("/api/orders/{$order->order_number}/payment-success")->assertOk();

        Mail::assertSent(PaymentReceiptMail::class, fn ($mail) => $mail->hasTo($buyer->email) && $mail->order->is($order));
        Mail::assertSent(NewOrderReceivedMail::class, fn ($mail) => $mail->hasTo($seller->user->email) && $mail->order->is($order));
    }

    public function test_payment_success_does_not_email_a_receipt_twice_for_the_same_payment(): void
    {
        Mail::fake();

        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        $listing = $this->makeListing($seller);
        $order = $this->makeOrder($buyer, $listing);
        $this->makePayment($order);
        Sanctum::actingAs($buyer);

        // The frontend calls this after PayMongo's redirect, and the
        // webhook can also fire for the same payment -- only the first
        // transition into paid_held should trigger a receipt email. The second
        // call is now refused outright as a spent return link.
        $this->postJson("/api/orders/{$order->order_number}/payment-success")->assertOk();
        $this->postJson("/api/orders/{$order->order_number}/payment-success")->assertStatus(410);

        Mail::assertSent(PaymentReceiptMail::class, 1);
        Mail::assertSent(NewOrderReceivedMail::class, 1);
    }

    public function test_the_hosted_checkout_page_is_expired_once_the_payment_is_captured(): void
    {
        config(['services.paymongo.secret_key' => 'sk_test_fake']);
        Http::fake([
            'api.paymongo.com/v1/checkout_sessions/cs_test_paid' => Http::response(['data' => [
                'attributes' => ['payments' => [['attributes' => ['status' => 'paid']]]],
            ]]),
            'api.paymongo.com/*' => Http::response([]),
        ]);

        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        $listing = $this->makeListing($seller);
        $order = $this->makeOrder($buyer, $listing);
        $payment = $this->makePayment($order, [
            'status' => 'checkout_created',
            'provider_reference' => 'cs_test_paid',
            'checkout_url' => 'https://checkout.paymongo.com/cs_test_paid',
        ]);
        Sanctum::actingAs($buyer);

        $this->postJson("/api/orders/{$order->order_number}/payment-success", [
            't' => PaymentReturnToken::issue($order),
        ])->assertOk()->assertJsonPath('status', 'success');

        // The buyer's checkout.paymongo.com page is not ours to take down, so
        // it has to be expired through PayMongo, or it stays payable forever.
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://api.paymongo.com/v1/checkout_sessions/cs_test_paid/expire');

        // ...and we stop holding a copy of the dead URL.
        $this->assertSame('paid_held', $payment->fresh()->status);
        $this->assertNull($payment->fresh()->checkout_url);
    }

    public function test_resuming_payment_expires_the_previous_checkout_page(): void
    {
        config(['services.paymongo.secret_key' => 'sk_test_fake']);
        Http::fake([
            'api.paymongo.com/v1/checkout_sessions' => Http::response(['data' => [
                'id' => 'cs_test_second',
                'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_test_second'],
            ]]),
            'api.paymongo.com/*' => Http::response([]),
        ]);

        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        $listing = $this->makeListing($seller);
        $order = $this->makeOrder($buyer, $listing);
        $this->makePayment($order, [
            'status' => 'checkout_created',
            'provider_reference' => 'cs_test_first',
            'checkout_url' => 'https://checkout.paymongo.com/cs_test_first',
        ]);
        Sanctum::actingAs($buyer);

        // NB: the checkout route binds by id, not order_number.
        $this->postJson("/api/orders/{$order->id}/checkout")->assertOk();

        // Two live sessions for one order would let the buyer pay twice, so the
        // abandoned one is retired as the replacement is minted.
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://api.paymongo.com/v1/checkout_sessions/cs_test_first/expire');
        $this->assertSame('cs_test_second', $order->payment->fresh()->provider_reference);
    }

    public function test_an_expired_unpaid_order_takes_its_checkout_page_down_with_it(): void
    {
        config([
            'services.paymongo.secret_key' => 'sk_test_fake',
            'services.paymongo.unpaid_order_timeout_minutes' => 30,
        ]);
        Http::fake([
            // Unpaid, so the scheduler is allowed to expire the order.
            'api.paymongo.com/v1/checkout_sessions/cs_test_stale' => Http::response(['data' => [
                'attributes' => ['payments' => []],
            ]]),
            'api.paymongo.com/*' => Http::response([]),
        ]);

        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        $listing = $this->makeListing($seller);
        $order = $this->makeOrder($buyer, $listing);
        $payment = $this->makePayment($order, [
            'status' => 'checkout_created',
            'provider_reference' => 'cs_test_stale',
            'checkout_url' => 'https://checkout.paymongo.com/cs_test_stale',
        ]);
        // created_at is not fillable, so push it past the payment window here.
        $order->forceFill(['created_at' => now()->subMinutes(90)])->save();

        $this->artisan('orders:expire-unpaid')->assertExitCode(0);

        $this->assertSame('failed', $order->fresh()->status);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://api.paymongo.com/v1/checkout_sessions/cs_test_stale/expire');
        $this->assertNull($payment->fresh()->checkout_url);
    }

    public function test_a_payment_return_link_is_single_use_once_the_payment_is_settled(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        $listing = $this->makeListing($seller);
        $order = $this->makeOrder($buyer, $listing);
        $payment = $this->makePayment($order);
        Sanctum::actingAs($buyer);

        $token = PaymentReturnToken::issue($order);

        // First trip back from PayMongo: the token is valid and is burned.
        $this->postJson("/api/orders/{$order->order_number}/payment-success", ['t' => $token])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        // Re-opening the very same URL later -- from history or a bookmark --
        // must not render a fresh receipt, and must not append another audit
        // row to a payment that closed long ago.
        $logsAfterFirstUse = PaymentLog::where('payment_id', $payment->id)->count();

        $this->postJson("/api/orders/{$order->order_number}/payment-success", ['t' => $token])
            ->assertStatus(410)
            ->assertJsonPath('status', 'already_confirmed');

        $this->assertSame($logsAfterFirstUse, PaymentLog::where('payment_id', $payment->id)->count());
    }

    public function test_a_spent_return_link_still_captures_a_payment_that_never_reached_escrow(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        $listing = $this->makeListing($seller);
        $order = $this->makeOrder($buyer, $listing);
        $payment = $this->makePayment($order);
        Sanctum::actingAs($buyer);

        // No token at all (a checkout minted before tokens existed, or a lost
        // query string). Money that is not in escrow yet must still be
        // captured -- the replay guard protects the receipt, never the capture.
        $this->postJson("/api/orders/{$order->order_number}/payment-success")
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertSame('paid_held', $payment->fresh()->status);
    }

    public function test_a_spent_cancel_link_does_not_report_a_decline_after_the_order_was_paid(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        $listing = $this->makeListing($seller);
        $order = $this->makeOrder($buyer, $listing, ['status' => 'paid']);
        $this->makePayment($order, ['status' => 'paid_held']);
        Sanctum::actingAs($buyer);

        $this->postJson("/api/orders/{$order->order_number}/payment-cancelled")
            ->assertStatus(410)
            ->assertJsonPath('status', 'already_confirmed');

        $this->assertSame(0, AppNotification::where('user_id', $buyer->id)
            ->where('type', 'payment_failed')->count());
    }

    public function test_order_confirmation_email_sends_when_seller_confirms_and_not_on_repeat_updates(): void
    {
        Mail::fake();

        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        $listing = $this->makeListing($seller);
        $order = $this->makeOrder($buyer, $listing, ['status' => 'paid']);
        // Advancing an order requires its payment to have reached escrow.
        $this->makePayment($order, ['status' => 'paid_held']);
        Sanctum::actingAs($seller->user);

        $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'confirmed'])->assertOk();
        // Re-sending the same status must not send a second confirmation email.
        $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'confirmed'])->assertOk();

        Mail::assertSent(OrderConfirmedMail::class, fn ($mail) => $mail->hasTo($buyer->email) && $mail->order->is($order));
        Mail::assertSent(OrderConfirmedMail::class, 1);
    }

    public function test_delivery_email_sends_when_seller_marks_order_completed(): void
    {
        Mail::fake();

        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        $listing = $this->makeListing($seller);
        $order = $this->makeOrder($buyer, $listing, ['status' => 'in_transit']);
        // Advancing an order requires its payment to have reached escrow.
        $this->makePayment($order, ['status' => 'paid_held']);
        Sanctum::actingAs($seller->user);

        $this->buyerConfirmsReceipt($order, $seller->user);

        Mail::assertSent(OrderDeliveredMail::class, fn ($mail) => $mail->hasTo($buyer->email) && $mail->order->is($order));
    }

    public function test_listing_approval_and_rejection_email_the_seller(): void
    {
        Mail::fake();

        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $seller = $this->makeSeller(['municipality_id' => $lguAdmin->municipality_id], ['municipality_id' => $lguAdmin->municipality_id]);
        $approvedListing = $this->makeListing($seller, ['species' => 'Tilapia', 'approval_status' => 'pending']);
        $rejectedListing = $this->makeListing($seller, ['species' => 'Bangus', 'approval_status' => 'pending']);
        Sanctum::actingAs($lguAdmin);

        $this->patchJson("/api/lgu/listings/{$approvedListing->id}/approve")->assertOk();
        $this->patchJson("/api/lgu/listings/{$rejectedListing->id}/reject", ['reason' => 'Missing water quality documentation.'])->assertOk();

        Mail::assertSent(ListingApprovedMail::class, fn ($mail) => $mail->hasTo($seller->user->email) && $mail->listing->is($approvedListing));
        Mail::assertSent(ListingRejectedMail::class, fn ($mail) => $mail->hasTo($seller->user->email) && $mail->listing->is($rejectedListing));
    }

    public function test_super_admin_listing_approval_and_rejection_email_the_seller(): void
    {
        Mail::fake();

        $seller = $this->makeSeller();
        $approvedListing = $this->makeListing($seller, ['approval_status' => 'pending']);
        $rejectedListing = $this->makeListing($seller, ['approval_status' => 'pending']);
        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());

        $this->patchJson("/api/super-admin/listings/{$approvedListing->id}/approve")->assertOk();
        $this->patchJson("/api/super-admin/listings/{$rejectedListing->id}/reject")->assertOk();

        Mail::assertSent(ListingApprovedMail::class);
        Mail::assertSent(ListingRejectedMail::class);
    }

    public function test_withdrawal_release_emails_the_seller_with_wallet_details(): void
    {
        Mail::fake();

        $seller = $this->makeSeller();
        $withdrawal = $this->makeWithdrawal($seller, ['amount' => 25]); // Platform Payout Fee: 6% of ₱25 = ₱1.50.
        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());

        $this->patchJson("/api/super-admin/withdrawals/{$withdrawal->id}/approve")->assertOk();
        $this->patchJson("/api/super-admin/withdrawals/{$withdrawal->id}/paid")->assertOk();

        Mail::assertSent(WithdrawalReleasedMail::class, fn ($mail) => $mail->hasTo($seller->user->email) && $mail->withdrawal->is($withdrawal));

        $html = (new WithdrawalReleasedMail($withdrawal->fresh()))->render();
        $this->assertStringContainsString('25.00', $html);
        $this->assertStringContainsString('1.50', $html); // Platform Payout Fee.
        $this->assertStringContainsString('23.50', $html); // Amount Received (net).
    }

    public function test_lgu_earnings_approval_sends_the_seller_earnings_approved_email_not_a_payout_email(): void
    {
        Mail::fake();

        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller([], ['municipality_id' => $lguAdmin->municipality_id]);
        $listing = $this->makeListing($seller);
        $order = $this->makeOrder($buyer, $listing, ['status' => 'completed']);
        $payment = $this->makePayment($order, ['status' => 'paid_held']);

        Sanctum::actingAs($lguAdmin);
        $this->patchJson("/api/lgu/payments/{$payment->id}/approve")->assertOk();

        $settlement = Settlement::where('order_id', $order->id)->firstOrFail();
        Mail::assertSent(SellerEarningsApprovedMail::class, fn ($mail) => $mail->hasTo($seller->user->email) && $mail->settlement->is($settlement));
        // The withdrawal-payout email must never fire from an earnings approval -- they are distinct events.
        Mail::assertNotSent(WithdrawalReleasedMail::class);
    }

    public function test_seller_earnings_approved_email_explains_pending_to_available_and_is_not_a_payout(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller([], ['municipality_id' => $lguAdmin->municipality_id]);
        $listing = $this->makeListing($seller, ['species' => 'Tilapia']);
        $order = $this->makeOrder($buyer, $listing, ['status' => 'completed', 'total_amount' => 500]);
        $payment = $this->makePayment($order, ['status' => 'released', 'amount' => 500, 'released_at' => now()]);
        $settlement = $this->makeSettlement($order, $payment); // Seller Share: 96% of ₱500 = ₱480.

        $mailable = new SellerEarningsApprovedMail($settlement);

        $this->assertSame('Seller Earnings Approved', $mailable->envelope()->subject);

        $html = $mailable->render();
        $plainText = strtolower(strip_tags($html));
        $this->assertStringContainsString($order->order_number, $html);
        $this->assertStringContainsString('Pending Balance', $html);
        $this->assertStringContainsString('Available Balance', $html);
        $this->assertStringContainsString('96% Seller Share', $html);
        $this->assertStringContainsString('not a bank or e-wallet payout yet', $plainText);
        $this->assertStringNotContainsString('Withdrawal Has Been Successfully Processed', $html);

        // Must show only the Seller Share -- never the ₱500 gross amount, and
        // never the LGU Share, which a seller must never be able to infer.
        $this->assertStringContainsString('480', $html);
        $this->assertStringNotContainsString('500.00', $html);
        $this->assertStringNotContainsString('₱20.00', $html); // LGU Share (4% of ₱500).
    }

    public function test_withdrawal_released_email_is_sent_only_after_payout(): void
    {
        Mail::fake();

        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller);
        $order = $this->makeOrder($buyer, $listing, ['status' => 'completed']);
        $payment = $this->makePayment($order, ['status' => 'released', 'amount' => 300]);
        $this->makeSettlement($order, $payment); // Seller Share: 96% of ₱300 = ₱288.
        Sanctum::actingAs($seller->user);

        $withdrawal = $this->postJson('/api/seller/withdrawals', [
            'method' => 'gcash',
            'account_name' => 'Test Seller',
            'account_number' => '09171234567',
            'amount' => 200,
        ])->assertCreated()->json();

        Mail::assertNothingSent();

        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
        $this->patchJson("/api/super-admin/withdrawals/{$withdrawal['id']}/approve")->assertOk();

        // "Approved" only means the Super Admin is processing it -- the seller
        // gets the approval email, but never a "funds released" one yet.
        Mail::assertSent(SellerWithdrawalApprovedMail::class, fn ($mail) => $mail->hasTo($seller->user->email));
        Mail::assertNotSent(WithdrawalReleasedMail::class);

        $this->patchJson("/api/super-admin/withdrawals/{$withdrawal['id']}/paid")->assertOk();

        Mail::assertSent(WithdrawalReleasedMail::class, fn ($mail) => $mail->hasTo($seller->user->email));
    }

    public function test_transactional_emails_render_a_responsive_branded_html_document(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        $listing = $this->makeListing($seller);
        $order = $this->makeOrder($buyer, $listing);

        $html = (new PaymentReceiptMail($order))->render();

        $this->assertStringContainsString('name="viewport"', $html);
        $this->assertStringContainsString('@media only screen and (max-width: 600px)', $html);
        $this->assertStringContainsString('AbaiMarket', $html);
        $this->assertStringContainsString($order->order_number, $html);
    }

    public function test_email_failure_does_not_interrupt_checkout(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP unreachable'));

        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        $listing = $this->makeListing($seller);
        $order = $this->makeOrder($buyer, $listing);
        $this->makePayment($order);
        Sanctum::actingAs($buyer);

        // The payment confirmation must still succeed even though mail sending throws.
        $this->postJson("/api/orders/{$order->order_number}/payment-success")
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertSame('paid_held', $order->payment->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Marketplace Revenue Sharing
    // ------------------------------------------------------------------

    public function test_commission_calculator_split_always_sums_exactly_to_the_gross_amount(): void
    {
        $split = CommissionCalculator::split(0.05);

        $this->assertEquals(0.05, round($split['seller_share'] + $split['lgu_share'], 2));
        // 96% of ₱0.05 = ₱0.048, which rounds to ₱0.05 (nearest centavo).
        $this->assertEquals(0.05, $split['seller_share']);
        // The LGU absorbs whatever the rounding of the seller share leaves over.
        $this->assertEquals(0.00, $split['lgu_share']);
        // The Platform takes nothing at settlement -- its revenue is a withdrawal fee instead.
        $this->assertEquals(0.00, $split['platform_share']);
    }

    public function test_commission_calculator_uses_the_fixed_96_4_settlement_split(): void
    {
        $split = CommissionCalculator::split(1000);

        $this->assertEquals(960, $split['seller_share']);
        $this->assertEquals(40, $split['lgu_share']);
        $this->assertEquals(0, $split['platform_share']);
        $this->assertEquals(96, $split['seller_percent']);
        $this->assertEquals(4, $split['lgu_percent']);
        $this->assertEquals(0, $split['platform_percent']);

        $this->assertEquals(96.0, CommissionCalculator::SELLER_PERCENT);
        $this->assertEquals(4.0, CommissionCalculator::LGU_PERCENT);
        $this->assertEquals(6.0, CommissionCalculator::WITHDRAWAL_FEE_PERCENT);
    }

    public function test_commission_calculator_computes_the_withdrawal_fee_on_the_requested_amount(): void
    {
        $fee = CommissionCalculator::withdrawalFee(1000);

        $this->assertEquals(60, $fee['fee']);
        $this->assertEquals(940, $fee['net_amount']);
        $this->assertEquals(1000, round($fee['fee'] + $fee['net_amount'], 2));
    }

    /**
     * There is deliberately no Super Admin UI or endpoint to manage
     * commission percentages -- the split is fixed in code (see
     * CommissionCalculator). These routes must not exist for any role.
     */
    public function test_commission_settings_management_endpoints_no_longer_exist(): void
    {
        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());

        $this->getJson('/api/super-admin/commission-settings')->assertStatus(404);
        $this->postJson('/api/super-admin/commission-settings', ['seller_percent' => 80, 'lgu_percent' => 10, 'platform_percent' => 10])->assertStatus(404);
    }

    /**
     * Even though the split can no longer be changed at runtime, every
     * settlement still freezes the percentages it used at creation time --
     * this proves a settlement's own stored numbers are self-contained and
     * never re-derived from CommissionCalculator after the fact. The
     * Platform takes nothing at settlement -- see the withdrawal-fee tests
     * for where its revenue actually comes from.
     */
    public function test_settlement_permanently_freezes_the_percentages_used_at_approval_time(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller([], ['municipality_id' => $lguAdmin->municipality_id]);
        $listing = $this->makeListing($seller, ['price_per_piece' => 100, 'quantity' => 100]);

        Sanctum::actingAs($buyer);
        $order = $this->postJson('/api/orders', ['fingerling_listing_id' => $listing->id, 'quantity' => 1])->assertCreated()->json();
        $this->postJson("/api/orders/{$order['order_number']}/payment-success")->assertOk();
        $this->buyerConfirmsReceipt($order['id'], $seller->user);
        $payment = MockPayment::whereHas('order', fn ($q) => $q->where('id', $order['id']))->firstOrFail();
        Sanctum::actingAs($lguAdmin);
        $this->patchJson("/api/lgu/payments/{$payment->id}/approve")->assertOk();

        $this->assertDatabaseHas('settlements', [
            'order_id' => $order['id'],
            'gross_amount' => 100,
            'seller_share' => 96,
            'lgu_share' => 4,
            'platform_share' => 0,
            'seller_percent' => 96,
            'lgu_percent' => 4,
            'platform_percent' => 0,
        ]);

        Sanctum::actingAs($seller->user);
        $wallet = $this->getJson('/api/seller/wallet')->assertOk()->json();
        $this->assertEquals(96, $wallet['available_balance']);
    }

    /**
     * The withdrawal fee is frozen onto the WithdrawalRequest at the moment
     * it's requested, exactly like Settlement freezes its percentages -- so
     * it never changes even if CommissionCalculator::WITHDRAWAL_FEE_PERCENT
     * is edited afterward.
     */
    public function test_withdrawal_request_freezes_the_platform_fee_at_request_time(): void
    {
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller);
        $order = $this->makeOrder($buyer, $listing, ['status' => 'completed']);
        $payment = $this->makePayment($order, ['status' => 'released', 'amount' => 1000]);
        $this->makeSettlement($order, $payment); // Seller Share: 96% of ₱1000 = ₱960.

        Sanctum::actingAs($seller->user);
        $response = $this->postJson('/api/seller/withdrawals', [
            'method' => 'gcash', 'account_name' => 'Test Seller', 'account_number' => '09171234567', 'amount' => 500,
        ]);

        $response->assertCreated()
            ->assertJsonPath('amount', '500.00')
            ->assertJsonPath('platform_fee', '30.00')
            ->assertJsonPath('net_amount', 470);
    }

    public function test_lgu_dashboard_and_reports_reveal_only_their_own_municipalitys_lgu_share(): void
    {
        $cordova = Municipality::where('name', 'Cordova')->firstOrFail();
        $mandaue = Municipality::where('name', 'Mandaue')->firstOrFail();
        $cordovaAdmin = $this->makeLguAdmin(['municipality_id' => $cordova->id]);
        $mandaueAdmin = $this->makeLguAdmin(['municipality_id' => $mandaue->id]);

        $cordovaSeller = $this->makeSeller([], ['municipality_id' => $cordova->id]);
        $mandaueSeller = $this->makeSeller([], ['municipality_id' => $mandaue->id]);
        $buyer = $this->makeBuyer();

        $cordovaOrder = $this->makeOrder($buyer, $this->makeListing($cordovaSeller), ['status' => 'completed', 'total_amount' => 1000]);
        $cordovaPayment = $this->makePayment($cordovaOrder, ['status' => 'released', 'amount' => 1000]);
        $this->makeSettlement($cordovaOrder, $cordovaPayment); // LGU Share: 4% of ₱1000 = ₱40.

        $mandaueOrder = $this->makeOrder($buyer, $this->makeListing($mandaueSeller), ['status' => 'completed', 'total_amount' => 5000]);
        $mandauePayment = $this->makePayment($mandaueOrder, ['status' => 'released', 'amount' => 5000]);
        $this->makeSettlement($mandaueOrder, $mandauePayment); // LGU Share: 4% of ₱5000 = ₱200.

        Sanctum::actingAs($cordovaAdmin);
        $dashboard = $this->getJson('/api/lgu/dashboard')->assertOk();
        $this->assertEquals(40, $dashboard->json('municipality_revenue.total_revenue'));
        $this->assertEquals(1, $dashboard->json('municipality_revenue.total_completed_orders'));

        $reports = $this->getJson('/api/lgu/reports')->assertOk();
        $this->assertEquals(40, $reports->json('revenue_cards.total_revenue'));

        // Cordova's dashboard/reports must never mention Mandaue's larger revenue figure.
        $this->assertStringNotContainsString('200', json_encode($dashboard->json('municipality_revenue')));

        // The response must never surface the Platform Share or the gross amount to an LGU.
        $this->assertArrayNotHasKey('platform_share', $dashboard->json('municipality_revenue'));
        $this->assertArrayNotHasKey('gross_amount', $dashboard->json('municipality_revenue'));

        Sanctum::actingAs($mandaueAdmin);
        $mandaueDashboard = $this->getJson('/api/lgu/dashboard')->assertOk();
        $this->assertEquals(200, $mandaueDashboard->json('municipality_revenue.total_revenue'));
    }

    /**
     * Platform Revenue is deliberately realized only once a seller's
     * withdrawal has actually been paid out -- not at settlement time like
     * Seller Share and LGU Share. This is the core behavior a Super Admin
     * must see: settled-but-unwithdrawn earnings must never inflate
     * Platform Revenue.
     */
    public function test_platform_revenue_is_zero_until_a_sellers_withdrawal_is_actually_paid(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        $order = $this->makeOrder($buyer, $this->makeListing($seller), ['status' => 'completed', 'total_amount' => 1000]);
        $payment = $this->makePayment($order, ['status' => 'released', 'amount' => 1000]);
        $this->makeSettlement($order, $payment); // Seller Share 960, LGU Share 40, Platform Share 0 -- all settled, nothing withdrawn.

        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
        $dashboard = $this->getJson('/api/super-admin/dashboard')->assertOk();

        // Gross Marketplace Revenue realizes at settlement -- non-zero immediately.
        $this->assertEquals(1000, $dashboard->json('platform_revenue.gross_marketplace_revenue'));
        // Platform Revenue must NOT realize yet -- the seller hasn't withdrawn anything.
        $this->assertEquals(0, $dashboard->json('platform_revenue.today_platform_revenue'));
        $this->assertEquals(0, $dashboard->json('platform_revenue.monthly_platform_revenue'));
        $this->assertEquals(0, $dashboard->json('platform_revenue.total_platform_revenue'));

        // The seller withdraws ₱450 of their ₱960 Seller Share.
        Sanctum::actingAs($seller->user);
        $withdrawal = $this->postJson('/api/seller/withdrawals', [
            'method' => 'gcash', 'account_name' => 'Test Seller', 'account_number' => '09171234567', 'amount' => 450,
        ])->assertCreated()->json();

        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
        $this->patchJson("/api/super-admin/withdrawals/{$withdrawal['id']}/approve")->assertOk();

        // Still not realized -- "approved" is not "paid" yet.
        $stillZero = $this->getJson('/api/super-admin/dashboard')->assertOk();
        $this->assertEquals(0, $stillZero->json('platform_revenue.total_platform_revenue'));

        $this->patchJson("/api/super-admin/withdrawals/{$withdrawal['id']}/paid")->assertOk();

        // 6% payout fee on the ₱450 withdrawal = ₱27 of Platform Revenue is now realized.
        $after = $this->getJson('/api/super-admin/dashboard')->assertOk();
        $this->assertEquals(27, $after->json('platform_revenue.today_platform_revenue'));
        $this->assertEquals(27, $after->json('platform_revenue.monthly_platform_revenue'));
        $this->assertEquals(27, $after->json('platform_revenue.total_platform_revenue'));
        // Gross Marketplace Revenue is unaffected by the withdrawal -- still the full settled gross.
        $this->assertEquals(1000, $after->json('platform_revenue.gross_marketplace_revenue'));
    }

    public function test_super_admin_dashboard_and_reports_expose_realized_platform_revenue_across_municipalities(): void
    {
        $cordova = Municipality::where('name', 'Cordova')->firstOrFail();
        $mandaue = Municipality::where('name', 'Mandaue')->firstOrFail();
        $cordovaSeller = $this->makeSeller([], ['municipality_id' => $cordova->id]);
        $mandaueSeller = $this->makeSeller([], ['municipality_id' => $mandaue->id]);
        $buyer = $this->makeBuyer();

        $cordovaOrder = $this->makeOrder($buyer, $this->makeListing($cordovaSeller), ['status' => 'completed', 'total_amount' => 1000]);
        $cordovaPayment = $this->makePayment($cordovaOrder, ['status' => 'released', 'amount' => 1000]);
        $this->makeSettlement($cordovaOrder, $cordovaPayment); // Seller Share 960.

        $mandaueOrder = $this->makeOrder($buyer, $this->makeListing($mandaueSeller), ['status' => 'completed', 'total_amount' => 5000]);
        $mandauePayment = $this->makePayment($mandaueOrder, ['status' => 'released', 'amount' => 5000]);
        $this->makeSettlement($mandaueOrder, $mandauePayment); // Seller Share 4800.

        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());

        // Nothing withdrawn yet -- Platform Revenue must be zero even though ₱6000 is settled.
        $dashboard = $this->getJson('/api/super-admin/dashboard')->assertOk();
        $this->assertEquals(0, $dashboard->json('platform_revenue.total_platform_revenue'));
        $this->assertEquals(6000, $dashboard->json('platform_revenue.gross_marketplace_revenue'));

        // Cordova's seller withdraws and is paid their full ₱960 Seller Share -- fee: 6% of ₱960 = ₱57.60.
        $this->makeWithdrawal($cordovaSeller, ['amount' => 960, 'status' => 'paid', 'paid_at' => now()]);
        // Mandaue's seller withdraws and is paid only half (₱2400) of their ₱4800 Seller Share -- fee: 6% of ₱2400 = ₱144.
        $this->makeWithdrawal($mandaueSeller, ['amount' => 2400, 'status' => 'paid', 'paid_at' => now()]);

        $dashboard = $this->getJson('/api/super-admin/dashboard')->assertOk();
        // Realized: 57.60 + 144 = 201.60.
        $this->assertEquals(201.6, $dashboard->json('platform_revenue.total_platform_revenue'));
        $this->assertEquals(6000, $dashboard->json('platform_revenue.gross_marketplace_revenue'));

        $reports = $this->getJson('/api/super-admin/reports')->assertOk();
        $this->assertEquals(201.6, $reports->json('revenue_cards.total_platform_revenue'));
        $municipalityRevenue = collect($reports->json('revenue_by_municipality'))->keyBy('municipality');
        $this->assertEquals(57.6, $municipalityRevenue['Cordova']['amount']);
        $this->assertEquals(144, $municipalityRevenue['Mandaue']['amount']);
    }

    public function test_lgu_ai_assistant_reports_municipality_revenue_using_lgu_share_only(): void
    {
        config(['services.gemini.api_key' => null]);

        $mandaue = Municipality::where('name', 'Mandaue')->firstOrFail();
        $seller = $this->makeSeller([], ['municipality_id' => $mandaue->id]);
        $buyer = $this->makeBuyer();
        $order = $this->makeOrder($buyer, $this->makeListing($seller), ['status' => 'completed', 'total_amount' => 1000]);
        $payment = $this->makePayment($order, ['status' => 'released', 'amount' => 1000]);
        $this->makeSettlement($order, $payment); // LGU Share: 4% of ₱1000 = ₱40.

        $mandaueAdmin = $this->makeLguAdmin(['municipality_id' => $mandaue->id]);
        Sanctum::actingAs($mandaueAdmin);

        $response = $this->postJson('/api/ai-assistant/ask', ['question' => 'What is our municipality revenue?']);

        $response->assertCreated();
        $this->assertStringContainsString('40', $response->json('response'));
        // Must never leak the gross amount (1000) into the LGU's own revenue answer -- only the LGU Share.
        $this->assertStringNotContainsString('1000', $response->json('response'));
    }

    public function test_super_admin_ai_assistant_distinguishes_platform_revenue_from_gross_marketplace_revenue(): void
    {
        config(['services.gemini.api_key' => null]);

        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        $order = $this->makeOrder($buyer, $this->makeListing($seller), ['status' => 'completed', 'total_amount' => 1000]);
        $payment = $this->makePayment($order, ['status' => 'released', 'amount' => 1000]);
        $this->makeSettlement($order, $payment); // Seller Share 960, Platform Share 0 -- settled, not yet withdrawn.

        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());

        // Platform Revenue must read as zero before any withdrawal is paid, even though the order is already settled.
        $beforeWithdrawal = $this->postJson('/api/ai-assistant/ask', ['question' => 'What is our platform revenue?']);
        $beforeWithdrawal->assertCreated();
        $this->assertStringContainsString('₱0', $beforeWithdrawal->json('response'));

        // Seller withdraws and is paid their full ₱960 Seller Share -- fee: 6% of ₱960 = ₱57.60.
        $this->makeWithdrawal($seller, ['amount' => 960, 'status' => 'paid', 'paid_at' => now()]);

        $platformResponse = $this->postJson('/api/ai-assistant/ask', ['question' => 'What is our platform revenue?']);
        $platformResponse->assertCreated();
        $this->assertStringContainsString('57.6', $platformResponse->json('response'));

        $grossResponse = $this->postJson('/api/ai-assistant/ask', ['question' => 'What is our gross marketplace revenue?']);
        $grossResponse->assertCreated();
        $this->assertStringContainsString('1000', $grossResponse->json('response'));
    }

    // ------------------------------------------------------------------
    // LGU Revenue Withdrawal System
    // ------------------------------------------------------------------

    public function test_lgu_wallet_reports_available_balance_and_total_revenue_from_lgu_share_only(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $municipalityId = $lguAdmin->municipality_id;
        $seller = $this->makeSeller([], ['municipality_id' => $municipalityId]);
        $buyer = $this->makeBuyer();

        $order = $this->makeOrder($buyer, $this->makeListing($seller), ['status' => 'completed', 'total_amount' => 1000]);
        $payment = $this->makePayment($order, ['status' => 'released', 'amount' => 1000]);
        $this->makeSettlement($order, $payment); // LGU Share: 4% of ₱1000 = ₱40.

        Sanctum::actingAs($lguAdmin);
        $response = $this->getJson('/api/lgu/wallet');

        $response->assertOk()
            ->assertJsonPath('total_revenue', 40)
            ->assertJsonPath('available_balance', 40)
            ->assertJsonPath('pending_balance', 0)
            ->assertJsonPath('processing_amount', 0)
            ->assertJsonPath('withdrawn_amount', 0);

        // Revenue history must show the settlement's LGU Share, never the
        // gross amount, the Seller Share, or the Platform Share.
        $this->assertEquals(40, $response->json('revenue_history.0.lgu_share'));
        $this->assertEquals(1000, $response->json('revenue_history.0.gross_amount'));
    }

    public function test_lgu_can_submit_withdrawal_request_within_available_balance(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $municipalityId = $lguAdmin->municipality_id;
        $seller = $this->makeSeller([], ['municipality_id' => $municipalityId]);
        $buyer = $this->makeBuyer();

        $order = $this->makeOrder($buyer, $this->makeListing($seller), ['status' => 'completed', 'total_amount' => 1000]);
        $payment = $this->makePayment($order, ['status' => 'released', 'amount' => 1000]);
        $this->makeSettlement($order, $payment); // LGU Share: 40.

        Sanctum::actingAs($lguAdmin);
        $response = $this->postJson('/api/lgu/withdrawals', [
            'method' => 'gcash',
            'account_name' => 'Municipal Treasury',
            'account_number' => '09171234567',
            'amount' => 25,
        ]);

        // No platform fee on LGU withdrawals -- the full amount is what gets paid.
        $response->assertCreated()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('amount', '25.00');
        $this->assertDatabaseHas('lgu_withdrawal_requests', [
            'municipality_id' => $municipalityId,
            'requested_by' => $lguAdmin->id,
            'amount' => 25,
            'status' => 'pending',
        ]);

        $wallet = $this->getJson('/api/lgu/wallet')->assertOk();
        $wallet->assertJsonPath('available_balance', 15);
    }

    public function test_lgu_cannot_submit_withdrawal_request_exceeding_available_balance(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $municipalityId = $lguAdmin->municipality_id;
        $seller = $this->makeSeller([], ['municipality_id' => $municipalityId]);
        $buyer = $this->makeBuyer();

        $order = $this->makeOrder($buyer, $this->makeListing($seller), ['status' => 'completed', 'total_amount' => 1000]);
        $payment = $this->makePayment($order, ['status' => 'released', 'amount' => 1000]);
        $this->makeSettlement($order, $payment); // LGU Share: 40.

        Sanctum::actingAs($lguAdmin);
        $this->postJson('/api/lgu/withdrawals', [
            'method' => 'gcash', 'account_name' => 'Municipal Treasury', 'account_number' => '09171234567', 'amount' => 500,
        ])->assertStatus(422);
    }

    public function test_lgu_withdrawal_amount_must_be_greater_than_zero(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();

        Sanctum::actingAs($lguAdmin);
        $this->postJson('/api/lgu/withdrawals', [
            'method' => 'gcash', 'account_name' => 'Municipal Treasury', 'account_number' => '09171234567', 'amount' => 0,
        ])->assertStatus(422);
        $this->postJson('/api/lgu/withdrawals', [
            'method' => 'gcash', 'account_name' => 'Municipal Treasury', 'account_number' => '09171234567', 'amount' => -10,
        ])->assertStatus(422);
    }

    public function test_lgu_cannot_submit_duplicate_pending_withdrawal_request(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $municipalityId = $lguAdmin->municipality_id;
        $seller = $this->makeSeller([], ['municipality_id' => $municipalityId]);
        $buyer = $this->makeBuyer();

        $order = $this->makeOrder($buyer, $this->makeListing($seller), ['status' => 'completed', 'total_amount' => 2000]);
        $payment = $this->makePayment($order, ['status' => 'released', 'amount' => 2000]);
        $this->makeSettlement($order, $payment); // LGU Share: 80.

        Sanctum::actingAs($lguAdmin);
        $this->postJson('/api/lgu/withdrawals', [
            'method' => 'gcash', 'account_name' => 'Municipal Treasury', 'account_number' => '09171234567', 'amount' => 10,
        ])->assertCreated();

        $second = $this->postJson('/api/lgu/withdrawals', [
            'method' => 'gcash', 'account_name' => 'Municipal Treasury', 'account_number' => '09171234567', 'amount' => 10,
        ]);
        $second->assertStatus(422);
        $this->assertDatabaseCount('lgu_withdrawal_requests', 1);
    }

    public function test_super_admin_can_approve_and_reject_lgu_withdrawal_requests(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $withdrawal = $this->makeLguWithdrawal($lguAdmin->municipality_id, ['amount' => 30]);
        $otherWithdrawal = $this->makeLguWithdrawal($lguAdmin->municipality_id, ['amount' => 15, 'method' => 'maya']);

        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());

        $this->getJson('/api/super-admin/lgu-withdrawals')->assertOk()->assertJsonCount(2);

        $this->patchJson("/api/super-admin/lgu-withdrawals/{$withdrawal->id}/approve")
            ->assertOk()->assertJsonPath('status', 'approved');
        $this->patchJson("/api/super-admin/lgu-withdrawals/{$otherWithdrawal->id}/reject", ['reason' => 'Bank details unclear.'])
            ->assertOk()
            ->assertJsonPath('status', 'rejected')
            ->assertJsonPath('rejection_reason', 'Bank details unclear.');

        $this->assertDatabaseHas('notifications', ['user_id' => $lguAdmin->id, 'type' => 'lgu_withdrawal_approved']);
        $rejectedNotification = AppNotification::where('user_id', $lguAdmin->id)->where('type', 'lgu_withdrawal_rejected')->firstOrFail();
        $this->assertStringContainsString('Bank details unclear.', $rejectedNotification->body);
    }

    public function test_super_admin_can_mark_lgu_withdrawal_paid_and_it_reflects_in_the_wallet(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $municipalityId = $lguAdmin->municipality_id;
        $seller = $this->makeSeller([], ['municipality_id' => $municipalityId]);
        $buyer = $this->makeBuyer();
        $order = $this->makeOrder($buyer, $this->makeListing($seller), ['status' => 'completed', 'total_amount' => 1000]);
        $payment = $this->makePayment($order, ['status' => 'released', 'amount' => 1000]);
        $this->makeSettlement($order, $payment); // LGU Share: 40.

        $withdrawal = $this->makeLguWithdrawal($municipalityId, ['requested_by' => $lguAdmin->id, 'amount' => 25]);

        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());

        // Cannot mark as paid before it has been approved.
        $this->patchJson("/api/super-admin/lgu-withdrawals/{$withdrawal->id}/paid")->assertStatus(422);

        $this->patchJson("/api/super-admin/lgu-withdrawals/{$withdrawal->id}/approve")->assertOk();
        $paid = $this->patchJson("/api/super-admin/lgu-withdrawals/{$withdrawal->id}/paid");
        $paid->assertOk()->assertJsonPath('status', 'paid');
        $this->assertNotNull($paid->json('paid_at'));

        $this->assertDatabaseHas('notifications', ['user_id' => $lguAdmin->id, 'type' => 'lgu_withdrawal_paid']);

        Sanctum::actingAs($lguAdmin);
        $wallet = $this->getJson('/api/lgu/wallet')->assertOk();
        // No platform fee -- withdrawn amount equals exactly what was requested.
        $wallet->assertJsonPath('withdrawn_amount', 25)
            ->assertJsonPath('available_balance', 15);
    }

    /**
     * No email fires when the request is first submitted -- only once the
     * Super Admin acts on it. Approval and payout are distinct events with
     * distinct emails (LguWithdrawalApprovedMail vs LguWithdrawalReleasedMail),
     * so a recipient never confuses "we're processing this" with "the money
     * has moved."
     */
    public function test_lgu_withdrawal_emails_fire_on_approval_and_on_payout_but_never_on_submission(): void
    {
        Mail::fake();

        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $municipalityId = $lguAdmin->municipality_id;
        $seller = $this->makeSeller([], ['municipality_id' => $municipalityId]);
        $buyer = $this->makeBuyer();
        $order = $this->makeOrder($buyer, $this->makeListing($seller), ['status' => 'completed', 'total_amount' => 1000]);
        $payment = $this->makePayment($order, ['status' => 'released', 'amount' => 1000]);
        $this->makeSettlement($order, $payment); // LGU Share: 40.

        Sanctum::actingAs($lguAdmin);
        $created = $this->postJson('/api/lgu/withdrawals', [
            'method' => 'gcash', 'account_name' => 'Municipal Treasury', 'account_number' => '09171234567', 'amount' => 20,
        ])->assertCreated()->json();
        $withdrawal = LguWithdrawalRequest::findOrFail($created['id']);

        // Nothing sent yet -- submitting a request must never email anyone.
        Mail::assertNothingSent();

        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
        $this->patchJson("/api/super-admin/lgu-withdrawals/{$withdrawal->id}/approve")->assertOk();

        Mail::assertSent(LguWithdrawalApprovedMail::class, fn ($mail) => $mail->hasTo($lguAdmin->email) && $mail->withdrawal->is($withdrawal));
        Mail::assertNotSent(LguWithdrawalReleasedMail::class);

        $this->patchJson("/api/super-admin/lgu-withdrawals/{$withdrawal->id}/paid")->assertOk();

        Mail::assertSent(LguWithdrawalReleasedMail::class, fn ($mail) => $mail->hasTo($lguAdmin->email) && $mail->withdrawal->is($withdrawal));
        // Approval email must not fire a second time just because the withdrawal was later paid.
        Mail::assertSent(LguWithdrawalApprovedMail::class, 1);
    }

    public function test_lgu_withdrawal_approved_email_shows_correct_subject_and_does_not_claim_payment_was_made(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $withdrawal = $this->makeLguWithdrawal($lguAdmin->municipality_id, [
            'requested_by' => $lguAdmin->id,
            'amount' => 25,
            'status' => 'approved',
            'reviewed_at' => now(),
        ]);

        $mailable = new LguWithdrawalApprovedMail($withdrawal);

        $this->assertSame('Your LGU Revenue Withdrawal Request Has Been Approved', $mailable->envelope()->subject);

        $html = $mailable->render();
        $this->assertStringContainsString('25.00', $html);
        $this->assertStringContainsString('Awaiting Payout', $html);
        $this->assertStringContainsString('separate email once the payment has actually been made', $html);
        // Must not claim the funds have already moved -- that's the paid email's job.
        $this->assertStringNotContainsString('Withdrawal Has Been Successfully', $html);
    }

    public function test_lgu_withdrawal_released_email_shows_correct_subject_and_details(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $withdrawal = $this->makeLguWithdrawal($lguAdmin->municipality_id, [
            'requested_by' => $lguAdmin->id,
            'amount' => 25,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $mailable = new LguWithdrawalReleasedMail($withdrawal);

        $this->assertSame('Your LGU Revenue Withdrawal Has Been Processed', $mailable->envelope()->subject);

        $html = $mailable->render();
        $this->assertStringContainsString('25.00', $html);
        $this->assertStringContainsString('Paid', $html);
        // LGU withdrawals aren't charged a platform fee -- must never show one.
        $this->assertStringNotContainsString('Platform Payout Fee', $html);
    }

    public function test_super_admin_dashboard_exposes_pending_and_completed_withdrawal_counts_for_seller_and_lgu(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $seller = $this->makeSeller([], ['municipality_id' => $lguAdmin->municipality_id]);

        $this->makeWithdrawal($seller, ['amount' => 50, 'status' => 'pending']);
        $this->makeWithdrawal($seller, ['amount' => 60, 'status' => 'approved']);
        $this->makeWithdrawal($seller, ['amount' => 70, 'status' => 'paid', 'paid_at' => now()]);
        $this->makeLguWithdrawal($lguAdmin->municipality_id, ['amount' => 10, 'status' => 'pending']);
        $this->makeLguWithdrawal($lguAdmin->municipality_id, ['amount' => 15, 'status' => 'paid', 'paid_at' => now()]);
        $this->makeLguWithdrawal($lguAdmin->municipality_id, ['amount' => 20, 'status' => 'paid', 'paid_at' => now()]);

        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
        $dashboard = $this->getJson('/api/super-admin/dashboard')->assertOk();

        $dashboard->assertJsonPath('pending_seller_withdrawals', 2) // pending + approved
            ->assertJsonPath('completed_seller_withdrawals', 1)
            ->assertJsonPath('pending_lgu_withdrawals', 1)
            ->assertJsonPath('completed_lgu_withdrawals', 2);
    }

    public function test_lgu_wallet_is_scoped_to_its_own_municipality_and_never_leaks_other_data(): void
    {
        $cordova = Municipality::where('name', 'Cordova')->firstOrFail();
        $mandaue = Municipality::where('name', 'Mandaue')->firstOrFail();
        $cordovaAdmin = $this->makeLguAdmin(['municipality_id' => $cordova->id]);
        $mandaueAdmin = $this->makeLguAdmin(['municipality_id' => $mandaue->id]);

        $cordovaSeller = $this->makeSeller([], ['municipality_id' => $cordova->id]);
        $mandaueSeller = $this->makeSeller([], ['municipality_id' => $mandaue->id]);
        $buyer = $this->makeBuyer();

        $cordovaOrder = $this->makeOrder($buyer, $this->makeListing($cordovaSeller), ['status' => 'completed', 'total_amount' => 1000]);
        $cordovaPayment = $this->makePayment($cordovaOrder, ['status' => 'released', 'amount' => 1000]);
        $this->makeSettlement($cordovaOrder, $cordovaPayment); // Cordova LGU Share: 40.

        $mandaueOrder = $this->makeOrder($buyer, $this->makeListing($mandaueSeller), ['status' => 'completed', 'total_amount' => 5000]);
        $mandauePayment = $this->makePayment($mandaueOrder, ['status' => 'released', 'amount' => 5000]);
        $this->makeSettlement($mandaueOrder, $mandauePayment); // Mandaue LGU Share: 200. Gross: 5000. Seller Share: 4800.

        $this->makeLguWithdrawal($mandaue->id, ['requested_by' => $mandaueAdmin->id, 'amount' => 50]);

        Sanctum::actingAs($cordovaAdmin);
        $wallet = $this->getJson('/api/lgu/wallet')->assertOk();

        // Cordova's own figures only.
        $wallet->assertJsonPath('total_revenue', 40)->assertJsonPath('available_balance', 40);
        // Must never leak Mandaue's settlement (its gross amount, LGU Share,
        // or the seller's own share) or its withdrawal request.
        $body = json_encode($wallet->json());
        $this->assertStringNotContainsString('"total_revenue":200', $body);
        $this->assertStringNotContainsString('"gross_amount":"5000.00"', $body);
        $this->assertStringNotContainsString('"lgu_share":"200.00"', $body);
        $this->assertStringNotContainsString('"seller_share":"4800.00"', $body);
        $this->assertEmpty($wallet->json('withdrawal_requests'));
        $this->assertCount(1, $wallet->json('revenue_history'));

        // Cordova admin cannot act on Mandaue's withdrawal request.
        $mandaueWithdrawal = LguWithdrawalRequest::where('municipality_id', $mandaue->id)->firstOrFail();
        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
        $allLguWithdrawals = $this->getJson('/api/super-admin/lgu-withdrawals')->assertOk();
        $this->assertEquals($mandaue->id, $allLguWithdrawals->json('0.municipality_id'));
    }

    public function test_lgu_ai_assistant_answers_wallet_and_withdrawal_questions(): void
    {
        config(['services.gemini.api_key' => null]);

        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $municipalityId = $lguAdmin->municipality_id;
        $seller = $this->makeSeller([], ['municipality_id' => $municipalityId]);
        $buyer = $this->makeBuyer();
        $order = $this->makeOrder($buyer, $this->makeListing($seller), ['status' => 'completed', 'total_amount' => 1000]);
        $payment = $this->makePayment($order, ['status' => 'released', 'amount' => 1000]);
        $this->makeSettlement($order, $payment); // LGU Share: 40.
        $this->makeLguWithdrawal($municipalityId, ['requested_by' => $lguAdmin->id, 'amount' => 10, 'status' => 'paid', 'paid_at' => now()]);

        Sanctum::actingAs($lguAdmin);

        $availableResponse = $this->postJson('/api/ai-assistant/ask', ['question' => 'How much can I withdraw?']);
        $availableResponse->assertCreated();
        $this->assertStringContainsString('30', $availableResponse->json('response')); // 40 - 10 withdrawn.

        $withdrawnResponse = $this->postJson('/api/ai-assistant/ask', ['question' => 'How much have I already withdrawn?']);
        $withdrawnResponse->assertCreated();
        $this->assertStringContainsString('10', $withdrawnResponse->json('response'));

        $pendingResponse = $this->postJson('/api/ai-assistant/ask', ['question' => 'Do I have any pending withdrawals?']);
        $pendingResponse->assertCreated();
        $this->assertStringContainsString('0 pending', $pendingResponse->json('response'));
    }

    public function test_lgu_reports_include_wallet_stats_and_withdrawal_trends(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $municipalityId = $lguAdmin->municipality_id;
        $seller = $this->makeSeller([], ['municipality_id' => $municipalityId]);
        $buyer = $this->makeBuyer();
        $order = $this->makeOrder($buyer, $this->makeListing($seller), ['status' => 'completed', 'total_amount' => 1000]);
        $payment = $this->makePayment($order, ['status' => 'released', 'amount' => 1000]);
        $this->makeSettlement($order, $payment); // LGU Share: 40.
        $this->makeLguWithdrawal($municipalityId, ['requested_by' => $lguAdmin->id, 'amount' => 15, 'status' => 'paid', 'paid_at' => now()]);

        Sanctum::actingAs($lguAdmin);
        $reports = $this->getJson('/api/lgu/reports')->assertOk();

        $reports->assertJsonPath('revenue_cards.total_revenue', 40)
            ->assertJsonPath('revenue_cards.available_balance', 25)
            ->assertJsonPath('revenue_cards.total_withdrawn', 15)
            ->assertJsonStructure(['lgu_withdrawal_trends']);

        $trendTotal = collect($reports->json('lgu_withdrawal_trends'))->sum('amount');
        $this->assertEquals(15, $trendTotal);
    }

    /**
     * The existing Seller Wallet and Seller Withdrawal flow must be
     * completely unaffected by the LGU withdrawal system sharing its
     * architecture -- same endpoints, same response shape, same behavior.
     */
    public function test_existing_seller_wallet_and_withdrawal_flow_remains_unchanged(): void
    {
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller);
        $order = $this->makeOrder($buyer, $listing, ['status' => 'completed']);
        $payment = $this->makePayment($order, ['status' => 'released', 'amount' => 200]);
        $this->makeSettlement($order, $payment); // Seller Share: 96% of ₱200 = ₱192.

        Sanctum::actingAs($seller->user);
        $wallet = $this->getJson('/api/seller/wallet')->assertOk();
        $wallet->assertJsonPath('available_balance', 192)->assertJsonPath('total_earnings', 192);

        $response = $this->postJson('/api/seller/withdrawals', [
            'method' => 'gcash', 'account_name' => 'Test Seller', 'account_number' => '09171234567', 'amount' => 100,
        ]);
        // Seller withdrawals still carry the 6% platform payout fee, unlike LGU withdrawals.
        $response->assertCreated()->assertJsonPath('platform_fee', '6.00')->assertJsonPath('net_amount', 94);
    }

    // ------------------------------------------------------------------
    // Super Admin Global Account Moderation
    // ------------------------------------------------------------------

    public function test_super_admin_can_suspend_and_reinstate_a_buyer_with_reason_notes_email_and_audit_log(): void
    {
        Mail::fake();
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        $buyer = $this->makeBuyer();
        Sanctum::actingAs($superAdmin);

        $suspend = $this->patchJson("/api/super-admin/buyers/{$buyer->id}/suspend", [
            'reason' => 'Fraudulent Orders',
            'notes' => 'Repeated chargebacks reported by three sellers.',
        ]);
        $suspend->assertOk()->assertJsonPath('status', 'suspended');

        Mail::assertSent(AccountSuspendedMail::class, fn ($mail) => $mail->hasTo($buyer->email) && $mail->reason === 'Fraudulent Orders');

        $this->assertDatabaseHas('moderation_logs', [
            'user_id' => $buyer->id,
            'role' => 'buyer',
            'moderator_id' => $superAdmin->id,
            'action' => 'suspended',
            'reason' => 'Fraudulent Orders',
            'resulting_status' => 'suspended',
        ]);

        $reinstate = $this->patchJson("/api/super-admin/buyers/{$buyer->id}/reinstate", ['reason' => 'Appeal approved', 'notes' => 'Appeal accepted.']);
        $reinstate->assertOk()->assertJsonPath('status', 'active');

        Mail::assertSent(AccountReinstatedMail::class, fn ($mail) => $mail->hasTo($buyer->email) && $mail->reason === 'Appeal approved');
        $this->assertDatabaseHas('moderation_logs', [
            'user_id' => $buyer->id,
            'role' => 'buyer',
            'action' => 'reinstated',
            'resulting_status' => 'active',
        ]);
    }

    public function test_suspending_a_buyer_requires_a_valid_enumerated_reason(): void
    {
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        $buyer = $this->makeBuyer();
        Sanctum::actingAs($superAdmin);

        $this->patchJson("/api/super-admin/buyers/{$buyer->id}/suspend")->assertStatus(422);
        $this->patchJson("/api/super-admin/buyers/{$buyer->id}/suspend", ['reason' => 'Because I feel like it'])->assertStatus(422);
    }

    /**
     * Reinstating requires a reason on every role, the same accountability
     * expectation as suspending -- see App\Support\AccountModeration.
     */
    public function test_reinstating_any_role_requires_a_reason(): void
    {
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller();
        $lguAdmin = $this->makeLguAdmin();
        Sanctum::actingAs($superAdmin);

        $this->patchJson("/api/super-admin/buyers/{$buyer->id}/suspend", ['reason' => 'Spam'])->assertOk();
        $this->patchJson("/api/super-admin/sellers/{$seller->id}/suspend")->assertOk();
        $this->patchJson("/api/super-admin/lgu-admins/{$lguAdmin->id}/disable")->assertOk();

        $this->patchJson("/api/super-admin/buyers/{$buyer->id}/reinstate")->assertStatus(422);
        $this->patchJson("/api/super-admin/sellers/{$seller->id}/reinstate")->assertStatus(422);
        $this->patchJson("/api/super-admin/lgu-admins/{$lguAdmin->id}/enable")->assertStatus(422);
    }

    public function test_suspended_buyer_can_still_log_in_but_is_blocked_from_ordering_paying_messaging_and_reviewing(): void
    {
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        $buyer = $this->makeBuyer(['email' => 'suspended-buyer@example.test']);
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller);

        // A completed order that predates the suspension must remain visible and untouched.
        $completedOrder = $this->makeOrder($buyer, $listing, ['status' => 'completed']);

        Sanctum::actingAs($superAdmin);
        $this->patchJson("/api/super-admin/buyers/{$buyer->id}/suspend", ['reason' => 'Spam'])->assertOk();

        // Login still works -- only in-app actions are restricted.
        $this->postJson('/api/auth/login', ['email' => 'suspended-buyer@example.test', 'password' => 'password'])->assertOk();

        Sanctum::actingAs($buyer->fresh());

        // Existing completed order is unaffected and still visible.
        $orders = $this->getJson('/api/orders')->assertOk();
        $this->assertTrue(collect($orders->json())->contains('id', $completedOrder->id));

        // Cannot place a new order.
        $this->postJson('/api/orders', [
            'fingerling_listing_id' => $listing->id,
            'quantity' => 10,
        ])->assertStatus(403);

        // Cannot make a payment on an existing order.
        $payableOrder = $this->makeOrder($buyer, $listing, ['status' => 'placed']);
        $this->makePayment($payableOrder);
        $this->postJson("/api/orders/{$payableOrder->order_number}/payment-success")->assertStatus(403);

        // Cannot message a seller.
        $this->postJson('/api/messages', ['receiver_id' => $seller->user_id, 'body' => 'Hello'])->assertStatus(403);

        // Cannot leave a review, even on the pre-existing completed order.
        $this->postJson("/api/orders/{$completedOrder->id}/review", ['rating' => 5])->assertStatus(403);
    }

    public function test_super_admin_can_suspend_and_reinstate_any_seller_regardless_of_municipality(): void
    {
        Mail::fake();
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        $otherMunicipality = Municipality::where('id', '!=', $superAdmin->municipality_id)->firstOrFail();
        $seller = $this->makeSeller([], ['municipality_id' => $otherMunicipality->id]);
        Sanctum::actingAs($superAdmin);

        $suspend = $this->patchJson("/api/super-admin/sellers/{$seller->id}/suspend", ['reason' => 'Policy violation']);
        $suspend->assertOk()->assertJsonPath('status', 'suspended');
        Mail::assertSent(AccountSuspendedMail::class, fn ($mail) => $mail->hasTo($seller->user->email));

        $reinstate = $this->patchJson("/api/super-admin/sellers/{$seller->id}/reinstate", ['reason' => 'Issue resolved']);
        $reinstate->assertOk();
        $this->assertNotEquals('suspended', $reinstate->json('status'));
        Mail::assertSent(AccountReinstatedMail::class, fn ($mail) => $mail->hasTo($seller->user->email));
    }

    public function test_super_admin_can_suspend_and_reinstate_an_lgu_admin_with_audit_log_and_email(): void
    {
        Mail::fake();
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        $lguAdmin = $this->makeLguAdmin(['email' => 'target-lgu@example.test']);
        $lguAdmin->createToken('test');
        $this->assertCount(1, $lguAdmin->tokens);

        Sanctum::actingAs($superAdmin);
        $disable = $this->patchJson("/api/super-admin/lgu-admins/{$lguAdmin->id}/disable", [
            'reason' => 'Marketplace Policy Violation',
            'notes' => 'Approved a seller outside due process.',
        ]);
        $disable->assertOk()->assertJsonPath('status', 'disabled');

        $this->assertDatabaseHas('moderation_logs', [
            'user_id' => $lguAdmin->id,
            'role' => 'lgu_admin',
            'action' => 'suspended',
            'resulting_status' => 'disabled',
        ]);
        Mail::assertSent(AccountSuspendedMail::class, fn ($mail) => $mail->hasTo('target-lgu@example.test'));
        $this->assertCount(0, $lguAdmin->fresh()->tokens);

        $this->postJson('/api/auth/login', ['email' => 'target-lgu@example.test', 'password' => 'password'])->assertStatus(403);

        Sanctum::actingAs($superAdmin);
        $enable = $this->patchJson("/api/super-admin/lgu-admins/{$lguAdmin->id}/enable", ['reason' => 'Review completed']);
        $enable->assertOk()->assertJsonPath('status', 'active');
        $this->assertDatabaseHas('moderation_logs', [
            'user_id' => $lguAdmin->id,
            'role' => 'lgu_admin',
            'action' => 'reinstated',
            'resulting_status' => 'active',
        ]);
        Mail::assertSent(AccountReinstatedMail::class, fn ($mail) => $mail->hasTo('target-lgu@example.test'));

        $this->postJson('/api/auth/login', ['email' => 'target-lgu@example.test', 'password' => 'password'])->assertOk();
    }

    /**
     * Structural self-suspension guard: a Super Admin can never be the
     * target of disableLguAdmin (role mismatch, 404 before the explicit
     * self-id check even runs) and suspendBuyer/suspendSeller are similarly
     * scoped to their respective roles -- there is no endpoint through which
     * a Super Admin account can ever be suspended, by themselves or anyone.
     */
    public function test_super_admin_cannot_suspend_their_own_account(): void
    {
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $this->patchJson("/api/super-admin/lgu-admins/{$superAdmin->id}/disable")->assertStatus(404);
    }

    public function test_lgu_admin_cannot_suspend_a_seller_outside_their_municipality(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $otherMunicipality = Municipality::where('id', '!=', $lguAdmin->municipality_id)->firstOrFail();
        $seller = $this->makeSeller([], ['municipality_id' => $otherMunicipality->id]);
        Sanctum::actingAs($lguAdmin);

        $this->patchJson("/api/lgu/sellers/{$seller->id}/suspend")->assertStatus(403);
    }

    public function test_lgu_admin_cannot_access_super_admin_buyer_or_lgu_admin_moderation_endpoints(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $buyer = $this->makeBuyer();
        $otherLguAdmin = $this->makeLguAdmin();
        Sanctum::actingAs($lguAdmin);

        $this->patchJson("/api/super-admin/buyers/{$buyer->id}/suspend", ['reason' => 'Spam'])->assertStatus(403);
        $this->patchJson("/api/super-admin/lgu-admins/{$otherLguAdmin->id}/disable")->assertStatus(403);
    }

    public function test_moderation_log_endpoint_returns_full_audit_trail_filterable_by_role_and_action(): void
    {
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller();
        Sanctum::actingAs($superAdmin);

        $this->patchJson("/api/super-admin/buyers/{$buyer->id}/suspend", ['reason' => 'Harassment'])->assertOk();
        $this->patchJson("/api/super-admin/sellers/{$seller->id}/suspend")->assertOk();

        $all = $this->getJson('/api/super-admin/moderation-log')->assertOk();
        $this->assertCount(2, $all->json());

        $buyersOnly = $this->getJson('/api/super-admin/moderation-log?role=buyer')->assertOk();
        $this->assertCount(1, $buyersOnly->json());
        $this->assertEquals('buyer', $buyersOnly->json('0.role'));

        $suspendedOnly = $this->getJson('/api/super-admin/moderation-log?action=suspended')->assertOk();
        $this->assertCount(2, $suspendedOnly->json());
    }

    public function test_super_admin_dashboard_exposes_moderation_statistics(): void
    {
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        $buyer = $this->makeBuyer();
        Sanctum::actingAs($superAdmin);
        $this->patchJson("/api/super-admin/buyers/{$buyer->id}/suspend", ['reason' => 'Spam'])->assertOk();

        $dashboard = $this->getJson('/api/super-admin/dashboard')->assertOk();
        $dashboard->assertJsonStructure([
            'active_buyers', 'suspended_buyers', 'active_sellers', 'suspended_sellers',
            'active_lgu_admins', 'suspended_lgu_admins', 'recent_moderation_actions',
        ]);
        $this->assertGreaterThanOrEqual(1, $dashboard->json('suspended_buyers'));
    }

    public function test_super_admin_reports_expose_moderation_summary_filterable_by_role_and_status(): void
    {
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        $buyer = $this->makeBuyer();
        Sanctum::actingAs($superAdmin);
        $this->patchJson("/api/super-admin/buyers/{$buyer->id}/suspend", ['reason' => 'Spam'])->assertOk();

        $reports = $this->getJson('/api/super-admin/reports')->assertOk();
        $reports->assertJsonStructure(['moderation_summary', 'moderation_actions_over_time', 'moderation_log']);
        $this->assertGreaterThanOrEqual(1, $reports->json('moderation_summary.suspended_buyers'));

        $filtered = $this->getJson('/api/super-admin/reports?moderation_role=buyer&moderation_status=suspended')->assertOk();
        $this->assertCount(1, $filtered->json('moderation_log'));

        foreach (['daily', 'weekly', 'monthly', 'yearly'] as $period) {
            $this->getJson("/api/super-admin/reports?period={$period}")->assertOk()->assertJsonPath('period', $period);
        }
    }

    public function test_ai_assistant_answers_super_admin_moderation_questions(): void
    {
        config(['services.gemini.api_key' => null]);
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        $buyer = $this->makeBuyer(['name' => 'Flagged Buyer']);
        $mandaue = Municipality::where('name', 'Mandaue')->firstOrFail();
        $seller = $this->makeSeller(['name' => 'Flagged Seller'], ['hatchery_name' => 'Flagged Hatchery', 'municipality_id' => $mandaue->id]);

        Sanctum::actingAs($superAdmin);
        $this->patchJson("/api/super-admin/buyers/{$buyer->id}/suspend", ['reason' => 'Spam'])->assertOk();
        $this->patchJson("/api/super-admin/sellers/{$seller->id}/suspend")->assertOk();

        $howMany = $this->postJson('/api/ai-assistant/ask', ['question' => 'How many suspended users are there?']);
        $howMany->assertCreated();
        $this->assertStringContainsString('2', $howMany->json('response'));

        $showBuyers = $this->postJson('/api/ai-assistant/ask', ['question' => 'Show suspended buyers.']);
        $showBuyers->assertCreated();
        $this->assertStringContainsString('Flagged Buyer', $showBuyers->json('response'));

        $showSellers = $this->postJson('/api/ai-assistant/ask', ['question' => 'Show suspended sellers.']);
        $showSellers->assertCreated();
        $this->assertStringContainsString('Flagged Hatchery', $showSellers->json('response'));

        $mostSuspended = $this->postJson('/api/ai-assistant/ask', ['question' => 'Which municipality has the most suspended sellers?']);
        $mostSuspended->assertCreated();
        $this->assertStringContainsString('Mandaue', $mostSuspended->json('response'));

        $thisMonth = $this->postJson('/api/ai-assistant/ask', ['question' => 'Which accounts were suspended this month?']);
        $thisMonth->assertCreated();
        $this->assertStringContainsString('Flagged Buyer', $thisMonth->json('response'));
    }

    /**
     * Regression check: the pre-existing LGU-scoped seller suspension flow
     * (see test_lgu_admin_can_suspend_and_reinstate_a_seller_in_their_municipality
     * above) must behave identically now that it's implemented through the
     * shared App\Support\AccountModeration class, including sending the new
     * moderation emails and audit log it didn't previously have.
     */
    public function test_existing_lgu_seller_suspension_flow_still_works_and_now_also_logs_and_emails(): void
    {
        Mail::fake();
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $seller = $this->makeSeller(
            ['municipality_id' => $lguAdmin->municipality_id],
            ['municipality_id' => $lguAdmin->municipality_id]
        );
        Sanctum::actingAs($lguAdmin);

        $suspend = $this->patchJson("/api/lgu/sellers/{$seller->id}/suspend");
        $suspend->assertOk()->assertJsonPath('status', 'suspended');
        Mail::assertSent(AccountSuspendedMail::class);
        $this->assertDatabaseHas('moderation_logs', [
            'user_id' => $seller->user_id,
            'role' => 'seller',
            'moderator_id' => $lguAdmin->id,
            'action' => 'suspended',
        ]);

        $reinstate = $this->patchJson("/api/lgu/sellers/{$seller->id}/reinstate", ['reason' => 'Issue resolved']);
        $reinstate->assertOk();
        $this->assertNotEquals('suspended', $reinstate->json('status'));
        Mail::assertSent(AccountReinstatedMail::class);
    }

    // ------------------------------------------------------------------
    // Unified Order Tracking & Order Lookup
    // ------------------------------------------------------------------

    public function test_buyer_can_look_up_their_own_order_by_order_number_and_sees_a_timeline(): void
    {
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller);
        $order = $this->makeOrder($buyer, $listing, ['order_number' => 'FG-LOOKUP1', 'status' => 'confirmed']);
        $this->makePayment($order, ['status' => 'paid_held']);

        Sanctum::actingAs($buyer);
        $response = $this->getJson('/api/orders/FG-LOOKUP1');

        $response->assertOk()
            ->assertJsonPath('order_number', 'FG-LOOKUP1')
            ->assertJsonPath('order_status', 'confirmed')
            ->assertJsonMissingPath('revenue_distribution_preview');
        $this->assertTrue(collect($response->json('timeline.stages'))->firstWhere('key', 'seller_accepted')['reached']);
        $this->assertFalse(collect($response->json('timeline.stages'))->firstWhere('key', 'delivered')['reached']);
    }

    public function test_buyer_cannot_look_up_another_buyers_order(): void
    {
        $owner = $this->makeBuyer();
        $intruder = $this->makeBuyer();
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller);
        $order = $this->makeOrder($owner, $listing, ['order_number' => 'FG-PRIVATE1']);

        Sanctum::actingAs($intruder);
        $this->getJson('/api/orders/FG-PRIVATE1')->assertForbidden();
    }

    public function test_seller_can_look_up_an_order_against_their_own_listing_but_not_another_sellers(): void
    {
        $buyer = $this->makeBuyer();
        $ownSeller = $this->makeSeller();
        $otherSeller = $this->makeSeller();
        $ownListing = $this->makeListing($ownSeller);
        $order = $this->makeOrder($buyer, $ownListing, ['order_number' => 'FG-SELLER01']);

        Sanctum::actingAs($ownSeller->user);
        $this->getJson('/api/orders/FG-SELLER01')->assertOk()->assertJsonPath('seller.id', $ownSeller->id);

        Sanctum::actingAs($otherSeller->user);
        $this->getJson('/api/orders/FG-SELLER01')->assertForbidden();
    }

    public function test_seller_can_set_notes_on_their_own_order(): void
    {
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller);
        $order = $this->makeOrder($buyer, $listing, ['order_number' => 'FG-NOTES001']);

        Sanctum::actingAs($seller->user);
        $response = $this->patchJson('/api/orders/FG-NOTES001/notes', ['seller_notes' => 'Buyer requested morning pickup.']);

        $response->assertOk()->assertJsonPath('seller_notes', 'Buyer requested morning pickup.');
    }

    public function test_lgu_admin_can_view_full_transaction_detail_only_within_their_municipality(): void
    {
        $lguAdmin = $this->makeLguAdmin();
        $otherLgu = $this->makeLguAdmin(['municipality_id' => Municipality::where('id', '!=', $lguAdmin->municipality_id)->first()->id]);
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller([], ['municipality_id' => $lguAdmin->municipality_id]);
        $listing = $this->makeListing($seller);
        $order = $this->makeOrder($buyer, $listing, ['order_number' => 'FG-LGUVIEW1', 'status' => 'completed']);
        $this->makePayment($order, ['status' => 'paid_held']);

        Sanctum::actingAs($lguAdmin);
        $this->getJson('/api/lgu/orders/FG-LGUVIEW1')
            ->assertOk()
            ->assertJsonPath('revenue_distribution_preview.source', 'preview')
            ->assertJsonPath('lgu_verification.status', 'pending');

        Sanctum::actingAs($otherLgu);
        $this->getJson('/api/lgu/orders/FG-LGUVIEW1')->assertForbidden();
    }

    public function test_lgu_admin_can_hold_earnings_for_investigation_and_it_blocks_approval(): void
    {
        $lguAdmin = $this->makeLguAdmin();
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller([], ['municipality_id' => $lguAdmin->municipality_id]);
        $listing = $this->makeListing($seller);
        $order = $this->makeOrder($buyer, $listing, ['order_number' => 'FG-HOLD0001', 'status' => 'completed']);
        $payment = $this->makePayment($order, ['status' => 'paid_held']);

        Sanctum::actingAs($lguAdmin);
        $this->patchJson("/api/lgu/payments/{$payment->id}/hold", ['reason' => 'Suspicious quantity mismatch'])
            ->assertOk()
            ->assertJsonPath('lgu_verification.status', 'on_hold');

        $this->patchJson("/api/lgu/payments/{$payment->id}/approve")->assertStatus(422);
        $this->assertDatabaseMissing('settlements', ['order_id' => $order->id]);

        $this->patchJson("/api/lgu/payments/{$payment->id}/clear-hold")->assertOk()->assertJsonPath('lgu_verification.status', 'pending');
        $this->patchJson("/api/lgu/payments/{$payment->id}/approve")->assertOk();
        $this->assertDatabaseHas('settlements', ['order_id' => $order->id]);
    }

    public function test_lgu_admin_can_reject_earnings_which_removes_it_from_the_pending_queue(): void
    {
        $lguAdmin = $this->makeLguAdmin();
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller([], ['municipality_id' => $lguAdmin->municipality_id]);
        $listing = $this->makeListing($seller);
        $order = $this->makeOrder($buyer, $listing, ['order_number' => 'FG-REJECT01', 'status' => 'completed']);
        $payment = $this->makePayment($order, ['status' => 'paid_held']);

        Sanctum::actingAs($lguAdmin);
        $this->assertCount(1, $this->getJson('/api/lgu/earnings')->json());

        $this->patchJson("/api/lgu/payments/{$payment->id}/reject", ['reason' => 'Buyer disputed delivery'])
            ->assertOk()
            ->assertJsonPath('lgu_verification.status', 'rejected');

        $this->assertCount(0, $this->getJson('/api/lgu/earnings')->json());
        $this->patchJson("/api/lgu/payments/{$payment->id}/approve")->assertStatus(422);
        $this->assertDatabaseMissing('settlements', ['order_id' => $order->id]);
    }

    public function test_super_admin_can_globally_look_up_any_order_including_payout_status(): void
    {
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller);
        $order = $this->makeOrder($buyer, $listing, ['order_number' => 'FG-GLOBAL01']);

        Sanctum::actingAs($superAdmin);
        $this->getJson('/api/super-admin/orders/FG-GLOBAL01')
            ->assertOk()
            ->assertJsonPath('order_number', 'FG-GLOBAL01')
            ->assertJsonPath('seller_payout_status', 'awaiting_settlement');
    }

    public function test_ai_assistant_answers_an_order_number_question_scoped_to_the_caller(): void
    {
        // Deterministic fallback path (see test_ai_assistant_falls_back_to_data_driven_answer_when_gemini_is_unavailable
        // above for the same pattern) -- without this, a real GEMINI_API_KEY
        // in .env makes this call live Gemini and assert against its
        // non-deterministic phrasing of the grounded context.
        config(['services.gemini.api_key' => null]);

        $buyer = $this->makeBuyer();
        $intruder = $this->makeBuyer();
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller, ['species' => 'Tilapia']);
        $order = $this->makeOrder($buyer, $listing, ['order_number' => 'FG-AIQUERY1', 'status' => 'confirmed']);
        $this->makePayment($order, ['status' => 'paid_held']);

        Sanctum::actingAs($buyer);
        $ownAnswer = $this->postJson('/api/ai-assistant/ask', ['question' => 'What is the status of order FG-AIQUERY1?']);
        $ownAnswer->assertCreated();
        $this->assertStringContainsString('FG-AIQUERY1', $ownAnswer->json('response'));
        $this->assertStringContainsString('Tilapia', $ownAnswer->json('response'));

        Sanctum::actingAs($intruder);
        $deniedAnswer = $this->postJson('/api/ai-assistant/ask', ['question' => 'What is the status of order FG-AIQUERY1?']);
        $deniedAnswer->assertCreated();
        $this->assertStringNotContainsString('Tilapia', $deniedAnswer->json('response'));
    }

    // ------------------------------------------------------------------
    // Global Activity Log / Audit Trail
    // ------------------------------------------------------------------

    public function test_activity_log_records_listing_approval_and_is_scoped_to_lgu_municipality(): void
    {
        $lguAdmin = $this->makeLguAdmin();
        $otherLgu = $this->makeLguAdmin(['municipality_id' => Municipality::where('id', '!=', $lguAdmin->municipality_id)->first()->id]);
        $seller = $this->makeSeller([], ['municipality_id' => $lguAdmin->municipality_id, 'hatchery_name' => 'Log Test Hatchery']);
        $listing = $this->makeListing($seller, ['approval_status' => 'pending']);

        Sanctum::actingAs($lguAdmin);
        $this->patchJson("/api/lgu/listings/{$listing->id}/approve")->assertOk();

        $ownLog = $this->getJson('/api/lgu/activity-log')->assertOk();
        $entry = collect($ownLog->json('data'))->firstWhere('action', 'listing_approved');
        $this->assertNotNull($entry);
        $this->assertStringContainsString('Log Test Hatchery', $entry['description']);

        Sanctum::actingAs($otherLgu);
        $otherLog = $this->getJson('/api/lgu/activity-log')->assertOk();
        $this->assertNull(collect($otherLog->json('data'))->firstWhere('action', 'listing_approved'));
    }

    public function test_activity_log_reads_moderation_and_settlement_events_without_duplicating_them(): void
    {
        $lguAdmin = $this->makeLguAdmin();
        $sellerProfile = $this->makeSeller([], ['municipality_id' => $lguAdmin->municipality_id]);
        $buyer = $this->makeBuyer();
        $listing = $this->makeListing($sellerProfile);
        $order = $this->makeOrder($buyer, $listing, ['order_number' => 'FG-ACTLOG1', 'status' => 'completed']);
        $payment = $this->makePayment($order, ['status' => 'paid_held']);

        Sanctum::actingAs($lguAdmin);
        $this->patchJson("/api/lgu/sellers/{$sellerProfile->id}/suspend")->assertOk();
        $this->patchJson("/api/lgu/payments/{$payment->id}/approve")->assertOk();

        $log = $this->getJson('/api/lgu/activity-log')->assertOk()->json('data');

        $suspendEntry = collect($log)->firstWhere('action', 'seller_suspended');
        $this->assertNotNull($suspendEntry, 'Expected a seller_suspended entry sourced from moderation_logs.');

        $earningsEntry = collect($log)->firstWhere('action', 'seller_earnings_approved');
        $this->assertNotNull($earningsEntry, 'Expected a seller_earnings_approved entry sourced from settlements.');
        $this->assertSame('FG-ACTLOG1', $earningsEntry['reference_number']);
    }

    public function test_activity_log_category_filter_returns_every_action_in_that_category(): void
    {
        $lguAdmin = $this->makeLguAdmin();
        $sellerProfile = $this->makeSeller([], ['municipality_id' => $lguAdmin->municipality_id]);
        $buyer = $this->makeBuyer();
        $listing = $this->makeListing($sellerProfile);
        $order = $this->makeOrder($buyer, $listing, ['order_number' => 'FG-CATFLT1', 'status' => 'completed']);
        $payment = $this->makePayment($order, ['status' => 'paid_held']);

        Sanctum::actingAs($lguAdmin);
        $this->patchJson("/api/lgu/listings/{$listing->id}/reject", ['reason' => 'Needs more photos'])->assertOk();
        $this->patchJson("/api/lgu/sellers/{$sellerProfile->id}/suspend")->assertOk();
        $this->patchJson("/api/lgu/payments/{$payment->id}/approve")->assertOk();

        $paymentsOnly = $this->getJson('/api/lgu/activity-log?category=payments')->assertOk()->json('data');
        $this->assertNotEmpty($paymentsOnly);
        $this->assertTrue(collect($paymentsOnly)->every(fn ($e) => in_array($e['action'], ['seller_earnings_approved', 'seller_payout_requested', 'seller_payout_approved', 'seller_payout_completed', 'lgu_payout_requested', 'lgu_payout_approved', 'lgu_payout_completed'], true)));
        $this->assertNull(collect($paymentsOnly)->firstWhere('action', 'listing_rejected'));
        $this->assertNull(collect($paymentsOnly)->firstWhere('action', 'seller_suspended'));

        $listingsOnly = $this->getJson('/api/lgu/activity-log?category=listings_sellers')->assertOk()->json('data');
        $this->assertNotNull(collect($listingsOnly)->firstWhere('action', 'listing_rejected'));
        $this->assertNull(collect($listingsOnly)->firstWhere('action', 'seller_earnings_approved'));
    }

    public function test_activity_log_search_matches_the_actions_own_words_not_just_names_and_descriptions(): void
    {
        $lguAdmin = $this->makeLguAdmin();
        $sellerProfile = $this->makeSeller([], ['municipality_id' => $lguAdmin->municipality_id]);
        $buyer = $this->makeBuyer();
        $listing = $this->makeListing($sellerProfile);
        $order = $this->makeOrder($buyer, $listing, ['order_number' => 'FG-SEARCH1', 'status' => 'completed']);
        $payment = $this->makePayment($order, ['status' => 'paid_held']);

        Sanctum::actingAs($lguAdmin);
        $this->patchJson("/api/lgu/payments/{$payment->id}/approve")->assertOk();

        $results = $this->getJson('/api/lgu/activity-log?search=earnings')->assertOk()->json('data');
        $this->assertNotNull(collect($results)->firstWhere('action', 'seller_earnings_approved'));
    }

    public function test_activity_log_categories_endpoint_lists_every_category(): void
    {
        $lguAdmin = $this->makeLguAdmin();
        Sanctum::actingAs($lguAdmin);

        $categories = $this->getJson('/api/lgu/activity-log/categories')->assertOk()->json();
        $this->assertSame(['accounts', 'listings_sellers', 'moderation', 'payments', 'reviews', 'reports', 'support'], collect($categories)->pluck('value')->all());
    }

    public function test_user_registration_is_logged_via_observer_and_lgu_admin_creation_is_not_double_logged(): void
    {
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();

        $this->postJson('/api/auth/register', [
            'name' => 'Activity Log Buyer',
            'email' => 'activity-log-buyer@fishmarket.test',
            'password' => 'Password123!',
            'role' => 'buyer',
        ])->assertCreated();

        Sanctum::actingAs($superAdmin);
        $created = $this->postJson('/api/super-admin/lgu-admins', [
            'name' => 'New LGU Admin',
            'email' => 'new-lgu-admin@fishmarket.test',
            'password' => 'Password123!',
            'municipality_id' => Municipality::firstOrFail()->id,
        ])->assertCreated();

        $log = $this->getJson('/api/super-admin/activity-log')->assertOk()->json('data');

        $this->assertNotNull(collect($log)->first(fn ($e) => $e['action'] === 'user_registered' && $e['target_user'] === 'Activity Log Buyer'));

        $lguAdminEntries = collect($log)->where('target_user_id', $created->json('id'));
        $this->assertCount(1, $lguAdminEntries, 'LGU Admin creation should log exactly once (lgu_admin_created), not also user_registered.');
        $this->assertSame('lgu_admin_created', $lguAdminEntries->first()['action']);
    }

    public function test_super_admin_can_create_municipality_and_it_is_logged(): void
    {
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();

        Sanctum::actingAs($superAdmin);
        $response = $this->postJson('/api/super-admin/municipalities', ['name' => 'Brand New Town', 'province' => 'Cebu']);
        $response->assertCreated();
        $this->assertDatabaseHas('municipalities', ['name' => 'Brand New Town']);

        $log = $this->getJson('/api/super-admin/activity-log')->assertOk()->json('data');
        $this->assertNotNull(collect($log)->firstWhere('action', 'municipality_created'));
    }

    // ------------------------------------------------------------------
    // Announcement System
    // ------------------------------------------------------------------

    public function test_super_admin_can_create_announcement_which_immediately_notifies_every_role(): void
    {
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller();
        $lguAdmin = $this->makeLguAdmin();

        Sanctum::actingAs($superAdmin);
        $response = $this->postJson('/api/super-admin/announcements', [
            'title' => 'Scheduled Maintenance',
            'body' => 'The marketplace will be briefly unavailable tonight.',
            'category' => 'maintenance',
        ]);
        $response->assertCreated();
        $announcementId = $response->json('id');

        foreach ([$buyer, $seller->user, $lguAdmin] as $user) {
            $this->assertDatabaseHas('notifications', [
                'user_id' => $user->id,
                'type' => "announcement:{$announcementId}",
            ]);
        }
    }

    public function test_future_dated_announcement_does_not_notify_until_the_scheduled_command_runs(): void
    {
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        $buyer = $this->makeBuyer();

        Sanctum::actingAs($superAdmin);
        $response = $this->postJson('/api/super-admin/announcements', [
            'title' => 'Holiday Notice',
            'body' => 'We will be closed for the holiday.',
            'category' => 'holiday',
            'starts_at' => now()->addDays(3)->toDateTimeString(),
        ]);
        $response->assertCreated();
        $announcementId = $response->json('id');

        $this->assertDatabaseMissing('notifications', ['type' => "announcement:{$announcementId}"]);

        $this->travelTo(now()->addDays(4));
        $this->artisan('announcements:publish');

        $this->assertDatabaseHas('notifications', ['user_id' => $buyer->id, 'type' => "announcement:{$announcementId}"]);
        $this->travelBack();
    }

    public function test_active_announcements_endpoint_only_returns_announcements_within_their_display_window(): void
    {
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $active = $this->postJson('/api/super-admin/announcements', ['title' => 'Active One', 'body' => 'Currently visible.'])->json();
        // Past dates can no longer be entered through the API, so an announcement
        // that has already run its course is created directly.
        \App\Models\Announcement::create([
            'title' => 'Expired One',
            'body' => 'No longer visible.',
            'created_by' => $superAdmin->id,
            'starts_at' => now()->subDays(10),
            'expires_at' => now()->subDay(),
        ]);
        $this->postJson('/api/super-admin/announcements', [
            'title' => 'Future One',
            'body' => 'Not visible yet.',
            'starts_at' => now()->addDays(5)->toDateTimeString(),
        ])->assertCreated();

        $buyer = $this->makeBuyer();
        Sanctum::actingAs($buyer);
        $visible = $this->getJson('/api/announcements/active')->assertOk()->json();

        $titles = collect($visible)->pluck('title');
        $this->assertContains('Active One', $titles);
        $this->assertNotContains('Expired One', $titles);
        $this->assertNotContains('Future One', $titles);
        $this->assertSame($active['id'], collect($visible)->firstWhere('title', 'Active One')['id']);
    }

    public function test_guests_can_see_active_announcements_without_internal_fields(): void
    {
        \App\Models\Announcement::create([
            'title' => 'Scheduled Maintenance',
            'body' => 'The marketplace will be down briefly tonight.',
            'category' => 'maintenance',
            'created_by' => User::where('role', 'super_admin')->firstOrFail()->id,
        ]);

        // No Sanctum::actingAs -- this request is an anonymous guest.
        $response = $this->getJson('/api/announcements/active')->assertOk();

        $response->assertJsonPath('0.title', 'Scheduled Maintenance')
            ->assertJsonPath('0.category', 'maintenance');
        $this->assertArrayHasKey('updated_at', $response->json('0'));
        $this->assertArrayNotHasKey('created_by', $response->json('0'));
        $this->assertArrayNotHasKey('notified_at', $response->json('0'));
    }

    public function test_announcement_dates_cannot_be_in_the_past_or_end_before_they_start(): void
    {
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        $buyer = $this->makeBuyer();
        Sanctum::actingAs($superAdmin);

        // The reported case: starts today, "ends" two months earlier.
        $this->postJson('/api/super-admin/announcements', [
            'title' => 'Backwards',
            'body' => 'Should be refused.',
            'starts_at' => now()->toIso8601String(),
            'expires_at' => now()->subMonths(2)->toIso8601String(),
        ])->assertStatus(422)->assertJsonValidationErrors('expires_at');

        $this->postJson('/api/super-admin/announcements', [
            'title' => 'Past start',
            'body' => 'Should be refused.',
            'starts_at' => now()->subDay()->toIso8601String(),
        ])->assertStatus(422)->assertJsonValidationErrors('starts_at');

        $this->postJson('/api/super-admin/announcements', [
            'title' => 'Ends before it starts',
            'body' => 'Should be refused.',
            'starts_at' => now()->addDays(5)->toIso8601String(),
            'expires_at' => now()->addDays(2)->toIso8601String(),
        ])->assertStatus(422)->assertJsonValidationErrors('expires_at');

        $this->postJson('/api/super-admin/announcements', [
            'title' => 'Too far ahead',
            'body' => 'Should be refused.',
            'expires_at' => '2101-06-01T08:00:00+00:00',
        ])->assertStatus(422)->assertJsonValidationErrors('expires_at');

        $this->assertDatabaseCount('announcements', 0);
        $this->assertDatabaseMissing('notifications', ['user_id' => $buyer->id]);

        // A valid window sent with a UTC offset is stored as the same moment.
        $starts = now()->addDay()->startOfMinute();
        $response = $this->postJson('/api/super-admin/announcements', [
            'title' => 'Valid window',
            'body' => 'Accepted.',
            'starts_at' => $starts->copy()->setTimezone('Asia/Manila')->toIso8601String(),
            'expires_at' => now()->addDays(3)->toIso8601String(),
        ])->assertCreated();
        $this->assertTrue(\App\Models\Announcement::findOrFail($response->json('id'))->starts_at->equalTo($starts));
    }

    public function test_an_already_expired_announcement_can_still_be_edited_without_changing_its_dates(): void
    {
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        $announcement = \App\Models\Announcement::create([
            'title' => 'Old notice',
            'body' => 'Ran last month.',
            'created_by' => $superAdmin->id,
            'starts_at' => now()->subMonth()->startOfMinute(),
            'expires_at' => now()->subWeek()->startOfMinute(),
        ]);
        Sanctum::actingAs($superAdmin);

        // The edit form sends the unchanged dates back along with the new title.
        $this->patchJson("/api/super-admin/announcements/{$announcement->id}", [
            'title' => 'Old notice (typo fixed)',
            'starts_at' => $announcement->starts_at->toIso8601String(),
            'expires_at' => $announcement->expires_at->toIso8601String(),
        ])->assertOk()->assertJsonPath('title', 'Old notice (typo fixed)');

        // Moving the end date to another past date is still refused.
        $this->patchJson("/api/super-admin/announcements/{$announcement->id}", [
            'expires_at' => now()->subDay()->toIso8601String(),
        ])->assertStatus(422)->assertJsonValidationErrors('expires_at');
    }

    public function test_super_admin_can_update_and_delete_an_announcement(): void
    {
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $announcement = $this->postJson('/api/super-admin/announcements', ['title' => 'Original', 'body' => 'Original body.'])->json();

        $this->patchJson("/api/super-admin/announcements/{$announcement['id']}", ['title' => 'Updated Title'])
            ->assertOk()->assertJsonPath('title', 'Updated Title');

        $this->deleteJson("/api/super-admin/announcements/{$announcement['id']}")->assertOk();
        $this->assertDatabaseMissing('announcements', ['id' => $announcement['id']]);
    }

    // ------------------------------------------------------------------
    // Export Reports
    // ------------------------------------------------------------------

    public function test_lgu_can_export_sales_report_as_pdf_and_excel(): void
    {
        $lguAdmin = $this->makeLguAdmin();
        $seller = $this->makeSeller([], ['municipality_id' => $lguAdmin->municipality_id]);
        $buyer = $this->makeBuyer();
        $listing = $this->makeListing($seller);
        $this->makeOrder($buyer, $listing, ['order_number' => 'FG-EXPORT1']);

        Sanctum::actingAs($lguAdmin);

        $pdf = $this->getJson('/api/lgu/reports/export?type=sales&format=pdf');
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));

        $xlsx = $this->getJson('/api/lgu/reports/export?type=sales&format=xlsx');
        $xlsx->assertOk();
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $xlsx->headers->get('Content-Type')
        );
    }

    public function test_lgu_export_rejects_an_unknown_report_type(): void
    {
        $lguAdmin = $this->makeLguAdmin();
        Sanctum::actingAs($lguAdmin);

        $this->getJson('/api/lgu/reports/export?type=not-a-real-type&format=pdf')->assertStatus(422);
    }

    public function test_super_admin_can_export_orders_report_as_pdf_and_excel(): void
    {
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        $listing = $this->makeListing($seller);
        $this->makeOrder($buyer, $listing, ['order_number' => 'FG-SEXPORT1']);

        Sanctum::actingAs($superAdmin);

        $this->getJson('/api/super-admin/reports/export?type=orders&format=pdf')->assertOk();
        $this->getJson('/api/super-admin/reports/export?type=payouts&format=xlsx')->assertOk();
    }

    public function test_lgu_admin_cannot_access_super_admin_export_endpoint(): void
    {
        $lguAdmin = $this->makeLguAdmin();
        Sanctum::actingAs($lguAdmin);

        $this->getJson('/api/super-admin/reports/export?type=orders&format=pdf')->assertForbidden();
    }

    public function test_seller_can_create_edit_and_delete_a_post_with_media(): void
    {
        Storage::fake('public');
        $seller = $this->makeSeller();
        Sanctum::actingAs($seller->user);

        // Create -- text + a photo and a video, in one request.
        $created = $this->postJson('/api/seller/posts', [
            'body' => 'Fresh bangus harvest this morning!',
            'media' => [
                UploadedFile::fake()->image('harvest.jpg')->size(500),
                UploadedFile::fake()->create('feeding.mp4', 2048, 'video/mp4'),
            ],
        ])->assertCreated()->json();

        $this->assertDatabaseHas('seller_posts', ['seller_profile_id' => $seller->id, 'body' => 'Fresh bangus harvest this morning!']);
        $this->assertCount(2, $created['media']);
        $postId = $created['id'];
        foreach ($created['media'] as $media) {
            Storage::disk('public')->assertExists(str_replace('/storage/', '', parse_url($media['url'], PHP_URL_PATH)));
        }

        // Edit -- change the text.
        $this->patchJson("/api/seller/posts/{$postId}", ['body' => 'Harvest sold out, thank you!'])->assertOk();
        $this->assertDatabaseHas('seller_posts', ['id' => $postId, 'body' => 'Harvest sold out, thank you!']);

        // Delete -- post and its media rows go, and the files are removed.
        $mediaPaths = collect($created['media'])->map(fn ($m) => str_replace('/storage/', '', parse_url($m['url'], PHP_URL_PATH)));
        $this->deleteJson("/api/seller/posts/{$postId}")->assertOk();
        $this->assertDatabaseMissing('seller_posts', ['id' => $postId]);
        $this->assertDatabaseCount('seller_post_media', 0);
        foreach ($mediaPaths as $path) {
            Storage::disk('public')->assertMissing($path);
        }
    }

    public function test_a_post_requires_text_or_media(): void
    {
        $seller = $this->makeSeller();
        Sanctum::actingAs($seller->user);

        $this->postJson('/api/seller/posts', [])->assertStatus(422);
    }

    public function test_seller_posts_are_visible_to_every_role_on_the_public_profile(): void
    {
        $seller = $this->makeSeller();
        Sanctum::actingAs($seller->user);
        $this->postJson('/api/seller/posts', ['body' => 'Newly stocked tilapia fingerlings available.'])->assertCreated();

        $assertSeesPost = function () use ($seller) {
            $response = $this->getJson("/api/sellers/{$seller->id}")->assertOk()->json();
            $this->assertCount(1, $response['posts']);
            $this->assertSame('Newly stocked tilapia fingerlings available.', $response['posts'][0]['body']);
        };

        // Buyer, another seller, LGU admin, super admin, and even an
        // unauthenticated visitor all see the same feed.
        Sanctum::actingAs($this->makeBuyer());
        $assertSeesPost();

        Sanctum::actingAs($this->makeSeller()->user);
        $assertSeesPost();

        Sanctum::actingAs($this->makeLguAdmin());
        $assertSeesPost();

        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
        $assertSeesPost();
    }

    public function test_a_seller_cannot_edit_or_delete_another_sellers_post(): void
    {
        $owner = $this->makeSeller();
        Sanctum::actingAs($owner->user);
        $postId = $this->postJson('/api/seller/posts', ['body' => 'My farm update.'])->assertCreated()->json('id');

        // A different seller may VIEW it (public profile) but never mutate it.
        $intruder = $this->makeSeller();
        Sanctum::actingAs($intruder->user);

        $this->patchJson("/api/seller/posts/{$postId}", ['body' => 'Hijacked.'])->assertForbidden();
        $this->deleteJson("/api/seller/posts/{$postId}")->assertForbidden();
        $this->assertDatabaseHas('seller_posts', ['id' => $postId, 'body' => 'My farm update.']);
    }

    public function test_listing_media_stays_on_listings_and_is_not_exposed_as_seller_posts(): void
    {
        Storage::fake('public');
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller);
        Sanctum::actingAs($seller->user);

        // Upload media to the LISTING (existing flow) -- it must not leak into
        // the seller's posts feed, which stays empty.
        $this->postJson("/api/listings/{$listing->id}/media", [
            'photos' => [UploadedFile::fake()->image('care.jpg')->size(500)],
        ])->assertOk();

        $profile = $this->getJson("/api/sellers/{$seller->id}")->assertOk()->json();
        $this->assertSame([], $profile['posts']);
        $this->assertCount(1, $profile['listings'][0]['media']);
        $this->assertDatabaseCount('seller_post_media', 0);
    }

    public function test_any_role_can_like_and_unlike_a_seller_post(): void
    {
        $seller = $this->makeSeller();
        Sanctum::actingAs($seller->user);
        $postId = $this->postJson('/api/seller/posts', ['body' => 'Harvest day!'])->assertCreated()->json('id');

        // A buyer likes it.
        Sanctum::actingAs($this->makeBuyer());
        $liked = $this->postJson("/api/seller-posts/{$postId}/like")->assertOk()->json();
        $this->assertTrue($liked['liked_by_me']);
        $this->assertSame(1, $liked['likes_count']);

        // Liking again toggles it off -- one like per user per post.
        $unliked = $this->postJson("/api/seller-posts/{$postId}/like")->assertOk()->json();
        $this->assertFalse($unliked['liked_by_me']);
        $this->assertSame(0, $unliked['likes_count']);
        $this->assertDatabaseCount('seller_post_likes', 0);
    }

    public function test_like_count_and_state_appear_on_the_public_profile_per_viewer(): void
    {
        $seller = $this->makeSeller();
        Sanctum::actingAs($seller->user);
        $postId = $this->postJson('/api/seller/posts', ['body' => 'Restocked tilapia.'])->assertCreated()->json('id');

        Sanctum::actingAs($this->makeBuyer());
        $this->postJson("/api/seller-posts/{$postId}/like")->assertOk();

        // The liker sees liked_by_me = true.
        $post = $this->getJson("/api/sellers/{$seller->id}")->assertOk()->json('posts.0');
        $this->assertSame(1, $post['likes_count']);
        $this->assertTrue($post['liked_by_me']);

        // A different viewer sees the same count but liked_by_me = false.
        Sanctum::actingAs($this->makeLguAdmin());
        $post = $this->getJson("/api/sellers/{$seller->id}")->assertOk()->json('posts.0');
        $this->assertSame(1, $post['likes_count']);
        $this->assertFalse($post['liked_by_me']);
    }

    public function test_every_role_can_comment_and_comments_expose_only_safe_author_fields(): void
    {
        $seller = $this->makeSeller();
        Sanctum::actingAs($seller->user);
        $postId = $this->postJson('/api/seller/posts', ['body' => 'New pond ready.'])->assertCreated()->json('id');

        Sanctum::actingAs($this->makeBuyer(['name' => 'Commenting Buyer']));
        $this->postJson("/api/seller-posts/{$postId}/comments", ['body' => 'Great news!'])->assertCreated();

        $comment = $this->getJson("/api/sellers/{$seller->id}")->assertOk()->json('posts.0.comments.0');
        $this->assertSame('Great news!', $comment['body']);
        $this->assertSame('Commenting Buyer', $comment['user']['name']);
        // Public payload must never leak private contact fields.
        $this->assertArrayNotHasKey('email', $comment['user']);
        $this->assertArrayNotHasKey('phone', $comment['user']);
    }

    public function test_a_comment_can_be_deleted_only_by_its_author_or_the_super_admin(): void
    {
        $seller = $this->makeSeller();
        Sanctum::actingAs($seller->user);
        $postId = $this->postJson('/api/seller/posts', ['body' => 'Feeding time video.'])->assertCreated()->json('id');

        // A buyer leaves a comment.
        $buyer = $this->makeBuyer();
        Sanctum::actingAs($buyer);
        $commentId = $this->postJson("/api/seller-posts/{$postId}/comments", ['body' => 'Nice!'])->assertCreated()->json('id');

        // A stranger cannot delete it.
        Sanctum::actingAs($this->makeBuyer());
        $this->deleteJson("/api/seller-posts/comments/{$commentId}")->assertForbidden();

        // The post-owning seller CANNOT delete another user's comment on their
        // own feed -- moderation of others' comments is Super-Admin-only.
        Sanctum::actingAs($seller->user);
        $this->deleteJson("/api/seller-posts/comments/{$commentId}")->assertForbidden();
        $this->assertDatabaseCount('seller_post_comments', 1);

        // The author can delete their own comment.
        Sanctum::actingAs($buyer);
        $this->deleteJson("/api/seller-posts/comments/{$commentId}")->assertOk();
        $this->assertDatabaseCount('seller_post_comments', 0);
    }

    public function test_lgu_admin_can_upload_and_remove_their_profile_picture(): void
    {
        Storage::fake('public');
        $lguAdmin = $this->makeLguAdmin();
        Sanctum::actingAs($lguAdmin);

        $uploaded = $this->postJson('/api/lgu/profile/picture', [
            'photo' => UploadedFile::fake()->image('lgu-avatar.jpg', 300, 300)->size(500),
        ])->assertOk()->json();
        $this->assertNotNull($uploaded['profile_picture']);
        Storage::disk('public')->assertExists(str_replace('/storage/', '', parse_url($uploaded['profile_picture'], PHP_URL_PATH)));

        $removed = $this->deleteJson('/api/lgu/profile/picture')->assertOk()->json();
        $this->assertNull($removed['profile_picture']);
    }

    public function test_super_admin_can_upload_and_remove_their_profile_picture(): void
    {
        Storage::fake('public');
        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());

        $uploaded = $this->postJson('/api/super-admin/profile/picture', [
            'photo' => UploadedFile::fake()->image('admin-avatar.png', 300, 300)->size(500),
        ])->assertOk()->json();
        $this->assertNotNull($uploaded['profile_picture']);

        $this->deleteJson('/api/super-admin/profile/picture')->assertOk();
    }

    public function test_profile_picture_endpoints_reject_non_image_uploads_and_wrong_roles(): void
    {
        Storage::fake('public');

        // Wrong file type is rejected.
        $lguAdmin = $this->makeLguAdmin();
        Sanctum::actingAs($lguAdmin);
        $this->postJson('/api/lgu/profile/picture', [
            'photo' => UploadedFile::fake()->create('note.pdf', 100, 'application/pdf'),
        ])->assertStatus(422);

        // An LGU admin cannot reach the Super Admin picture endpoint.
        $this->postJson('/api/super-admin/profile/picture', [
            'photo' => UploadedFile::fake()->image('x.jpg')->size(200),
        ])->assertForbidden();
    }

    /**
     * Sellers no longer rate buyers. What a seller sees about a buyer is the
     * order history itself -- which is the signal they can act on anyway --
     * and the route to rate one is gone entirely.
     */
    public function test_a_seller_can_no_longer_rate_a_buyer(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        $order = $this->makeOrder($buyer, $this->makeListing($seller), ['status' => 'completed']);

        Sanctum::actingAs($seller->user);
        $this->postJson("/api/orders/{$order->id}/rate-buyer", ['rating' => 4, 'comment' => 'Good buyer.'])
            ->assertNotFound();

        $profile = $this->getJson("/api/seller/buyers/{$buyer->id}")->assertOk()->json();
        $this->assertArrayNotHasKey('buyer_rating', $profile);
        $this->assertArrayNotHasKey('buyer_ratings', $profile);

        // The order history a seller actually needs is still there.
        $row = collect($profile['seller_orders'])->firstWhere('id', $order->id);
        $this->assertNotNull($row);
        $this->assertArrayNotHasKey('buyerRating', $row);
    }

    public function test_the_super_admin_buyer_list_no_longer_carries_a_buyer_rating(): void
    {
        $buyer = $this->makeBuyer();

        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
        $entry = collect($this->getJson('/api/super-admin/users')->assertOk()->json('buyers'))
            ->firstWhere('id', $buyer->id);

        // The columns survive the feature removal (they are nullable and cost
        // nothing), but nothing writes them any more, so they must read empty.
        $this->assertNull($entry['buyerProfile']['rating'] ?? null);
        $this->assertSame(0, (int) ($entry['buyerProfile']['ratings_count'] ?? 0));
    }

    /**
     * Feedback runs one way now. The seller_ratings key is kept and returns an
     * empty list rather than disappearing, so an older cached frontend bundle
     * cannot crash on its absence mid-deploy.
     */
    public function test_the_reviews_endpoints_no_longer_carry_seller_ratings_of_buyers(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $seller = $this->makeSeller([], ['municipality_id' => $lguAdmin->municipality_id]);
        $buyer = $this->makeBuyer();
        $order = $this->makeOrder($buyer, $this->makeListing($seller), ['status' => 'completed']);

        Sanctum::actingAs($buyer);
        $this->postJson("/api/orders/{$order->id}/review", ['rating' => 5, 'title' => 'Great', 'comment' => 'Healthy stock.'])
            ->assertCreated();

        Sanctum::actingAs($lguAdmin);
        $this->getJson('/api/lgu/reviews')->assertOk()
            ->assertJsonCount(1, 'buyer_reviews')
            ->assertJsonCount(0, 'seller_ratings');

        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
        $this->getJson('/api/super-admin/reviews')->assertOk()
            ->assertJsonCount(1, 'buyer_reviews')
            ->assertJsonCount(0, 'seller_ratings');
    }

    public function test_lgu_can_remove_an_unfair_review_and_the_seller_rating_recomputes(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $seller = $this->makeSeller([], ['municipality_id' => $lguAdmin->municipality_id]);
        $buyer = $this->makeBuyer();
        $order = $this->makeOrder($buyer, $this->makeListing($seller), ['status' => 'completed']);

        // Buyer reviews the seller -> seller rating becomes 4.
        Sanctum::actingAs($buyer);
        $reviewId = $this->postJson("/api/orders/{$order->id}/review", ['rating' => 4, 'comment' => 'ok'])->assertCreated()->json('id');
        $this->assertEquals(4, (float) $seller->fresh()->rating);

        // LGU removes it: review gone, seller rating recomputed to 0, logged.
        Sanctum::actingAs($lguAdmin);
        $this->deleteJson("/api/lgu/reviews/{$reviewId}")->assertOk();
        $this->assertDatabaseMissing('reviews', ['id' => $reviewId]);
        $this->assertEquals(0, (float) $seller->fresh()->rating);
        $this->assertDatabaseHas('activity_logs', ['action' => 'review_removed', 'target_user_id' => $seller->user_id]);
    }

    public function test_lgu_cannot_remove_a_review_outside_their_municipality(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $otherMunicipality = Municipality::create(['name' => 'Far Town '.Str::random(4)]);
        $seller = $this->makeSeller([], ['municipality_id' => $otherMunicipality->id]);
        $buyer = $this->makeBuyer();
        $order = $this->makeOrder($buyer, $this->makeListing($seller), ['status' => 'completed']);
        Sanctum::actingAs($buyer);
        $reviewId = $this->postJson("/api/orders/{$order->id}/review", ['rating' => 5])->assertCreated()->json('id');

        Sanctum::actingAs($lguAdmin);
        $this->deleteJson("/api/lgu/reviews/{$reviewId}")->assertForbidden();
        $this->assertDatabaseHas('reviews', ['id' => $reviewId]);
    }

    public function test_super_admin_can_remove_any_review(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        $order = $this->makeOrder($buyer, $this->makeListing($seller), ['status' => 'completed']);

        Sanctum::actingAs($buyer);
        $reviewId = $this->postJson("/api/orders/{$order->id}/review", ['rating' => 1])->assertCreated()->json('id');

        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
        $this->deleteJson("/api/super-admin/reviews/{$reviewId}")->assertOk();
        $this->assertDatabaseMissing('reviews', ['id' => $reviewId]);

        // The buyer-rating moderation route went with the feature itself.
        $this->deleteJson('/api/super-admin/buyer-ratings/1')->assertNotFound();
    }

    public function test_super_admin_can_delete_any_comment(): void
    {
        $seller = $this->makeSeller();
        Sanctum::actingAs($seller->user);
        $postId = $this->postJson('/api/seller/posts', ['body' => 'Pond tour.'])->assertCreated()->json('id');

        Sanctum::actingAs($this->makeBuyer());
        $commentId = $this->postJson("/api/seller-posts/{$postId}/comments", ['body' => 'Report this.'])->assertCreated()->json('id');

        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
        $this->deleteJson("/api/seller-posts/comments/{$commentId}")->assertOk();
        $this->assertDatabaseCount('seller_post_comments', 0);
    }

    public function test_buyer_can_save_listings_to_the_cart_without_reserving_stock(): void
    {
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller, ['quantity' => 500]);
        $buyer = $this->makeBuyer();
        Sanctum::actingAs($buyer);

        $this->postJson('/api/cart', ['fingerling_listing_id' => $listing->id, 'quantity' => 100])
            ->assertCreated()
            ->assertJsonPath('quantity', 100)
            ->assertJsonPath('available', true);

        // A cart entry is a bookmark, not a reservation -- unlike placing an
        // order (see OrderController::store), it must not touch stock.
        $this->assertSame(500, $listing->fresh()->quantity);

        $cart = $this->getJson('/api/cart')->assertOk();
        $cart->assertJsonPath('count', 1);
        $this->assertSame(100 * (float) $listing->price_per_piece, (float) $cart->json('subtotal'));
    }

    public function test_saving_an_already_saved_listing_tops_up_the_same_line_rather_than_duplicating_it(): void
    {
        $listing = $this->makeListing($this->makeSeller(), ['quantity' => 500]);
        Sanctum::actingAs($this->makeBuyer());

        $this->postJson('/api/cart', ['fingerling_listing_id' => $listing->id, 'quantity' => 40])->assertCreated();
        $this->postJson('/api/cart', ['fingerling_listing_id' => $listing->id, 'quantity' => 60])
            ->assertOk()
            ->assertJsonPath('quantity', 100);

        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_cart_rejects_quantities_beyond_stock_and_reports_stale_saved_items(): void
    {
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller, ['quantity' => 10]);
        Sanctum::actingAs($this->makeBuyer());

        $this->postJson('/api/cart', ['fingerling_listing_id' => $listing->id, 'quantity' => 50])->assertStatus(422);

        $itemId = $this->postJson('/api/cart', ['fingerling_listing_id' => $listing->id, 'quantity' => 10])->assertCreated()->json('id');
        $this->patchJson("/api/cart/{$itemId}", ['quantity' => 50])->assertStatus(422);

        // Stock and price are re-read live, so a listing that sells out after
        // it was saved reports itself unavailable instead of silently
        // remaining checkout-able.
        $listing->update(['quantity' => 0]);
        $cart = $this->getJson('/api/cart')->assertOk();
        $cart->assertJsonPath('items.0.available', false);
        $cart->assertJsonPath('subtotal', 0);
    }

    public function test_a_buyer_can_only_see_and_manage_their_own_cart(): void
    {
        $listing = $this->makeListing($this->makeSeller());
        $owner = $this->makeBuyer();
        $other = $this->makeBuyer();

        Sanctum::actingAs($owner);
        $itemId = $this->postJson('/api/cart', ['fingerling_listing_id' => $listing->id, 'quantity' => 5])->assertCreated()->json('id');

        Sanctum::actingAs($other);
        $this->getJson('/api/cart')->assertOk()->assertJsonPath('count', 0);
        $this->patchJson("/api/cart/{$itemId}", ['quantity' => 1])->assertForbidden();
        $this->deleteJson("/api/cart/{$itemId}")->assertForbidden();

        // Non-buyer roles have no cart at all.
        Sanctum::actingAs($this->makeSeller()->user);
        $this->getJson('/api/cart')->assertForbidden();

        $this->assertDatabaseHas('cart_items', ['id' => $itemId, 'quantity' => 5]);
    }

    public function test_buyer_can_clear_their_cart(): void
    {
        $seller = $this->makeSeller();
        Sanctum::actingAs($this->makeBuyer());
        $this->postJson('/api/cart', ['fingerling_listing_id' => $this->makeListing($seller)->id, 'quantity' => 1])->assertCreated();
        $this->postJson('/api/cart', ['fingerling_listing_id' => $this->makeListing($seller)->id, 'quantity' => 1])->assertCreated();

        $this->deleteJson('/api/cart')->assertOk();
        $this->assertDatabaseCount('cart_items', 0);
    }

    /**
     * Without a billing object PayMongo asks the payer to type their own
     * details on its hosted page, which the browser autofills from whoever
     * last paid on that device -- so a transaction could show a name belonging
     * to a different AbaiMarket account. See App\Services\PayMongoService.
     */
    public function test_paymongo_checkout_identifies_the_buyer_who_placed_the_order(): void
    {
        config(['services.paymongo.secret_key' => 'sk_test_fake']);
        Http::fake([
            'api.paymongo.com/*' => Http::response(['data' => [
                'id' => 'cs_test_billing',
                'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_test_billing'],
            ]]),
        ]);

        $buyer = $this->makeBuyer(['name' => 'Ana Dela Cruz', 'email' => 'ana@example.com', 'phone' => '09171234567']);
        $order = $this->makeOrder($buyer, $this->makeListing($this->makeSeller()));
        $this->makePayment($order);

        Sanctum::actingAs($buyer);
        $this->postJson("/api/orders/{$order->id}/checkout")->assertOk();

        Http::assertSent(function ($request) {
            $billing = $request->data()['data']['attributes']['billing'] ?? null;

            return $billing === [
                'name' => 'Ana Dela Cruz',
                'email' => 'ana@example.com',
                'phone' => '09171234567',
            ];
        });
    }

    /**
     * phone is nullable on users. PayMongo rejects an empty string where it
     * accepts the key being absent, so a blank field is dropped, not blanked.
     */
    public function test_paymongo_billing_omits_fields_the_buyer_has_not_filled_in(): void
    {
        config(['services.paymongo.secret_key' => 'sk_test_fake']);
        Http::fake([
            'api.paymongo.com/*' => Http::response(['data' => [
                'id' => 'cs_test_nophone',
                'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_test_nophone'],
            ]]),
        ]);

        $buyer = $this->makeBuyer(['name' => 'Ben Santos', 'phone' => null]);
        $order = $this->makeOrder($buyer, $this->makeListing($this->makeSeller()));
        $this->makePayment($order);

        Sanctum::actingAs($buyer);
        $this->postJson("/api/orders/{$order->id}/checkout")->assertOk();

        Http::assertSent(function ($request) use ($buyer) {
            $billing = $request->data()['data']['attributes']['billing'] ?? null;

            return $billing === ['name' => 'Ben Santos', 'email' => $buyer->email];
        });
    }

    /**
     * The listing's photo has to reach PayMongo's hosted checkout page --
     * without it the buyer pays against a nameless line item. See
     * App\Services\PayMongoService.
     */
    public function test_paymongo_checkout_sends_the_listings_photo_as_the_line_item_image(): void
    {
        config(['services.paymongo.secret_key' => 'sk_test_fake']);
        Http::fake([
            'api.paymongo.com/*' => Http::response(['data' => [
                'id' => 'cs_test_123',
                'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_test_123'],
            ]]),
        ]);

        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller);
        // Media order is the seller's chosen order -- the lead photo wins, and
        // a video is never offered to PayMongo.
        $listing->media()->create(['type' => 'video', 'title' => 'Pond clip', 'url' => 'https://cdn.test/clip.mp4', 'position' => 0]);
        $listing->media()->create(['type' => 'photo', 'title' => 'Lead photo', 'url' => 'https://cdn.test/lead.jpg', 'position' => 1]);
        $listing->media()->create(['type' => 'photo', 'title' => 'Second photo', 'url' => 'https://cdn.test/second.jpg', 'position' => 2]);

        $buyer = $this->makeBuyer();
        $order = $this->makeOrder($buyer, $listing);
        $this->makePayment($order);

        Sanctum::actingAs($buyer);
        $this->postJson("/api/orders/{$order->id}/checkout")->assertOk();

        Http::assertSent(function ($request) {
            $lineItem = $request->data()['data']['attributes']['line_items'][0];

            return $lineItem['images'] === ['https://cdn.test/lead.jpg'];
        });
    }

    /**
     * A photo uploaded while APP_URL was localhost keeps that origin in
     * listing_media.url forever. PayMongo's checkout page can't load it, so
     * the origin is re-based onto the configured public host at send time --
     * without re-uploading anything. See App\Services\PayMongoService.
     */
    public function test_paymongo_checkout_rebases_locally_uploaded_photos_onto_the_public_https_host(): void
    {
        config([
            'app.url' => 'http://127.0.0.1:8000',
            'services.paymongo.secret_key' => 'sk_test_fake',
            'services.paymongo.asset_base_url' => 'https://fishmarket.example.ph',
        ]);
        Http::fake(['api.paymongo.com/*' => Http::response(['data' => [
            'id' => 'cs_test_123',
            'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_test_123'],
        ]])]);

        $listing = $this->makeListing($this->makeSeller());
        $listing->media()->create([
            'type' => 'photo',
            'title' => 'Pond photo',
            'url' => 'http://127.0.0.1:8000/storage/listings/9/lead.png',
            'position' => 0,
        ]);

        $buyer = $this->makeBuyer();
        $order = $this->makeOrder($buyer, $listing);
        $this->makePayment($order);

        Sanctum::actingAs($buyer);
        $this->postJson("/api/orders/{$order->id}/checkout")->assertOk();

        Http::assertSent(fn ($request) => $request->data()['data']['attributes']['line_items'][0]['images']
            === ['https://fishmarket.example.ph/storage/listings/9/lead.png']);
    }

    /**
     * Without a public host configured, a localhost photo would render as a
     * broken image box on PayMongo's HTTPS page (mixed content, and 127.0.0.1
     * is the buyer's own machine). No image beats a broken one.
     */
    public function test_paymongo_checkout_drops_a_non_https_image_rather_than_sending_a_broken_one(): void
    {
        config([
            'app.url' => 'http://127.0.0.1:8000',
            'services.paymongo.secret_key' => 'sk_test_fake',
            'services.paymongo.asset_base_url' => null,
        ]);
        Http::fake(['api.paymongo.com/*' => Http::response(['data' => [
            'id' => 'cs_test_123',
            'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_test_123'],
        ]])]);

        $listing = $this->makeListing($this->makeSeller());
        $listing->media()->create([
            'type' => 'photo',
            'title' => 'Pond photo',
            'url' => 'http://127.0.0.1:8000/storage/listings/9/lead.png',
            'position' => 0,
        ]);

        $buyer = $this->makeBuyer();
        $order = $this->makeOrder($buyer, $listing);
        $this->makePayment($order);

        Sanctum::actingAs($buyer);
        $this->postJson("/api/orders/{$order->id}/checkout")->assertOk();

        Http::assertSent(fn ($request) => ! array_key_exists('images', $request->data()['data']['attributes']['line_items'][0]));
    }

    public function test_paymongo_checkout_omits_the_image_key_for_a_listing_with_no_photo(): void
    {
        config(['services.paymongo.secret_key' => 'sk_test_fake']);
        Http::fake([
            'api.paymongo.com/*' => Http::response(['data' => [
                'id' => 'cs_test_123',
                'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_test_123'],
            ]]),
        ]);

        $buyer = $this->makeBuyer();
        $order = $this->makeOrder($buyer, $this->makeListing($this->makeSeller()));
        $this->makePayment($order);

        Sanctum::actingAs($buyer);
        $this->postJson("/api/orders/{$order->id}/checkout")->assertOk();

        Http::assertSent(function ($request) {
            $lineItem = $request->data()['data']['attributes']['line_items'][0];

            return ! array_key_exists('images', $lineItem) && $lineItem['name'] === 'Bangus Fingerlings';
        });
    }

    public function test_super_admin_can_permanently_remove_a_buyer_with_a_reason_email_and_audit_trail(): void
    {
        Mail::fake();
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        $buyer = $this->makeBuyer(['name' => 'Spam Signup']);
        $buyerId = $buyer->id;
        Sanctum::actingAs($superAdmin);

        $this->deleteJson("/api/super-admin/buyers/{$buyerId}", [
            'reason' => 'Spam Account',
            'notes' => 'Signed up 40 times in an hour.',
        ])->assertOk();

        Mail::assertSent(AccountRemovedMail::class, fn ($mail) => $mail->hasTo($buyer->email) && $mail->reason === 'Spam Account');

        $this->assertDatabaseMissing('users', ['id' => $buyerId]);
        $this->assertDatabaseMissing('buyer_profiles', ['user_id' => $buyerId]);

        // The audit entry has to outlive the account it documents:
        // activity_logs.target_user_id is nullOnDelete, so the description is
        // the only surviving record of who was removed.
        $entry = ActivityLogEntry::where('action', 'buyer_removed')->sole();
        $this->assertNull($entry->target_user_id);
        $this->assertSame($superAdmin->id, $entry->actor_id);
        $this->assertStringContainsString('Spam Signup', $entry->description);
        $this->assertStringContainsString($buyer->email, $entry->description);
        $this->assertStringContainsString('Spam Account', $entry->description);
    }

    public function test_super_admin_can_permanently_remove_a_seller_and_its_unordered_listings(): void
    {
        Mail::fake();
        $seller = $this->makeSeller();
        $sellerId = $seller->id;
        $userId = $seller->user_id;
        $listing = $this->makeListing($seller);

        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
        $this->deleteJson("/api/super-admin/sellers/{$sellerId}", ['reason' => 'Fake Hatchery Details'])->assertOk();

        Mail::assertSent(AccountRemovedMail::class);
        $this->assertDatabaseMissing('users', ['id' => $userId]);
        $this->assertDatabaseMissing('seller_profiles', ['id' => $sellerId]);
        // The hatchery's listings were never ordered, so they go with it.
        $this->assertDatabaseMissing('listings', ['id' => $listing->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'seller_removed']);
    }

    /**
     * The guard that protects the financial record: every transactional table
     * cascades off users.id, so removing an account that has traded would take
     * its orders/payments/settlements with it. See App\Support\AccountModeration.
     */
    public function test_an_account_with_order_history_cannot_be_removed_and_keeps_all_its_records(): void
    {
        Mail::fake();
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        $listing = $this->makeListing($seller);
        $order = $this->makeOrder($buyer, $listing, ['status' => 'completed']);
        $payment = $this->makePayment($order, ['status' => 'paid_held']);

        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());

        $this->deleteJson("/api/super-admin/buyers/{$buyer->id}", ['reason' => 'Spam Account'])->assertStatus(422);
        $this->deleteJson("/api/super-admin/sellers/{$seller->id}", ['reason' => 'Spam Account'])->assertStatus(422);

        Mail::assertNotSent(AccountRemovedMail::class);
        $this->assertDatabaseHas('users', ['id' => $buyer->id]);
        $this->assertDatabaseHas('users', ['id' => $seller->user_id]);
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
        $this->assertDatabaseHas('payments', ['id' => $payment->id]);
    }

    public function test_removing_an_account_requires_an_enumerated_reason_and_is_super_admin_only(): void
    {
        $buyer = $this->makeBuyer();
        $seller = $this->makeSeller();

        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
        $this->deleteJson("/api/super-admin/buyers/{$buyer->id}")->assertStatus(422);
        $this->deleteJson("/api/super-admin/buyers/{$buyer->id}", ['reason' => 'I felt like it'])->assertStatus(422);
        $this->assertDatabaseHas('users', ['id' => $buyer->id]);

        // A Super Admin can't remove a non-buyer through the buyer route.
        $this->deleteJson('/api/super-admin/buyers/'.$seller->user_id, ['reason' => 'Spam Account'])->assertNotFound();

        // Every other role is locked out entirely.
        Sanctum::actingAs($this->makeLguAdmin());
        $this->deleteJson("/api/super-admin/buyers/{$buyer->id}", ['reason' => 'Spam Account'])->assertForbidden();
        Sanctum::actingAs($buyer);
        $this->deleteJson("/api/super-admin/buyers/{$buyer->id}", ['reason' => 'Spam Account'])->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $buyer->id]);
    }

    /**
     * A rejected order's payment deliberately stays 'paid_held', but it can
     * never settle while rejected -- so counting it in Pending Balance showed
     * the seller money that was never coming. See App\Support\SellerWallet.
     */
    public function test_rejected_earnings_are_excluded_from_the_sellers_pending_balance(): void
    {
        $lgu = $this->makeLguAdmin();
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller);
        $buyer = $this->makeBuyer();

        $rejected = $this->makeOrder($buyer, $listing, ['status' => 'completed']);
        $rejectedPayment = $this->makePayment($rejected, ['status' => 'paid_held']);
        $healthy = $this->makeOrder($buyer, $listing, ['status' => 'completed']);
        $this->makePayment($healthy, ['status' => 'paid_held']);

        Sanctum::actingAs($seller->user);
        $before = $this->getJson('/api/seller/wallet')->assertOk()->json('pending_balance');

        Sanctum::actingAs($lgu);
        $this->patchJson("/api/lgu/payments/{$rejectedPayment->id}/reject", ['reason' => 'Quantity mismatch reported.'])->assertOk();

        Sanctum::actingAs($seller->user);
        $after = $this->getJson('/api/seller/wallet')->assertOk()->json('pending_balance');

        // The money is still held...
        $this->assertSame('paid_held', $rejectedPayment->fresh()->status);
        // ...but it's no longer projected as the seller's.
        $this->assertSame(round($before / 2, 2), round((float) $after, 2));
    }

    /**
     * Rejection used to be a dead end: the payment stays held, the order is
     * filtered out of the approval queue, and every review action refuses an
     * already-rejected order -- so nothing could resolve the escrow. Reopening
     * is the way back. See LguController::reopenRejectedEarnings.
     */
    public function test_lgu_can_reopen_a_rejected_transaction_and_then_approve_it(): void
    {
        Mail::fake();
        $lgu = $this->makeLguAdmin();
        $seller = $this->makeSeller();
        $order = $this->makeOrder($this->makeBuyer(), $this->makeListing($seller), ['status' => 'completed']);
        $payment = $this->makePayment($order, ['status' => 'paid_held']);

        Sanctum::actingAs($lgu);
        $this->patchJson("/api/lgu/payments/{$payment->id}/reject", ['reason' => 'Suspected fake delivery.'])->assertOk();

        // Gone from the approval queue, but visible (and still held) here.
        $this->assertCount(0, $this->getJson('/api/lgu/earnings')->assertOk()->json());
        $rejectedList = $this->getJson('/api/lgu/earnings/rejected')->assertOk()->json();
        $this->assertCount(1, $rejectedList);
        $this->assertSame('Suspected fake delivery.', $rejectedList[0]['order']['lgu_review_reason']);

        // While rejected, it cannot be approved.
        $this->patchJson("/api/lgu/payments/{$payment->id}/approve")->assertStatus(422);

        $this->patchJson("/api/lgu/payments/{$payment->id}/reopen")->assertOk();

        // Back in the queue, out of the rejected list, and now approvable.
        $this->assertCount(0, $this->getJson('/api/lgu/earnings/rejected')->assertOk()->json());
        $this->assertCount(1, $this->getJson('/api/lgu/earnings')->assertOk()->json());
        $this->assertNull($order->fresh()->lgu_review_status);

        $this->patchJson("/api/lgu/payments/{$payment->id}/approve")->assertOk();
        $this->assertSame('released', $payment->fresh()->status);
        $this->assertDatabaseHas('settlements', ['order_id' => $order->id]);

        // The seller is told at both ends -- on rejection and on reopening.
        $this->assertDatabaseHas('notifications', ['user_id' => $seller->user_id, 'type' => "earnings_rejected:{$order->id}"]);
        $this->assertDatabaseHas('notifications', ['user_id' => $seller->user_id, 'type' => "earnings_reopened:{$order->id}"]);
    }

    public function test_reopen_is_scoped_to_the_lgus_municipality_and_rejects_non_rejected_orders(): void
    {
        $seller = $this->makeSeller();
        $order = $this->makeOrder($this->makeBuyer(), $this->makeListing($seller), ['status' => 'completed']);
        $payment = $this->makePayment($order, ['status' => 'paid_held']);

        // Not rejected yet -- there's nothing to reopen.
        Sanctum::actingAs($this->makeLguAdmin());
        $this->patchJson("/api/lgu/payments/{$payment->id}/reopen")->assertStatus(422);

        $this->patchJson("/api/lgu/payments/{$payment->id}/reject", ['reason' => 'Investigating.'])->assertOk();

        // An admin from another municipality can neither see nor reopen it.
        $otherMunicipality = Municipality::where('id', '!=', $seller->municipality_id)->firstOrFail();
        Sanctum::actingAs($this->makeLguAdmin(['municipality_id' => $otherMunicipality->id]));
        $this->assertCount(0, $this->getJson('/api/lgu/earnings/rejected')->assertOk()->json());
        $this->patchJson("/api/lgu/payments/{$payment->id}/reopen")->assertForbidden();

        $this->assertSame('rejected', $order->fresh()->lgu_review_status);
    }

    public function test_ai_assistant_replies_in_the_chosen_language_and_rejects_unsupported_ones(): void
    {
        Sanctum::actingAs($this->makeBuyer());

        // Pinning the language overrides what the message itself looks like:
        // this question is plain English, but the reply language is Bisaya.
        $this->postJson('/api/ai-assistant/ask', ['question' => 'How do I place an order?', 'language' => 'Bisaya'])
            ->assertCreated()
            ->assertJsonPath('language', 'Bisaya');

        $this->postJson('/api/ai-assistant/ask', ['question' => 'How do I place an order?', 'language' => 'Tagalog'])
            ->assertCreated()
            ->assertJsonPath('language', 'Tagalog');

        // Omitting it keeps the original auto-detect behaviour.
        $this->postJson('/api/ai-assistant/ask', ['question' => 'Unsa ang presyo sa bangus?'])
            ->assertCreated()
            ->assertJsonPath('language', 'Bisaya');

        $this->postJson('/api/ai-assistant/ask', ['question' => 'How do I place an order?', 'language' => 'Klingon'])
            ->assertStatus(422);
    }

    public function test_guests_cannot_like_or_comment_and_see_no_like_state(): void
    {
        $seller = $this->makeSeller();
        $post = \App\Models\SellerPost::create(['seller_profile_id' => $seller->id, 'body' => 'Public post.']);

        // Unauthenticated writes are rejected.
        $this->postJson("/api/seller-posts/{$post->id}/like")->assertUnauthorized();
        $this->postJson("/api/seller-posts/{$post->id}/comments", ['body' => 'hi'])->assertUnauthorized();

        // A guest still reads the feed, just with no personal like state.
        $payload = $this->getJson("/api/sellers/{$seller->id}")->assertOk()->json('posts.0');
        $this->assertSame(0, $payload['likes_count']);
        $this->assertFalse($payload['liked_by_me']);
    }

    // ---------------------------------------------------------------------
    // Seller Registration Approval -- App\Support\SellerApproval. One
    // approval is enough: the LGU Admin normally reviews their own
    // municipality's sellers, and the Super Admin is the fallback reviewer.
    // ---------------------------------------------------------------------

    /** A seller pending review, in the given LGU admin's municipality. */
    private function makePendingSeller(?User $lguAdmin = null): SellerProfile
    {
        $municipalityId = $lguAdmin?->municipality_id ?? Municipality::first()->id;

        return $this->makeSeller(
            ['municipality_id' => $municipalityId],
            ['municipality_id' => $municipalityId, 'verified' => false, 'status' => 'pending', 'approval_status' => SellerApproval::PENDING]
        );
    }

    public function test_a_newly_registered_seller_starts_pending_approval(): void
    {
        $municipality = Municipality::first();

        $this->postJson('/api/auth/register', [
            'name' => 'Brand New Hatchery',
            'email' => 'brand-new-hatchery@example.test',
            'password' => 'Password123!',
            'role' => 'seller',
            'municipality_id' => $municipality->id,
        ])->assertCreated();

        $seller = SellerProfile::whereHas('user', fn ($q) => $q->where('email', 'brand-new-hatchery@example.test'))->firstOrFail();

        $this->assertSame(SellerApproval::PENDING, $seller->approval_status);
        $this->assertFalse($seller->verified);
    }

    public function test_a_seller_cannot_list_until_their_registration_is_approved(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $seller = $this->makePendingSeller($lguAdmin);
        $payload = ['species' => 'Bangus', 'title' => 'Bangus Fingerlings', 'quantity' => 100, 'price_per_piece' => 5];

        // Still awaiting review -- listing is refused.
        Sanctum::actingAs($seller->user);
        $this->postListing($payload)->assertStatus(403);

        // The LGU Admin approves. That single approval verifies the seller.
        Sanctum::actingAs($lguAdmin);
        $this->patchJson("/api/lgu/sellers/{$seller->id}/approve-registration")
            ->assertOk()
            ->assertJsonPath('approval_status', SellerApproval::APPROVED)
            ->assertJsonPath('approval_status_label', 'Approved')
            ->assertJsonPath('verified', true)
            ->assertJsonPath('status', 'verified');
        $this->assertDatabaseHas('notifications', [
            'user_id' => $seller->user_id,
            'type' => 'seller_registration_approved',
        ]);

        Sanctum::actingAs($seller->user->fresh());
        $this->postListing($payload)->assertCreated();
    }

    public function test_the_super_admin_can_approve_a_registration_on_their_own_as_the_fallback_reviewer(): void
    {
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        $seller = $this->makePendingSeller();

        // No LGU involvement at all -- the Super Admin covers for an LGU Admin
        // who is away, and one approval is all it takes.
        Sanctum::actingAs($superAdmin);
        $this->patchJson("/api/super-admin/sellers/{$seller->id}/approve-registration")
            ->assertOk()
            ->assertJsonPath('approval_status', SellerApproval::APPROVED)
            ->assertJsonPath('verified', true);

        $seller->refresh();
        $this->assertNotNull($seller->super_admin_reviewed_at);
        $this->assertNull($seller->lgu_reviewed_at);

        Sanctum::actingAs($seller->user->fresh());
        $this->postListing(['species' => 'Tilapia', 'title' => 'Tilapia Fingerlings', 'quantity' => 50, 'price_per_piece' => 3])
            ->assertCreated();
    }

    public function test_an_already_approved_registration_cannot_be_approved_again(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $seller = $this->makeSeller(
            ['municipality_id' => $lguAdmin->municipality_id],
            ['municipality_id' => $lguAdmin->municipality_id]
        );

        Sanctum::actingAs($lguAdmin);
        $this->patchJson("/api/lgu/sellers/{$seller->id}/approve-registration")->assertStatus(422);
    }

    public function test_either_reviewer_can_reject_a_registration_with_a_reason_and_the_seller_is_notified(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $seller = $this->makePendingSeller($lguAdmin);

        Sanctum::actingAs($lguAdmin);
        $this->patchJson("/api/lgu/sellers/{$seller->id}/reject-registration")->assertStatus(422);
        $this->patchJson("/api/lgu/sellers/{$seller->id}/reject-registration", ['reason' => 'Business permit is missing.'])
            ->assertOk()
            ->assertJsonPath('approval_status', SellerApproval::REJECTED)
            ->assertJsonPath('approval_status_label', 'Rejected')
            ->assertJsonPath('verified', false);

        $notification = AppNotification::where('user_id', $seller->user_id)->where('type', 'seller_registration_rejected')->firstOrFail();
        $this->assertStringContainsString('Business permit is missing.', $notification->body);

        // A rejection is reversible -- and by either reviewer, not only the
        // one who rejected it.
        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
        $this->patchJson("/api/super-admin/sellers/{$seller->id}/approve-registration")
            ->assertOk()
            ->assertJsonPath('approval_status', SellerApproval::APPROVED)
            ->assertJsonPath('verified', true);
    }

    public function test_a_rejection_reason_is_private_to_the_seller_and_their_reviewers(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $seller = $this->makePendingSeller($lguAdmin);

        Sanctum::actingAs($lguAdmin);
        $this->patchJson("/api/lgu/sellers/{$seller->id}/reject-registration", ['reason' => 'Permit number does not match DA records.'])->assertOk();

        // Never on the public hatchery page.
        $this->getJson("/api/sellers/{$seller->id}")
            ->assertOk()
            ->assertJsonMissing(['registration_rejection_reason' => 'Permit number does not match DA records.']);

        // The reviewer's own queue does see it...
        Sanctum::actingAs($lguAdmin);
        $this->getJson('/api/lgu/seller-registrations')
            ->assertOk()
            ->assertJsonFragment(['registration_rejection_reason' => 'Permit number does not match DA records.']);

        // ...and so does the seller, so the dashboard banner can explain it.
        Sanctum::actingAs($seller->user->fresh());
        $this->getJson('/api/seller/dashboard')
            ->assertOk()
            ->assertJsonPath('seller.registration_rejection_reason', 'Permit number does not match DA records.');
    }

    public function test_an_lgu_admin_reviews_only_their_own_municipality_while_the_super_admin_sees_everything(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $otherMunicipality = Municipality::where('id', '!=', $lguAdmin->municipality_id)->firstOrFail();
        $ownSeller = $this->makePendingSeller($lguAdmin);
        $outsideSeller = $this->makeSeller(
            ['municipality_id' => $otherMunicipality->id],
            ['municipality_id' => $otherMunicipality->id, 'verified' => false, 'status' => 'pending', 'approval_status' => SellerApproval::PENDING]
        );

        // The LGU queue is its own municipality only, and so is its authority.
        Sanctum::actingAs($lguAdmin);
        $lguQueue = collect($this->getJson('/api/lgu/seller-registrations')->assertOk()->json())->pluck('id');
        $this->assertTrue($lguQueue->contains($ownSeller->id));
        $this->assertFalse($lguQueue->contains($outsideSeller->id));

        $this->patchJson("/api/lgu/sellers/{$outsideSeller->id}/approve-registration")->assertStatus(403);

        // The Super Admin's queue is platform-wide -- that is what makes them
        // a usable fallback for any municipality.
        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
        $superQueue = collect($this->getJson('/api/super-admin/seller-registrations')->assertOk()->json())->pluck('id');
        $this->assertTrue($superQueue->contains($ownSeller->id));
        $this->assertTrue($superQueue->contains($outsideSeller->id));

        $this->patchJson("/api/super-admin/sellers/{$outsideSeller->id}/approve-registration")->assertOk();
    }

    // ---------------------------------------------------------------------
    // User Reports (App\Support\UserReports) and the automatic low-rating
    // Notice to Explain (App\Support\SellerReputation).
    // ---------------------------------------------------------------------

    public function test_a_buyer_can_report_a_seller_and_it_reaches_the_right_reviewers(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $seller = $this->makeSeller(
            ['municipality_id' => $lguAdmin->municipality_id],
            ['municipality_id' => $lguAdmin->municipality_id, 'hatchery_name' => 'Dodgy Hatchery']
        );
        $buyer = $this->makeBuyer();

        Sanctum::actingAs($buyer);
        $this->getJson('/api/reports/reasons')->assertOk()->assertJsonPath('reasons.0', 'Item not as described');

        $this->postJson('/api/reports', [
            'reported_user_id' => $seller->user_id,
            'reason' => 'Order never delivered',
            'description' => 'Paid two weeks ago and the fingerlings never arrived.',
        ])
            ->assertCreated()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('reporter_role', 'buyer')
            ->assertJsonPath('reported_role', 'seller')
            // Scoped to the seller's municipality, which is what an LGU sees.
            ->assertJsonPath('municipality_id', $lguAdmin->municipality_id);

        // Both the municipality's LGU Admin and the Super Admin are told.
        $this->assertDatabaseHas('notifications', ['user_id' => $lguAdmin->id, 'type' => 'user_report_filed']);
        $this->assertDatabaseHas('notifications', [
            'user_id' => User::where('role', 'super_admin')->value('id'),
            'type' => 'user_report_filed',
        ]);

        // A second open report against the same seller is refused.
        $this->postJson('/api/reports', [
            'reported_user_id' => $seller->user_id,
            'reason' => 'Seller unresponsive',
            'description' => 'Still no reply from this hatchery at all.',
        ])->assertStatus(422);

        Sanctum::actingAs($buyer);
        $this->getJson('/api/reports/mine')->assertOk()->assertJsonCount(1);
    }

    public function test_a_seller_can_report_a_buyer_and_the_directions_are_enforced(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        $otherBuyer = $this->makeBuyer();

        Sanctum::actingAs($seller->user);
        $this->getJson('/api/reports/reasons')->assertOk()->assertJsonPath('reasons.0', 'Payment issue');

        $this->postJson('/api/reports', [
            'reported_user_id' => $buyer->id,
            'reason' => 'Payment issue',
            'description' => 'Buyer disputed a payment that was already delivered and accepted.',
        ])
            ->assertCreated()
            ->assertJsonPath('reporter_role', 'seller')
            ->assertJsonPath('reported_role', 'buyer');

        // A seller cannot report another seller, and nobody reports themselves.
        $otherSeller = $this->makeSeller();
        $this->postJson('/api/reports', [
            'reported_user_id' => $otherSeller->user_id,
            'reason' => 'Payment issue',
            'description' => 'Trying to report a fellow seller, which is not allowed.',
        ])->assertStatus(422);

        $this->postJson('/api/reports', [
            'reported_user_id' => $seller->user_id,
            'reason' => 'Payment issue',
            'description' => 'Trying to report my own account, which is not allowed.',
        ])->assertStatus(422);

        // A buyer likewise cannot report another buyer.
        Sanctum::actingAs($buyer);
        $this->postJson('/api/reports', [
            'reported_user_id' => $otherBuyer->id,
            'reason' => 'Suspected fraud',
            'description' => 'Trying to report a fellow buyer, which is not allowed.',
        ])->assertStatus(422);
    }

    public function test_an_lgu_manages_only_its_own_municipalitys_reports_while_super_admin_manages_all(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $otherMunicipality = Municipality::where('id', '!=', $lguAdmin->municipality_id)->firstOrFail();

        $ownSeller = $this->makeSeller(
            ['municipality_id' => $lguAdmin->municipality_id],
            ['municipality_id' => $lguAdmin->municipality_id]
        );
        $outsideSeller = $this->makeSeller(
            ['municipality_id' => $otherMunicipality->id],
            ['municipality_id' => $otherMunicipality->id]
        );
        $buyer = $this->makeBuyer();

        Sanctum::actingAs($buyer);
        $ownReportId = $this->postJson('/api/reports', [
            'reported_user_id' => $ownSeller->user_id,
            'reason' => 'Poor fingerling quality or health',
            'description' => 'Most of the fingerlings arrived in very poor condition.',
        ])->assertCreated()->json('id');
        $outsideReportId = $this->postJson('/api/reports', [
            'reported_user_id' => $outsideSeller->user_id,
            'reason' => 'Seller unresponsive',
            'description' => 'No response to any of my messages about this order.',
        ])->assertCreated()->json('id');

        // The LGU sees and can act on its own municipality's report only.
        Sanctum::actingAs($lguAdmin);
        $lguList = collect($this->getJson('/api/lgu/user-reports')->assertOk()->json())->pluck('id');
        $this->assertTrue($lguList->contains($ownReportId));
        $this->assertFalse($lguList->contains($outsideReportId));

        $this->patchJson("/api/lgu/user-reports/{$outsideReportId}", ['status' => 'dismissed'])->assertStatus(403);

        $this->patchJson("/api/lgu/user-reports/{$ownReportId}", [
            'status' => 'resolved',
            'resolution_notes' => 'Spoke with the hatchery; replacement stock was sent.',
        ])->assertOk()->assertJsonPath('status', 'resolved');

        // Closing it tells the reporter the outcome.
        $this->assertDatabaseHas('notifications', ['user_id' => $buyer->id, 'type' => 'user_report_resolved']);

        // The Super Admin's list is platform-wide and they can act anywhere.
        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
        $superList = collect($this->getJson('/api/super-admin/user-reports')->assertOk()->json())->pluck('id');
        $this->assertTrue($superList->contains($ownReportId));
        $this->assertTrue($superList->contains($outsideReportId));

        $this->patchJson("/api/super-admin/user-reports/{$outsideReportId}", ['status' => 'under_review'])
            ->assertOk()
            ->assertJsonPath('status', 'under_review');
    }

    public function test_a_low_average_rating_raises_a_notice_to_explain_without_suspending_the_seller(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $seller = $this->makeSeller(
            ['municipality_id' => $lguAdmin->municipality_id],
            ['municipality_id' => $lguAdmin->municipality_id, 'hatchery_name' => 'Struggling Hatchery']
        );
        $listing = $this->makeListing($seller, ['approval_status' => 'approved']);

        // Two 2-star reviews take the average to 2.00, below the threshold.
        foreach ([2, 2] as $stars) {
            $buyer = $this->makeBuyer();
            $order = $this->makeOrder($buyer, $listing, ['status' => 'completed']);
            Sanctum::actingAs($buyer);
            $this->postJson("/api/orders/{$order->id}/review", ['rating' => $stars, 'comment' => 'Not good.'])->assertCreated();
        }

        $seller->refresh();
        $this->assertEquals(2.00, (float) $seller->rating);

        // The notice exists, snapshotted at the moment it fired -- which is
        // the FIRST 2-star review, since one review already puts the average
        // at 2.00. The seller's live average keeps moving; the snapshot does
        // not, which is what the LGU needs to see.
        $notice = SellerNotice::where('seller_profile_id', $seller->id)->firstOrFail();
        $this->assertSame('low_rating', $notice->type);
        $this->assertSame('open', $notice->status);
        $this->assertEquals(2.00, (float) $notice->average_rating);
        $this->assertSame(1, $notice->ratings_count);

        // Critically: the seller is NOT suspended and can still trade.
        $this->assertSame('verified', $seller->status);
        $this->assertTrue((bool) $seller->verified);

        // Seller and LGU are both notified.
        $this->assertDatabaseHas('notifications', ['user_id' => $seller->user_id, 'type' => 'seller_notice_to_explain']);
        $this->assertDatabaseHas('notifications', ['user_id' => $lguAdmin->id, 'type' => 'seller_low_rating']);

        // Only ONE open notice, however many more bad reviews arrive.
        $buyer = $this->makeBuyer();
        $order = $this->makeOrder($buyer, $listing, ['status' => 'completed']);
        Sanctum::actingAs($buyer);
        $this->postJson("/api/orders/{$order->id}/review", ['rating' => 1, 'comment' => 'Worse.'])->assertCreated();
        $this->assertSame(1, SellerNotice::where('seller_profile_id', $seller->id)->count());
    }

    public function test_a_good_rating_never_raises_a_notice(): void
    {
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller, ['approval_status' => 'approved']);
        $buyer = $this->makeBuyer();
        $order = $this->makeOrder($buyer, $listing, ['status' => 'completed']);

        Sanctum::actingAs($buyer);
        $this->postJson("/api/orders/{$order->id}/review", ['rating' => 5, 'comment' => 'Excellent stock.'])->assertCreated();

        $this->assertSame(0, SellerNotice::where('seller_profile_id', $seller->id)->count());
    }

    public function test_the_seller_answers_a_notice_and_only_their_lgu_can_close_it(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $otherMunicipality = Municipality::where('id', '!=', $lguAdmin->municipality_id)->firstOrFail();
        $seller = $this->makeSeller(
            ['municipality_id' => $lguAdmin->municipality_id],
            ['municipality_id' => $lguAdmin->municipality_id]
        );
        $notice = SellerReputation::raiseLowRatingNotice($seller, 2.5, 4);
        $this->assertNotNull($notice);

        // The seller explains. Answering moves it to under_review but does not
        // close it -- that is the LGU's call.
        Sanctum::actingAs($seller->user);
        $this->getJson('/api/seller/notices')->assertOk()->assertJsonCount(1);
        $this->postJson("/api/seller/notices/{$notice->id}/respond", [
            'response' => 'A delivery partner failed us for two weeks; we have changed couriers.',
        ])->assertOk()->assertJsonPath('status', 'under_review');

        $this->assertDatabaseHas('notifications', ['user_id' => $lguAdmin->id, 'type' => 'seller_notice_answered']);

        // An LGU Admin from another municipality cannot touch it.
        $outsideAdmin = $this->makeLguAdmin(['municipality_id' => $otherMunicipality->id]);
        Sanctum::actingAs($outsideAdmin);
        $this->getJson('/api/lgu/seller-notices')->assertOk()->assertJsonCount(0);
        $this->patchJson("/api/lgu/seller-notices/{$notice->id}/accept", [])->assertStatus(403);

        // The seller's own LGU accepts the explanation, and the seller is told.
        Sanctum::actingAs($lguAdmin);
        $this->getJson('/api/lgu/seller-notices')->assertOk()->assertJsonCount(1);
        $this->patchJson("/api/lgu/seller-notices/{$notice->id}/accept", [
            'notes' => 'Explanation accepted; will monitor for one month.',
        ])->assertOk()->assertJsonPath('status', 'accepted');

        $this->assertDatabaseHas('notifications', ['user_id' => $seller->user_id, 'type' => 'seller_notice_accepted']);
        $this->assertStringStartsWith('Your LGU accepted your explanation.',
            AppNotification::where('user_id', $seller->user_id)->where('type', 'seller_notice_accepted')->value('body'));

        // Closed notices can no longer be answered.
        Sanctum::actingAs($seller->user);
        $this->getJson('/api/seller/notices')->assertOk()->assertJsonPath('0.reviewed_by_label', 'Your LGU');
        $this->postJson("/api/seller/notices/{$notice->id}/respond", ['response' => 'One more thing to add here.'])
            ->assertStatus(422);
    }

    /**
     * A seller's first bad run is a warning, not a sanction: a rating can fall
     * because a buyer was trolling, and taking the shop down on one notice
     * would punish the seller for that.
     */
    public function test_a_first_notice_is_a_warning_and_leaves_the_listings_up(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $seller = $this->makeSeller(
            ['municipality_id' => $lguAdmin->municipality_id],
            ['municipality_id' => $lguAdmin->municipality_id]
        );
        $listing = $this->makeListing($seller);
        $buyer = $this->makeBuyer();

        $notice = SellerReputation::raiseLowRatingNotice($seller, 2.5, 4);
        $this->assertNotNull($notice);

        // Nothing is taken away on the first notice.
        $this->assertNull($seller->fresh()->listings_frozen_at);
        $this->assertTrue(collect($this->getJson('/api/listings')->assertOk()->json())->contains('id', $listing->id));

        // They can still sell and still post.
        Sanctum::actingAs($buyer);
        $this->postJson('/api/orders', ['fingerling_listing_id' => $listing->id, 'quantity' => 10])->assertCreated();

        Sanctum::actingAs($seller->user);
        $this->postListing(['species' => 'Tilapia', 'title' => 'Still allowed', 'quantity' => 10, 'price_per_piece' => 5])
            ->assertCreated();

        // Even a REJECTED explanation on a first notice does not freeze them --
        // it only records an offense for the LGU to weigh.
        Sanctum::actingAs($lguAdmin);
        $this->patchJson("/api/lgu/seller-notices/{$notice->id}/reject", [
            'reason' => 'The explanation does not address the complaints raised.',
        ])->assertOk()->assertJsonPath('status', 'rejected');

        $this->assertSame(1, SellerSanctions::offenseCount($seller->id));
        $this->assertNull($seller->fresh()->listings_frozen_at);
        $this->assertNotSame('suspended', $seller->fresh()->status);
    }

    public function test_a_second_notice_freezes_the_listings_until_the_explanation_is_accepted(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $seller = $this->makeSeller(
            ['municipality_id' => $lguAdmin->municipality_id],
            ['municipality_id' => $lguAdmin->municipality_id]
        );
        $listing = $this->makeListing($seller);
        $buyer = $this->makeBuyer();

        // First notice, closed by the LGU so a second one can be raised.
        $first = SellerReputation::raiseLowRatingNotice($seller, 2.5, 4);
        Sanctum::actingAs($seller->user);
        $this->postJson("/api/seller/notices/{$first->id}/respond", ['response' => 'A courier failed us for two weeks.'])->assertOk();
        Sanctum::actingAs($lguAdmin);
        $this->patchJson("/api/lgu/seller-notices/{$first->id}/accept", [])->assertOk();
        $this->assertNull($seller->fresh()->listings_frozen_at);

        // Second notice -- now the shop comes down.
        $second = SellerReputation::raiseLowRatingNotice($seller->fresh(), 2.2, 6);
        $this->assertNotNull($second);
        $this->assertNotNull($seller->fresh()->listings_frozen_at);

        $this->assertFalse(collect($this->getJson('/api/listings')->assertOk()->json())->contains('id', $listing->id));
        $this->getJson("/api/listings/{$listing->id}")->assertStatus(404);

        // A buyer holding the id from a cart or an open tab still cannot order.
        Sanctum::actingAs($buyer);
        $this->postJson('/api/orders', ['fingerling_listing_id' => $listing->id, 'quantity' => 10])->assertStatus(422);

        // The seller can still sign in and answer, but cannot post around it.
        Sanctum::actingAs($seller->user);
        $this->postListing(['species' => 'Tilapia', 'title' => 'Blocked', 'quantity' => 10, 'price_per_piece' => 5])
            ->assertStatus(403);
        $this->postJson("/api/seller/notices/{$second->id}/respond", ['response' => 'We have replaced the courier entirely.'])->assertOk();

        // Accepting reopens the shop, and no offense is recorded.
        Sanctum::actingAs($lguAdmin);
        $this->patchJson("/api/lgu/seller-notices/{$second->id}/accept", [])->assertOk()->assertJsonPath('status', 'accepted');

        $this->assertNull($seller->fresh()->listings_frozen_at);
        $this->assertSame(0, SellerSanctions::offenseCount($seller->id));
        $this->assertTrue(collect($this->getJson('/api/listings')->assertOk()->json())->contains('id', $listing->id));
    }

    /**
     * Suspension is a judgement call, never a counter. However many
     * explanations are rejected, the account stays active until a human
     * decides otherwise.
     */
    public function test_repeated_rejected_explanations_never_suspend_the_account_on_their_own(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $seller = $this->makeSeller(
            ['municipality_id' => $lguAdmin->municipality_id],
            ['municipality_id' => $lguAdmin->municipality_id]
        );

        foreach ([1, 2, 3, 4] as $round) {
            $notice = SellerReputation::raiseLowRatingNotice($seller->fresh(), 2.0, 5);
            $this->assertNotNull($notice, "notice {$round} should have been raised");

            Sanctum::actingAs($lguAdmin);
            $this->patchJson("/api/lgu/seller-notices/{$notice->id}/reject", [
                'reason' => 'The explanation still does not address the delivery complaints.',
            ])->assertOk();

            $this->assertSame($round, SellerSanctions::offenseCount($seller->id));
            $this->assertNotSame('suspended', $seller->fresh()->status, "must not auto-suspend at offense {$round}");
        }

        // The LGU can still suspend by hand whenever it judges it warranted,
        // and reinstating lifts the freeze with it.
        Sanctum::actingAs($lguAdmin);
        $this->patchJson("/api/lgu/sellers/{$seller->id}/suspend", ['reason' => 'Repeated unresolved delivery complaints.'])->assertOk();
        $this->assertSame('suspended', $seller->fresh()->status);

        AccountModeration::reinstateSeller($seller->fresh(), $lguAdmin, 'Appeal upheld.');
        $this->assertNotSame('suspended', $seller->fresh()->status);
        $this->assertNull($seller->fresh()->listings_frozen_at);
    }

    public function test_the_super_admin_can_decide_a_notice_in_any_municipality(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $seller = $this->makeSeller(
            ['municipality_id' => $lguAdmin->municipality_id],
            ['municipality_id' => $lguAdmin->municipality_id]
        );
        $notice = SellerReputation::raiseLowRatingNotice($seller, 2.5, 4);

        Sanctum::actingAs($seller->user);
        $this->postJson("/api/seller/notices/{$notice->id}/respond", [
            'response' => 'We have already refunded the affected buyers in full.',
        ])->assertOk();

        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
        $this->getJson('/api/super-admin/seller-notices')->assertOk()->assertJsonCount(1);
        $this->patchJson("/api/super-admin/seller-notices/{$notice->id}/accept", [])
            ->assertOk()->assertJsonPath('status', 'accepted');

        $this->assertNull($seller->fresh()->listings_frozen_at);

        // The seller is told the Super Admin decided, not "your LGU".
        $this->assertDatabaseHas('notifications', ['user_id' => $seller->user_id, 'type' => 'seller_notice_accepted']);
        $this->assertStringStartsWith('The Super Admin accepted your explanation.',
            AppNotification::where('user_id', $seller->user_id)->where('type', 'seller_notice_accepted')->value('body'));

        Sanctum::actingAs($seller->user);
        $this->getJson('/api/seller/notices')->assertOk()
            ->assertJsonPath('0.reviewed_by_label', 'The Super Admin')
            ->assertJsonMissingPath('0.reviewer');

        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());

        // A decided notice cannot be decided twice.
        $this->patchJson("/api/super-admin/seller-notices/{$notice->id}/reject", ['reason' => 'Changed my mind about this.'])
            ->assertStatus(422);
    }

    /**
     * Species used to be free text, so "tilapia" never matched the Tilapia
     * filter. It is now stored the way the marketplace lists it, and a
     * variety (GIFT) narrows within the species instead of replacing it.
     */
    public function test_species_is_normalized_and_variety_filters_within_it(): void
    {
        $seller = $this->makeSeller();
        $this->makeListing($seller, ['species' => 'Bangus']);

        Sanctum::actingAs($seller->user);
        $gift = $this->postListing([
            'species' => '  tilapia ', 'variety' => ' GIFT ', 'title' => 'GIFT Tilapia Fingerlings',
            'quantity' => 100, 'price_per_piece' => 3,
        ])->assertCreated()->assertJsonPath('species', 'Tilapia')->assertJsonPath('variety', 'GIFT')->json('id');
        $plain = $this->postListing([
            'species' => 'TILAPIA', 'variety' => '', 'title' => 'Tilapia Fingerlings',
            'quantity' => 100, 'price_per_piece' => 2,
        ])->assertCreated()->assertJsonPath('species', 'Tilapia')->assertJsonPath('variety', null)->json('id');

        // Editing keeps the spelling consistent too.
        $this->patchJson("/api/listings/{$plain}", ['species' => 'tilapia'])->assertOk()->assertJsonPath('species', 'Tilapia');

        // The Tilapia filter finds both, whatever case the buyer sends; the
        // variety narrows to the GIFT one; search finds it by variety.
        $ids = fn ($query) => collect($this->getJson("/api/listings?{$query}")->assertOk()->json())->pluck('id')->sort()->values()->all();
        $this->assertSame(collect([$gift, $plain])->sort()->values()->all(), $ids('species=tilapia'));
        $this->assertSame([$gift], $ids('species=Tilapia&variety=GIFT'));
        $this->assertSame([$gift], $ids('search=gift'));

        // A species outside the list is tidied, never rejected.
        $this->assertSame('Sea Bass', FingerlingListing::normalizeSpecies('sea  bass'));
        $this->assertSame('Grouper', FingerlingListing::normalizeSpecies('grouper'));
    }

    public function test_an_explanation_cannot_be_accepted_before_the_seller_has_given_one(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $seller = $this->makeSeller(
            ['municipality_id' => $lguAdmin->municipality_id],
            ['municipality_id' => $lguAdmin->municipality_id]
        );
        $notice = SellerReputation::raiseLowRatingNotice($seller, 2.5, 4);

        Sanctum::actingAs($lguAdmin);
        $this->patchJson("/api/lgu/seller-notices/{$notice->id}/accept", [])->assertStatus(422);

        // ...but silence IS a valid reason to reject, or ignoring the notice
        // would be the safest thing a seller could do.
        $this->patchJson("/api/lgu/seller-notices/{$notice->id}/reject", [
            'reason' => 'The seller did not respond within the review period.',
        ])->assertOk()->assertJsonPath('status', 'rejected');
    }

    // ---------------------------------------------------------------------
    // Unit of Measurement + Minimum Order (FingerlingListing::UNIT_TYPES /
    // ::quantityIssue).
    // ---------------------------------------------------------------------

    public function test_listings_default_to_per_piece_with_no_minimum(): void
    {
        $seller = $this->makeSeller();
        // makeListing sends no unit fields at all, exactly like a listing
        // created before this feature existed.
        $listing = $this->makeListing($seller, ['approval_status' => 'approved']);

        $this->assertSame('piece', $listing->fresh()->unit_type);
        $this->assertSame(1, $listing->fresh()->minimumOrder());

        $buyer = $this->makeBuyer();
        Sanctum::actingAs($buyer);
        $this->postJson('/api/orders', ['fingerling_listing_id' => $listing->id, 'quantity' => 1])->assertCreated();
    }

    public function test_a_buyer_cannot_order_below_the_sellers_minimum(): void
    {
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller, [
            'approval_status' => 'approved',
            'quantity' => 1000,
            'unit_type' => 'piece',
            'minimum_order' => 50,
        ]);
        $buyer = $this->makeBuyer();

        Sanctum::actingAs($buyer);

        // Below the minimum -- refused, and the message names the minimum.
        $response = $this->postJson('/api/orders', ['fingerling_listing_id' => $listing->id, 'quantity' => 49])
            ->assertStatus(422);
        $this->assertStringContainsString('minimum order of 50 pcs', $response->json('message'));

        // Above available stock -- still refused.
        $this->postJson('/api/orders', ['fingerling_listing_id' => $listing->id, 'quantity' => 1001])->assertStatus(422);

        // Exactly the minimum is accepted.
        $this->postJson('/api/orders', ['fingerling_listing_id' => $listing->id, 'quantity' => 50])->assertCreated();
    }

    public function test_the_cart_enforces_the_same_minimum_as_ordering(): void
    {
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller, [
            'approval_status' => 'approved',
            'quantity' => 500,
            'unit_type' => 'bulk',
            'minimum_order' => 5,
        ]);
        $buyer = $this->makeBuyer();

        Sanctum::actingAs($buyer);
        $this->postJson('/api/cart', ['fingerling_listing_id' => $listing->id, 'quantity' => 4])->assertStatus(422);

        $item = $this->postJson('/api/cart', ['fingerling_listing_id' => $listing->id, 'quantity' => 5])
            ->assertCreated()
            ->json();
        $this->assertSame(5, $item['listing']['minimum_order']);
        $this->assertSame('bulk', $item['listing']['unit_label_plural']);

        // Editing a saved line back below the minimum is refused too.
        $this->patchJson("/api/cart/{$item['id']}", ['quantity' => 2])->assertStatus(422);

        // A seller raising their minimum after the fact flags the saved line
        // rather than letting it check out.
        $listing->update(['minimum_order' => 20]);
        $cart = $this->getJson('/api/cart')->assertOk()->json();
        $this->assertFalse($cart['items'][0]['available']);
        $this->assertStringContainsString('minimum order of 20 bulk', $cart['items'][0]['issue']);
    }

    // ---------------------------------------------------------------------
    // Buyer Turnout / ROI analytics -- App\Support\BuyerInvestmentReport.
    // ---------------------------------------------------------------------

    public function test_buyer_analytics_reports_investment_turnout_and_a_harvest_projection(): void
    {
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller, ['approval_status' => 'approved', 'price_per_piece' => 5, 'quantity' => 10000]);
        $buyer = $this->makeBuyer();

        // 1,000 fingerlings at P5 = P5,000 invested across two completed
        // orders, plus one still in progress and one cancelled.
        $this->makeOrder($buyer, $listing, ['status' => 'completed', 'quantity' => 600, 'unit_price' => 5, 'total_amount' => 3000]);
        $this->makeOrder($buyer, $listing, ['status' => 'completed', 'quantity' => 400, 'unit_price' => 5, 'total_amount' => 2000]);
        $this->makeOrder($buyer, $listing, ['status' => 'paid', 'quantity' => 100, 'unit_price' => 5, 'total_amount' => 500]);
        $this->makeOrder($buyer, $listing, ['status' => 'cancelled', 'quantity' => 50, 'unit_price' => 5, 'total_amount' => 250]);

        Sanctum::actingAs($buyer);
        $report = $this->getJson('/api/buyer/analytics?period=yearly')->assertOk()->json('investment');

        // Recorded fact -- money in.
        $this->assertEquals(5000, $report['investment']['total_invested']);
        $this->assertEquals(500, $report['investment']['committed_investment']);
        $this->assertEquals(5500, $report['investment']['total_exposure']);
        $this->assertEquals(2500, $report['investment']['average_order_value']);

        // Recorded fact -- turnout.
        $this->assertSame(4, $report['turnout']['total_orders']);
        $this->assertSame(2, $report['turnout']['completed_orders']);
        $this->assertSame(1, $report['turnout']['active_orders']);
        $this->assertSame(1, $report['turnout']['cancelled_orders']);
        $this->assertEquals(50.0, $report['turnout']['completion_rate']);
        $this->assertSame(1, $report['turnout']['sellers_engaged']);

        // Estimate -- 1,000 pcs at the default 80% survival and P25/fish.
        $this->assertSame(1000, $report['projection']['pieces_purchased']);
        $this->assertSame(800, $report['projection']['projected_survivors']);
        $this->assertSame(200, $report['projection']['projected_losses']);
        $this->assertEquals(20000, $report['projection']['projected_revenue']);
        $this->assertEquals(15000, $report['projection']['projected_return']);
        $this->assertEquals(300.0, $report['projection']['projected_roi_percent']);
        $this->assertEquals(5, $report['projection']['cost_per_piece']);
        $this->assertEquals(6.25, $report['projection']['break_even_value_per_piece']);

        $this->assertCount(2, $report['purchase_history']);
    }

    public function test_a_farmer_can_drive_the_projection_with_their_own_assumptions(): void
    {
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller, ['approval_status' => 'approved', 'price_per_piece' => 5, 'quantity' => 10000]);
        $buyer = $this->makeBuyer();
        $this->makeOrder($buyer, $listing, ['status' => 'completed', 'quantity' => 1000, 'unit_price' => 5, 'total_amount' => 5000]);

        Sanctum::actingAs($buyer);
        $report = $this->getJson('/api/buyer/analytics?period=yearly&survival_rate=0.5&harvest_value_per_piece=40')
            ->assertOk()
            ->json('investment');

        $this->assertEquals(0.5, $report['assumptions']['survival_rate']);
        $this->assertEquals(40, $report['assumptions']['harvest_value_per_piece']);
        $this->assertSame(500, $report['projection']['projected_survivors']);
        $this->assertEquals(20000, $report['projection']['projected_revenue']);
        $this->assertEquals(15000, $report['projection']['projected_return']);
        // Defaults are still reported so the UI can offer a reset.
        $this->assertEquals(0.8, $report['assumptions']['defaults']['survival_rate']);

        // Nonsense assumptions are clamped rather than producing absurd maths.
        $clamped = $this->getJson('/api/buyer/analytics?period=yearly&survival_rate=9&harvest_value_per_piece=-100')
            ->assertOk()
            ->json('investment.assumptions');
        $this->assertEquals(1.0, $clamped['survival_rate']);
        $this->assertEquals(0.0, $clamped['harvest_value_per_piece']);
    }

    /**
     * Buyers commit money before they ever see the fingerlings, so a listing
     * without a picture is not something we want on the marketplace at all.
     */
    /**
     * A listing has no unit of measurement. Stock, minimum order and price are
     * all counted in single fingerlings; "bulk" is only a convenience the BUYER
     * may pick at order time, and the API only ever receives a plain quantity.
     */
    /**
     * Listings no longer wait in an approval queue. The check moved upstream:
     * the LGU vets the SELLER once, and after that their posts go straight to
     * the marketplace. Listing Management stays for monitoring after the fact.
     */
    public function test_a_verified_sellers_listing_goes_live_immediately(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $seller = $this->makeSeller(
            ['municipality_id' => $lguAdmin->municipality_id],
            ['municipality_id' => $lguAdmin->municipality_id]
        );
        $this->assertTrue((bool) $seller->verified);

        Sanctum::actingAs($seller->user);
        $listing = $this->postListing([
            'species' => 'Bangus',
            'title' => 'Bangus Fingerlings',
            'quantity' => 500,
            'price_per_piece' => 3.5,
        ])->assertCreated()->json();

        $this->assertSame('approved', $listing['approval_status']);

        // On the public marketplace straight away, with no LGU action at all.
        $this->assertTrue(collect($this->getJson('/api/listings')->assertOk()->json())->contains('id', $listing['id']));
        $this->getJson("/api/listings/{$listing['id']}")->assertOk();
    }

    public function test_an_unverified_seller_cannot_post_anything(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $seller = $this->makePendingSeller($lguAdmin);

        Sanctum::actingAs($seller->user);
        $this->postListing([
            'species' => 'Bangus',
            'title' => 'Bangus Fingerlings',
            'quantity' => 500,
            'price_per_piece' => 3.5,
        ])->assertStatus(403);

        // Verification is now the only gate in front of the marketplace, so it
        // is checked on the profile flag itself: an account whose verification
        // is withdrawn must stop being able to post, even though its
        // registration decision still reads 'approved'.
        $seller->forceFill(['approval_status' => 'approved', 'verified' => false])->save();
        Sanctum::actingAs($seller->user->fresh());
        $this->postListing([
            'species' => 'Bangus',
            'title' => 'Bangus Fingerlings',
            'quantity' => 500,
            'price_per_piece' => 3.5,
        ])->assertStatus(403);

        // Verified -- now they can.
        $seller->forceFill(['verified' => true, 'status' => 'verified'])->save();
        Sanctum::actingAs($seller->user->fresh());
        $this->postListing([
            'species' => 'Bangus',
            'title' => 'Bangus Fingerlings',
            'quantity' => 500,
            'price_per_piece' => 3.5,
        ])->assertCreated()->assertJsonPath('approval_status', 'approved');
    }

    public function test_the_lgu_can_still_take_down_a_live_listing(): void
    {
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $seller = $this->makeSeller(
            ['municipality_id' => $lguAdmin->municipality_id],
            ['municipality_id' => $lguAdmin->municipality_id]
        );
        $listing = $this->makeListing($seller, ['approval_status' => 'approved']);

        // Monitoring after the fact still works -- removing the queue did not
        // remove the LGU's ability to act.
        Sanctum::actingAs($lguAdmin);
        $this->patchJson("/api/lgu/listings/{$listing->id}/reject", ['reason' => 'Photos do not match the species listed.'])
            ->assertOk();

        $this->assertFalse(collect($this->getJson('/api/listings')->assertOk()->json())->contains('id', $listing->id));
    }

    public function test_every_listing_must_state_its_bulk_size(): void
    {
        $seller = $this->makeSeller();
        Sanctum::actingAs($seller->user);

        $this->post('/api/listings', [
            'species' => 'Bangus',
            'title' => 'Bangus Fingerlings',
            'quantity' => 500,
            'price_per_piece' => 3.5,
            'photos' => [UploadedFile::fake()->image('fingerlings.jpg')->size(300)],
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pieces_per_unit');

        $listing = $this->postListing([
            'species' => 'Bangus',
            'title' => 'Bangus Fingerlings',
            'quantity' => 500,
            'price_per_piece' => 3.5,
            'minimum_order' => 25,
            'pieces_per_unit' => 10,
        ])->assertCreated()->json();

        $this->assertSame(10, $listing['pieces_per_unit']);
        $this->assertSame(25, $listing['minimum_order']);
        $this->assertSame('1 bulk = 10 quantity', $listing['unit_contents_label']);
        // 500 fingerlings is 50 whole bulks.
        $this->assertSame(50, $listing['available_bulks']);
    }

    /**
     * Whether the buyer thought in fingerlings or in bulks, the order is a
     * plain quantity by the time it reaches the API, and stock moves one-for-one
     * with it. The conversion is a front-end convenience, not a second unit.
     */
    public function test_stock_moves_one_for_one_with_the_ordered_quantity(): void
    {
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller, [
            'quantity' => 1000,
            'price_per_piece' => 10,
            'pieces_per_unit' => 10,
            'minimum_order' => 1,
        ]);
        $buyer = $this->makeBuyer();
        Sanctum::actingAs($buyer);

        // A buyer who chose "5 bulks" sends 50 -- the UI multiplied for them.
        $order = $this->postJson('/api/orders', [
            'fingerling_listing_id' => $listing->id,
            'quantity' => 50,
        ])->assertCreated()->json();

        $this->assertSame(50, (int) $order['quantity']);
        // Priced per fingerling: 50 x 10.
        $this->assertEquals(500, (float) $order['total_amount']);
        $this->assertSame(950, (int) $listing->fresh()->quantity);
        $this->assertSame(95, $listing->fresh()->availableBulks());

        // Cancelling returns exactly what was taken.
        $seller->user->refresh();
        Sanctum::actingAs($seller->user);
        $this->patchJson("/api/orders/{$order['id']}/status", ['status' => 'cancelled', 'cancellation_reason' => 'Stock died before dispatch.'])->assertOk();
        $this->assertSame(1000, (int) $listing->fresh()->quantity);
    }

    public function test_the_stock_ceiling_is_the_plain_quantity(): void
    {
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller, ['quantity' => 45, 'pieces_per_unit' => 10, 'minimum_order' => 1]);
        $buyer = $this->makeBuyer();
        Sanctum::actingAs($buyer);

        // 45 fingerlings is only 4 whole bulks, but the ceiling itself is 45.
        $this->assertSame(4, $listing->availableBulks());

        $this->postJson('/api/orders', ['fingerling_listing_id' => $listing->id, 'quantity' => 50])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Requested quantity exceeds available stock.');

        $this->postJson('/api/orders', ['fingerling_listing_id' => $listing->id, 'quantity' => 45])
            ->assertCreated();

        $this->assertSame(0, (int) $listing->fresh()->quantity);
    }

    public function test_the_cart_enforces_the_same_plain_quantity_ceiling(): void
    {
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller, ['quantity' => 30, 'pieces_per_unit' => 10, 'minimum_order' => 1]);
        $buyer = $this->makeBuyer();
        Sanctum::actingAs($buyer);

        $this->postJson('/api/cart', ['fingerling_listing_id' => $listing->id, 'quantity' => 40])
            ->assertStatus(422);

        $this->postJson('/api/cart', ['fingerling_listing_id' => $listing->id, 'quantity' => 30])
            ->assertCreated();
    }

    /**
     * With units gone, every purchase is already a count of fingerlings, so the
     * Turnout/ROI projection can cover all of them. Nothing is excluded any
     * more except a listing that predates the bulk-size field.
     */
    public function test_the_projection_counts_every_purchase_now_that_all_stock_is_counted(): void
    {
        $seller = $this->makeSeller();
        $listingA = $this->makeListing($seller, ['price_per_piece' => 5, 'quantity' => 10000]);
        $listingB = $this->makeListing($seller, ['price_per_piece' => 10, 'quantity' => 10000]);
        $buyer = $this->makeBuyer();

        $this->makeOrder($buyer, $listingA, ['status' => 'completed', 'quantity' => 1000, 'unit_price' => 5, 'total_amount' => 5000]);
        $this->makeOrder($buyer, $listingB, ['status' => 'completed', 'quantity' => 30, 'unit_price' => 10, 'total_amount' => 300]);

        Sanctum::actingAs($buyer);
        $report = $this->getJson('/api/buyer/analytics?period=yearly')->assertOk()->json('investment');

        $this->assertSame(1030, $report['projection']['pieces_purchased']);
        $this->assertEquals(5300, $report['projection']['invested_in_pieces']);
        $this->assertSame(0, $report['projection']['excluded_orders']);
    }

    public function test_a_listing_cannot_be_created_without_a_photo(): void
    {
        $seller = $this->makeSeller();
        Sanctum::actingAs($seller->user);

        $this->postJson('/api/listings', [
            'species' => 'Bangus',
            'title' => 'Bangus Fingerlings',
            'quantity' => 500,
            'price_per_piece' => 3.5,
        ])->assertStatus(422)->assertJsonValidationErrors('photos');

        $this->assertSame(0, FingerlingListing::where('title', 'Bangus Fingerlings')->count());

        // With a photo it is created, and the photo is attached in the same request.
        $listing = $this->postListing([
            'species' => 'Bangus',
            'title' => 'Bangus Fingerlings',
            'quantity' => 500,
            'price_per_piece' => 3.5,
        ])->assertCreated()->json();

        $this->assertCount(1, $listing['media']);
        $this->assertSame('photo', $listing['media'][0]['type']);
    }

    public function test_a_listing_cannot_have_its_last_photo_removed(): void
    {
        $seller = $this->makeSeller();
        Sanctum::actingAs($seller->user);

        $listing = $this->postListing([
            'species' => 'Bangus',
            'title' => 'Bangus Fingerlings',
            'quantity' => 500,
            'price_per_piece' => 3.5,
        ])->assertCreated()->json();
        $firstPhoto = $listing['media'][0]['id'];

        // Otherwise the rule is undone by a longer route: post with a photo,
        // then delete it.
        $this->deleteJson("/api/listings/{$listing['id']}/media/{$firstPhoto}")->assertStatus(422);
        $this->assertSame(1, ListingMedia::where('listing_id', $listing['id'])->count());

        // Adding a replacement first is the supported way to swap it.
        $this->post("/api/listings/{$listing['id']}/media", [
            'photos' => [UploadedFile::fake()->image('replacement.jpg')->size(300)],
        ], ['Accept' => 'application/json'])->assertOk();

        $this->deleteJson("/api/listings/{$listing['id']}/media/{$firstPhoto}")->assertOk();
        $this->assertSame(1, ListingMedia::where('listing_id', $listing['id'])->count());
    }

    public function test_the_projection_counts_bulk_purchases_using_the_sellers_fish_per_bulk(): void
    {
        $seller = $this->makeSeller();
        $pieces = $this->makeListing($seller, ['price_per_piece' => 5, 'quantity' => 10000]);
        $byBulk = $this->makeListing($seller, [
            'price_per_piece' => 850,
            'quantity' => 500,
            'unit_type' => 'bulk',
            'pieces_per_unit' => 10,
        ]);
        $buyer = $this->makeBuyer();

        $this->makeOrder($buyer, $pieces, ['status' => 'completed', 'quantity' => 1000, 'unit_price' => 5, 'total_amount' => 5000]);
        // 3 bulk x 10 fish = 30 fish, for 2,550 pesos.
        $this->makeOrder($buyer, $byBulk, ['status' => 'completed', 'quantity' => 3, 'unit_price' => 850, 'total_amount' => 2550]);

        Sanctum::actingAs($buyer);
        $report = $this->getJson('/api/buyer/analytics?period=yearly')->assertOk()->json('investment');

        // Both purchases now feed the per-fish projection, and nothing is
        // excluded, because both can be resolved to a count of fish.
        $this->assertSame(1030, $report['projection']['pieces_purchased']);
        $this->assertEquals(7550, $report['projection']['invested_in_pieces']);
        $this->assertSame(0, $report['projection']['excluded_orders']);

        // The unit breakdown still keeps bulk and pieces apart.
        $this->assertCount(2, $report['units']);
    }

    public function test_a_bulk_listing_with_no_stated_count_stays_out_of_the_projection(): void
    {
        $seller = $this->makeSeller();
        $pieces = $this->makeListing($seller, ['price_per_piece' => 5, 'quantity' => 10000]);
        // A listing created before pieces_per_unit existed: null, not zero.
        $legacyBulk = $this->makeListing($seller, [
            'price_per_piece' => 850,
            'quantity' => 500,
            'unit_type' => 'bulk',
            'pieces_per_unit' => null,
        ]);
        $buyer = $this->makeBuyer();

        $this->makeOrder($buyer, $pieces, ['status' => 'completed', 'quantity' => 1000, 'unit_price' => 5, 'total_amount' => 5000]);
        $this->makeOrder($buyer, $legacyBulk, ['status' => 'completed', 'quantity' => 3, 'unit_price' => 850, 'total_amount' => 2550]);

        Sanctum::actingAs($buyer);
        $report = $this->getJson('/api/buyer/analytics?period=yearly')->assertOk()->json('investment');

        // The money is still recorded as invested...
        $this->assertEquals(7550, $report['investment']['total_invested']);

        // ...but the projection will not invent a fish count for it.
        $this->assertSame(1000, $report['projection']['pieces_purchased']);
        $this->assertEquals(5000, $report['projection']['invested_in_pieces']);
        $this->assertSame(1, $report['projection']['excluded_orders']);
    }

    /**
     * The Sellers directories show a star rating, and an unrated seller must
     * read as "no ratings yet" rather than zero stars -- seller_profiles.rating
     * is 0.00 until someone reviews them (see App\Support\SellerReputation),
     * so the average alone cannot tell those two apart. Both dashboards need
     * the count for that, and dropping it would silently blank the stars.
     */
    public function test_the_lgu_and_super_admin_seller_directories_expose_a_review_count(): void
    {
        $lgu = $this->makeLguAdmin();
        $rated = $this->makeSeller(['municipality_id' => $lgu->municipality_id]);
        $unrated = $this->makeSeller(['municipality_id' => $lgu->municipality_id]);
        $buyer = $this->makeBuyer();

        $order = $this->makeOrder($buyer, $this->makeListing($rated), ['status' => 'completed']);
        Review::create([
            'order_id' => $order->id,
            'buyer_id' => $buyer->id,
            'seller_profile_id' => $rated->id,
            'rating' => 4,
        ]);

        foreach ([[$lgu, '/api/lgu/sellers'], [User::where('role', 'super_admin')->firstOrFail(), '/api/super-admin/sellers']] as [$actor, $url]) {
            Sanctum::actingAs($actor);
            $rows = collect($this->getJson($url)->assertOk()->json())->keyBy('id');

            $this->assertSame(1, $rows[$rated->id]['reviews_count'], $url);
            $this->assertSame(0, $rows[$unrated->id]['reviews_count'], $url);
        }
    }

    public function test_every_message_carries_a_timestamp_for_sender_and_receiver(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();

        Sanctum::actingAs($buyer);
        $this->postJson('/api/messages', ['receiver_id' => $seller->user_id, 'body' => 'Do you have Bangus in stock?'])->assertCreated();

        Sanctum::actingAs($seller->user);
        $this->postJson('/api/messages', ['receiver_id' => $buyer->id, 'body' => 'Yes, 5,000 pcs ready.'])->assertCreated();

        // The receiver sees a timestamp on every message in the thread...
        Sanctum::actingAs($buyer);
        $thread = $this->getJson("/api/messages/thread/{$seller->user_id}")->assertOk()->json();
        $this->assertCount(2, $thread['messages']);
        foreach ($thread['messages'] as $message) {
            $this->assertNotEmpty($message['created_at']);
        }

        // ...and so does the sender, reading the same conversation.
        Sanctum::actingAs($seller->user);
        $sellerView = $this->getJson("/api/messages/thread/{$buyer->id}")->assertOk()->json();
        $this->assertSame(
            collect($thread['messages'])->pluck('created_at')->all(),
            collect($sellerView['messages'])->pluck('created_at')->all()
        );

        // The thread list carries the last message's timestamp too.
        $this->getJson('/api/messages/threads')->assertOk()->assertJsonStructure([['user', 'last_message' => ['created_at'], 'unread_count']]);
    }

    public function test_a_seller_counterpart_carries_their_hatchery_profile_id_for_view_profile(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();

        Sanctum::actingAs($buyer);
        $this->postJson('/api/messages', ['receiver_id' => $seller->user_id, 'body' => 'Are these Bangus ready?'])->assertCreated();

        // The buyer's view of a seller carries seller_profile_id, which is what
        // the "View Profile" link needs -- the hatchery page is addressed by
        // seller_profiles.id, not users.id.
        $this->getJson("/api/messages/thread/{$seller->user_id}")
            ->assertOk()
            ->assertJsonPath('user.role', 'seller')
            ->assertJsonPath('user.seller_profile_id', $seller->id);

        $this->getJson('/api/messages/threads')
            ->assertOk()
            ->assertJsonPath('0.user.seller_profile_id', $seller->id);

        // The seller's view of a buyer has no such id -- a buyer's profile page
        // is keyed by users.id and needs nothing extra.
        Sanctum::actingAs($seller->user);
        $sellerView = $this->getJson("/api/messages/thread/{$buyer->id}")->assertOk()->json('user');
        $this->assertSame('buyer', $sellerView['role']);
        $this->assertArrayNotHasKey('seller_profile_id', $sellerView);
    }

    // ---------------------------------------------------------------------
    // Help & Support tickets (App\Support\SupportTickets).
    // ---------------------------------------------------------------------

    /** The contact form fields every ticket needs, plus the topic. */
    private function ticketForm(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'contact_email' => 'juan.contact@example.com',
            'category' => 'order_delivery',
            'subject' => 'Question about my order',
            'body' => 'I have a question about my recent order.',
        ], $overrides);
    }

    public function test_a_buyer_sends_a_ticket_from_the_contact_form_and_both_the_lgu_and_super_admin_hear_about_it(): void
    {
        Mail::fake();
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $buyer = $this->makeBuyer(['municipality_id' => $lguAdmin->municipality_id]);
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();

        // Logged in only.
        $this->postJson('/api/support/tickets', $this->ticketForm())->assertUnauthorized();

        Sanctum::actingAs($buyer);
        $categories = collect($this->getJson('/api/support/categories')->assertOk()->json('categories'))->pluck('value');
        $this->assertTrue($categories->contains('payment_refund'));
        $this->assertTrue($categories->contains('feedback'));
        // Seller-only categories are not offered to a buyer, and are refused.
        $this->assertFalse($categories->contains('wallet_withdrawal'));
        $this->postJson('/api/support/tickets', $this->ticketForm(['category' => 'wallet_withdrawal']))->assertStatus(422);

        // Name and contact email are required.
        $this->postJson('/api/support/tickets', $this->ticketForm(['first_name' => '', 'contact_email' => 'not-an-email']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['first_name', 'contact_email']);

        $ticket = $this->postJson('/api/support/tickets', $this->ticketForm([
            'category' => 'order_delivery',
            'subject' => 'Delivery is late',
            'body' => 'The seller said it would arrive Monday and it has not.',
        ]))
            ->assertCreated()
            ->assertJsonPath('status', 'open')
            ->assertJsonPath('first_name', 'Juan')
            ->assertJsonPath('last_name', 'Dela Cruz')
            ->assertJsonMissingPath('mobile_number')
            ->assertJsonPath('contact_email', 'juan.contact@example.com')
            ->assertJsonCount(1, 'messages')
            ->json();

        $this->assertSame(sprintf('SUP-%06d', $ticket['id']), $ticket['ticket_number']);
        // The sender gets a "we received it" email at the address they gave.
        Mail::assertSent(SupportTicketUpdatedMail::class, fn ($mail) => $mail->hasTo('juan.contact@example.com') && $mail->kind === 'received');
        // Shared: the buyer's LGU and the Super Admin are both told, whatever the topic.
        $this->assertDatabaseHas('notifications', ['user_id' => $superAdmin->id, 'type' => 'support_ticket_opened']);
        $this->assertDatabaseHas('notifications', ['user_id' => $lguAdmin->id, 'type' => 'support_ticket_opened']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'support_ticket_opened', 'reference_number' => $ticket['ticket_number']]);
        $this->getJson('/api/support/tickets')->assertOk()->assertJsonCount(1);

        // Another buyer cannot open it.
        Sanctum::actingAs($this->makeBuyer());
        $this->getJson("/api/support/tickets/{$ticket['id']}")->assertStatus(403);
    }

    public function test_feedback_is_sent_as_a_ticket(): void
    {
        Mail::fake();
        $seller = $this->makeSeller();

        Sanctum::actingAs($seller->user);
        $this->postJson('/api/support/tickets', $this->ticketForm([
            'category' => 'feedback',
            'subject' => 'Love the new dashboard',
            'body' => 'The new seller dashboard is much easier to use. Thank you!',
            'last_name' => null,
        ]))->assertCreated()->assertJsonPath('category', 'feedback')->assertJsonPath('last_name', null);
    }

    public function test_a_ticket_is_shared_by_the_order_sellers_lgu_and_the_super_admin(): void
    {
        Mail::fake();
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $otherMunicipality = Municipality::where('id', '!=', $lguAdmin->municipality_id)->firstOrFail();
        $outsideLgu = $this->makeLguAdmin(['municipality_id' => $otherMunicipality->id]);
        $seller = $this->makeSeller(
            ['municipality_id' => $lguAdmin->municipality_id],
            ['municipality_id' => $lguAdmin->municipality_id]
        );
        // The buyer lives elsewhere: the order's seller decides the municipality.
        $buyer = $this->makeBuyer(['municipality_id' => $otherMunicipality->id]);
        $order = $this->makeOrder($buyer, $this->makeListing($seller));

        // Someone else's order cannot be attached.
        Sanctum::actingAs($this->makeBuyer());
        $this->postJson('/api/support/tickets', $this->ticketForm(['order_number' => $order->order_number]))
            ->assertStatus(422)->assertJsonValidationErrors('order_number');

        Sanctum::actingAs($buyer);
        $ticketId = $this->postJson('/api/support/tickets', $this->ticketForm([
            'subject' => 'Delivery is late',
            'body' => 'The seller said it would arrive Monday and it has not.',
            'order_number' => $order->order_number,
        ]))
            ->assertCreated()
            ->assertJsonPath('municipality_id', $lguAdmin->municipality_id)
            ->assertJsonPath('order.order_number', $order->order_number)
            ->json('id');

        $this->assertDatabaseHas('notifications', ['user_id' => $lguAdmin->id, 'type' => 'support_ticket_opened']);

        Sanctum::actingAs($outsideLgu);
        $this->assertFalse(collect($this->getJson('/api/lgu/support-tickets')->assertOk()->json())->pluck('id')->contains($ticketId));
        $this->getJson("/api/support/tickets/{$ticketId}")->assertStatus(403);
        $this->postJson("/api/lgu/support-tickets/{$ticketId}/responses", ['body' => 'Not mine to answer.'])->assertStatus(403);
        $this->patchJson("/api/lgu/support-tickets/{$ticketId}/resolve")->assertStatus(403);

        Sanctum::actingAs($lguAdmin);
        $this->assertTrue(collect($this->getJson('/api/lgu/support-tickets')->assertOk()->json())->pluck('id')->contains($ticketId));

        // The Super Admin sees it too, and either of them may answer.
        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
        $this->assertTrue(collect($this->getJson('/api/super-admin/support-tickets')->assertOk()->json())->pluck('id')->contains($ticketId));
        $this->postJson("/api/super-admin/support-tickets/{$ticketId}/responses", ['body' => 'Checking with the seller now.'])
            ->assertCreated()
            ->assertJsonPath('status', 'answered');
    }

    public function test_staff_responses_are_emailed_and_internal_notes_stay_hidden_from_the_user(): void
    {
        Mail::fake();
        Storage::fake('public');
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $seller = $this->makeSeller(
            ['municipality_id' => $lguAdmin->municipality_id],
            ['municipality_id' => $lguAdmin->municipality_id]
        );

        Sanctum::actingAs($seller->user);
        $ticket = $this->post('/api/support/tickets', $this->ticketForm([
            'category' => 'listing',
            'subject' => 'Cannot upload listing photos',
            'body' => 'Every photo I upload fails with an error.',
            'contact_email' => 'seller.reply@example.com',
            'attachment' => UploadedFile::fake()->image('error.png')->size(200),
        ]), ['Accept' => 'application/json'])->assertCreated()->json();
        $this->assertNotNull($ticket['messages'][0]['attachment_url']);

        // The user cannot use the staff response route.
        $this->postJson("/api/lgu/support-tickets/{$ticket['id']}/responses", ['body' => 'Any update?'])->assertStatus(403);

        Sanctum::actingAs($lguAdmin);
        // An internal note: no email, no notification, status unchanged.
        $this->postJson("/api/lgu/support-tickets/{$ticket['id']}/responses", ['body' => 'Probably the 10 MB limit.', 'internal' => true])
            ->assertCreated()
            ->assertJsonPath('status', 'open')
            ->assertJsonPath('messages.1.is_internal', true);
        Mail::assertNotSent(SupportTicketUpdatedMail::class, fn ($mail) => $mail->kind === 'reply');
        $this->assertDatabaseMissing('notifications', ['user_id' => $seller->user_id, 'type' => 'support_ticket_reply']);

        $this->postJson("/api/lgu/support-tickets/{$ticket['id']}/responses", ['body' => 'Try a JPG under 10 MB.'])
            ->assertCreated()
            ->assertJsonPath('status', 'answered')
            ->assertJsonCount(3, 'messages');
        $this->assertDatabaseHas('notifications', ['user_id' => $seller->user_id, 'type' => 'support_ticket_reply']);
        // Emailed to the address on the ticket, not necessarily the account email.
        Mail::assertSent(SupportTicketUpdatedMail::class, fn ($mail) => $mail->hasTo('seller.reply@example.com') && $mail->kind === 'reply');

        // The user sees the response, but never the internal note -- in the
        // ticket or in the message count on My Tickets.
        Sanctum::actingAs($seller->user);
        $mine = $this->getJson("/api/support/tickets/{$ticket['id']}")->assertOk()->assertJsonCount(2, 'messages')->json();
        $this->assertSame(['Every photo I upload fails with an error.', 'Try a JPG under 10 MB.'], collect($mine['messages'])->pluck('body')->all());
        $this->getJson('/api/support/tickets')->assertOk()->assertJsonPath('0.messages_count', 2);

        Sanctum::actingAs($lguAdmin);
        $this->patchJson("/api/lgu/support-tickets/{$ticket['id']}/resolve", ['note' => 'Fixed on our side.'])
            ->assertOk()
            ->assertJsonPath('status', 'resolved');
        Mail::assertSent(SupportTicketUpdatedMail::class, fn ($mail) => $mail->kind === 'closed');
        $this->patchJson("/api/lgu/support-tickets/{$ticket['id']}/resolve")->assertStatus(422);
        // A closed ticket takes no more staff responses or internal notes.
        $this->postJson("/api/lgu/support-tickets/{$ticket['id']}/responses", ['body' => 'One more thing.'])->assertStatus(422);
        $this->postJson("/api/lgu/support-tickets/{$ticket['id']}/responses", ['body' => 'Note.', 'internal' => true])->assertStatus(422);
        $this->assertDatabaseHas('activity_logs', ['action' => 'support_ticket_resolved', 'reference_number' => $ticket['ticket_number']]);
    }

    public function test_the_ticket_owner_can_reply_to_clarify_until_the_ticket_is_closed(): void
    {
        Mail::fake();
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $buyer = $this->makeBuyer(['municipality_id' => $lguAdmin->municipality_id]);

        Sanctum::actingAs($buyer);
        $ticketId = $this->postJson('/api/support/tickets', $this->ticketForm())->assertCreated()->json('id');
        // The owner can only reply once support has messaged them.
        $this->postJson("/api/support/tickets/{$ticketId}/replies", ['body' => 'Any update yet?'])->assertStatus(422);

        Sanctum::actingAs($lguAdmin);
        // An internal note is not a message to the user, so it does not open replies.
        $this->postJson("/api/lgu/support-tickets/{$ticketId}/responses", ['body' => 'Check their orders.', 'internal' => true])->assertCreated();
        Sanctum::actingAs($buyer);
        $this->postJson("/api/support/tickets/{$ticketId}/replies", ['body' => 'Any update yet?'])->assertStatus(422);

        Sanctum::actingAs($lguAdmin);
        $this->postJson("/api/lgu/support-tickets/{$ticketId}/responses", ['body' => 'Which order is this about?'])
            ->assertCreated()
            ->assertJsonPath('status', 'answered');

        // The owner's reply puts it back in the staff queue and tells staff.
        Sanctum::actingAs($buyer);
        $this->postJson("/api/support/tickets/{$ticketId}/replies", ['body' => 'It is my order from last Monday.'])
            ->assertCreated()
            ->assertJsonPath('status', 'open')
            // The internal note stays hidden from the owner.
            ->assertJsonCount(3, 'messages')
            ->assertJsonPath('messages.2.body', 'It is my order from last Monday.');
        $this->assertDatabaseHas('notifications', ['user_id' => $lguAdmin->id, 'type' => 'support_ticket_reply']);
        // Staff are also emailed, so a reply is not missed outside the dashboard.
        Mail::assertSent(\App\Mail\SupportTicketUserRepliedMail::class, fn ($mail) => $mail->hasTo($lguAdmin->email));
        Mail::assertSent(\App\Mail\SupportTicketUserRepliedMail::class, fn ($mail) => $mail->hasTo(User::where('role', 'super_admin')->value('email')));
        // After that they can keep replying without waiting for support.
        $this->postJson("/api/support/tickets/{$ticketId}/replies", ['body' => 'Also, it was 500 tilapia.'])
            ->assertCreated()
            ->assertJsonCount(4, 'messages');
        $this->postJson("/api/support/tickets/{$ticketId}/replies", ['body' => ''])->assertStatus(422);

        // Nobody else can reply on it -- not another buyer, and staff use their own route.
        Sanctum::actingAs($this->makeBuyer());
        $this->postJson("/api/support/tickets/{$ticketId}/replies", ['body' => 'Hello?'])->assertStatus(403);
        Sanctum::actingAs($lguAdmin);
        $this->postJson("/api/support/tickets/{$ticketId}/replies", ['body' => 'Hello?'])->assertStatus(403);

        // A closed ticket takes no more replies.
        $this->patchJson("/api/lgu/support-tickets/{$ticketId}/resolve")->assertOk();
        Sanctum::actingAs($buyer);
        $this->postJson("/api/support/tickets/{$ticketId}/replies", ['body' => 'One more thing.'])->assertStatus(422);
    }

    public function test_super_admin_only_topics_can_be_read_but_not_answered_by_the_lgu(): void
    {
        Mail::fake();
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        $buyer = $this->makeBuyer(['municipality_id' => $lguAdmin->municipality_id]);

        Sanctum::actingAs($buyer);
        $ticket = $this->postJson('/api/support/tickets', $this->ticketForm([
            'category' => 'account',
            'subject' => 'Cannot log in',
            'body' => 'My password reset link keeps saying it expired.',
        ]))->assertCreated()->assertJsonPath('super_admin_only', true)->json();

        // Only the Super Admin is told -- the LGU cannot act on it.
        $this->assertDatabaseHas('notifications', ['user_id' => $superAdmin->id, 'type' => 'support_ticket_opened']);
        $this->assertDatabaseMissing('notifications', ['user_id' => $lguAdmin->id, 'type' => 'support_ticket_opened']);

        // The LGU still sees and can read it, but cannot respond, note on, or close it.
        Sanctum::actingAs($lguAdmin);
        $listed = collect($this->getJson('/api/lgu/support-tickets')->assertOk()->json())->firstWhere('id', $ticket['id']);
        $this->assertTrue($listed['super_admin_only']);
        $this->getJson("/api/support/tickets/{$ticket['id']}")->assertOk();
        $this->postJson("/api/lgu/support-tickets/{$ticket['id']}/responses", ['body' => 'Try again later.'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Only the Super Admin can answer Account or login tickets.');
        $this->postJson("/api/lgu/support-tickets/{$ticket['id']}/responses", ['body' => 'Note.', 'internal' => true])->assertStatus(403);
        $this->patchJson("/api/lgu/support-tickets/{$ticket['id']}/resolve")->assertStatus(403);

        Sanctum::actingAs($superAdmin);
        $this->postJson("/api/super-admin/support-tickets/{$ticket['id']}/responses", ['body' => 'I sent you a new reset link.'])
            ->assertCreated()
            ->assertJsonPath('status', 'answered');
        // The buyer's reply is emailed to the Super Admin, not to the LGU that cannot answer it.
        Sanctum::actingAs($buyer);
        $this->postJson("/api/support/tickets/{$ticket['id']}/replies", ['body' => 'Got it, thank you!'])->assertCreated();
        Mail::assertSent(\App\Mail\SupportTicketUserRepliedMail::class, fn ($mail) => $mail->hasTo($superAdmin->email));
        Mail::assertNotSent(\App\Mail\SupportTicketUserRepliedMail::class, fn ($mail) => $mail->hasTo($lguAdmin->email));
        Sanctum::actingAs($superAdmin);
        $this->patchJson("/api/super-admin/support-tickets/{$ticket['id']}/resolve")->assertOk();

        // A shared topic stays answerable by the LGU.
        Sanctum::actingAs($buyer);
        $shared = $this->postJson('/api/support/tickets', $this->ticketForm(['category' => 'listing']))
            ->assertCreated()->assertJsonPath('super_admin_only', false)->json('id');
        Sanctum::actingAs($lguAdmin);
        $this->postJson("/api/lgu/support-tickets/{$shared}/responses", ['body' => 'Which listing?'])->assertCreated();
    }

    public function test_a_buyer_ticket_without_an_order_is_open_to_every_lgu_but_a_seller_ticket_stays_local(): void
    {
        Mail::fake();
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $otherMunicipality = Municipality::where('id', '!=', $lguAdmin->municipality_id)->firstOrFail();
        $otherLgu = $this->makeLguAdmin(['municipality_id' => $otherMunicipality->id]);
        // Buyers have no municipality -- even one set on the profile is not used.
        $buyer = $this->makeBuyer(['municipality_id' => $lguAdmin->municipality_id]);

        Sanctum::actingAs($buyer);
        $buyerTicket = $this->postJson('/api/support/tickets', $this->ticketForm(['category' => 'other']))
            ->assertCreated()
            ->assertJsonPath('municipality_id', null)
            ->json('id');

        // Every LGU is told, sees it, and may pick it up.
        foreach ([$lguAdmin, $otherLgu] as $lgu) {
            $this->assertDatabaseHas('notifications', ['user_id' => $lgu->id, 'type' => 'support_ticket_opened']);
            Sanctum::actingAs($lgu);
            $this->assertTrue(collect($this->getJson('/api/lgu/support-tickets')->assertOk()->json())->pluck('id')->contains($buyerTicket));
            $this->getJson("/api/support/tickets/{$buyerTicket}")->assertOk();
        }
        $this->postJson("/api/lgu/support-tickets/{$buyerTicket}/responses", ['body' => 'Happy to help -- what do you need?'])
            ->assertCreated()
            ->assertJsonPath('status', 'answered');

        // A seller's ticket stays with the seller's own LGU.
        $seller = $this->makeSeller(
            ['municipality_id' => $lguAdmin->municipality_id],
            ['municipality_id' => $lguAdmin->municipality_id]
        );
        Sanctum::actingAs($seller->user);
        $sellerTicket = $this->postJson('/api/support/tickets', $this->ticketForm(['category' => 'listing']))->assertCreated()->json('id');
        Sanctum::actingAs($otherLgu);
        $this->assertFalse(collect($this->getJson('/api/lgu/support-tickets')->assertOk()->json())->pluck('id')->contains($sellerTicket));
        $this->getJson("/api/support/tickets/{$sellerTicket}")->assertStatus(403);
    }

    public function test_sending_tickets_is_rate_limited(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->makeBuyer());

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/support/tickets', $this->ticketForm())->assertCreated();
        }
        $this->postJson('/api/support/tickets', $this->ticketForm())->assertStatus(429);
    }

    public function test_a_disabled_lgu_admin_is_not_notified_about_new_tickets(): void
    {
        Mail::fake();
        $lguAdmin = User::where('role', 'lgu_admin')->firstOrFail();
        $lguAdmin->update(['status' => 'disabled']);
        $buyer = $this->makeBuyer(['municipality_id' => $lguAdmin->municipality_id]);

        Sanctum::actingAs($buyer);
        $this->postJson('/api/support/tickets', $this->ticketForm())->assertCreated();

        $this->assertDatabaseHas('notifications', [
            'user_id' => User::where('role', 'super_admin')->value('id'),
            'type' => 'support_ticket_opened',
        ]);
        $this->assertDatabaseMissing('notifications', ['user_id' => $lguAdmin->id, 'type' => 'support_ticket_opened']);
    }

    public function test_the_ai_assistant_points_users_to_help_and_support(): void
    {
        foreach ([
            'How do I contact support?',
            'I want to talk to a person',
            'I found a bug in the app',
            'Where is the help center?',
            'How do I give feedback?',
            'Paano humingi ng tulong sa AbaiMarket?',
            'Asa ko mangayo og tabang?',
        ] as $question) {
            $result = \App\Support\AiIntentClassifier::classify($question);
            $this->assertNotNull($result['topic'], $question);
            $this->assertStringContainsString('Contact Support', $result['topic']['English'], "Expected \"{$question}\" to reach the Help & Support answer.");
            $this->assertStringContainsString('not real-time', $result['topic']['English']);
        }

        // Staff hear how to answer tickets, not how to open one.
        $topic = \App\Support\AiIntentClassifier::classify('How do I contact support?')['topic'];
        $this->assertStringContainsString('the Super Admin sees the same tickets', \App\Support\AiIntentClassifier::topicContext($topic, 'lgu_admin'));
        $this->assertStringContainsString('Internal Note', \App\Support\AiIntentClassifier::topicContext($topic, 'super_admin'));
        // Who sees buyer tickets, and that a closed ticket is final.
        $this->assertStringContainsString('every LGU', \App\Support\AiIntentClassifier::topicContext($topic, 'super_admin'));
        foreach (['buyer', 'seller', 'lgu_admin', 'super_admin'] as $role) {
            $this->assertMatchesRegularExpression('/closed.*(no longer be replied to|nobody can reply)/', \App\Support\AiIntentClassifier::topicContext($topic, $role), $role);
        }
        $this->assertStringContainsString('Contact Support', \App\Support\AiIntentClassifier::classify('Why can I not reply to my ticket?')['topic']['English']);

        // A bare "support" or "help" is not enough -- those stay on their own topics.
        $this->assertStringNotContainsString('Contact Support', (string) (\App\Support\AiIntentClassifier::classify('Does checkout support GCash?')['topic']['English'] ?? ''));
        $this->assertSame('Fish Care', \App\Support\AiIntentClassifier::classify('Help, my fish are sick')['category']);

        // The refund answer describes the real refund flow and where to follow up.
        $refund = \App\Support\AiIntentClassifier::classify('How do I get a refund?')['topic'];
        $this->assertStringContainsString('Refund Pending', $refund['English']);
        $this->assertStringContainsString('Payment or refund', $refund['English']);
        $this->assertStringContainsString('refund queue', \App\Support\AiIntentClassifier::topicContext($refund, 'super_admin'));
    }
}
