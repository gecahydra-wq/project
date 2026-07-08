<?php

namespace Database\Seeders;

use App\Models\AiConversation;
use App\Models\AppNotification;
use App\Models\BuyerProfile;
use App\Models\FingerlingListing;
use App\Models\ListingMedia;
use App\Models\Message;
use App\Models\MockPayment;
use App\Models\Municipality;
use App\Models\Order;
use App\Models\Review;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $municipalities = collect(['Mandaue', 'Consolacion', 'Compostela', 'Liloan', 'Lapu-Lapu', 'Talisay', 'Carmen'])
            ->mapWithKeys(fn ($name) => [$name => Municipality::create(['name' => $name, 'province' => 'Cebu'])]);

        $buyer = User::create([
            'name' => 'Maria B.',
            'email' => 'buyer@fishmarket.test',
            'password' => Hash::make('password'),
            'role' => 'buyer',
            'municipality_id' => $municipalities['Mandaue']->id,
            'phone' => '09XX-XXX-0001',
        ]);

        BuyerProfile::create([
            'user_id' => $buyer->id,
            'municipality_id' => $municipalities['Mandaue']->id,
            'farm_name' => 'Maria Fish Farm',
            'water_source' => 'Freshwater pond',
            'pond_area' => 450,
        ]);

        AppNotification::create([
            'user_id' => $buyer->id,
            'type' => 'payment_success',
            'title' => 'Payment received',
            'body' => 'Your PayMongo payment was accepted and your order is now being processed.',
        ]);

        AppNotification::create([
            'user_id' => $buyer->id,
            'type' => 'listing_approval',
            'title' => 'Saved seller update',
            'body' => 'Juan\'s Hatchery has a new Bangus batch available for order.',
        ]);

        AppNotification::create([
            'user_id' => $buyer->id,
            'type' => 'order_created',
            'title' => 'Order placed',
            'body' => 'Your Bangus order #FG-0041 is now being reviewed by the seller.',
        ]);

        AppNotification::create([
            'user_id' => $buyer->id,
            'type' => 'payment_success',
            'title' => 'Payment confirmed',
            'body' => 'Your PayMongo checkout for order #FG-0041 was successful and funds are held in escrow.',
        ]);

        User::create([
            'name' => 'Admin Cruz',
            'email' => 'lgu@fishmarket.test',
            'password' => Hash::make('password'),
            'role' => 'lgu_admin',
            'municipality_id' => $municipalities['Mandaue']->id,
        ]);

        User::create([
            'name' => 'Super Admin',
            'email' => 'super@fishmarket.test',
            'password' => Hash::make('password'),
            'role' => 'super_admin',
        ]);

        $sellerRows = [
            ["Juan's Hatchery", 'seller@fishmarket.test', 'Mandaue', 4.8, true],
            ['BlueLake Hatchery', 'bluelake@fishmarket.test', 'Consolacion', 4.6, true],
            ['IslaFin Aqua', 'islafin@fishmarket.test', 'Lapu-Lapu', 4.4, false],
            ['CebuFish Farm', 'cebufish@fishmarket.test', 'Talisay', 4.5, true],
        ];

        $sellers = collect($sellerRows)->mapWithKeys(function ($row) use ($municipalities) {
            [$hatchery, $email, $municipality, $rating, $verified] = $row;
            $user = User::create([
                'name' => $hatchery,
                'email' => $email,
                'password' => Hash::make('password'),
                'role' => 'seller',
                'municipality_id' => $municipalities[$municipality]->id,
            ]);

            return [$hatchery => SellerProfile::create([
                'user_id' => $user->id,
                'municipality_id' => $municipalities[$municipality]->id,
                'hatchery_name' => $hatchery,
                'description' => 'Verified local hatchery supplying healthy fish fingerlings.',
                'rating' => $rating,
                'verified' => $verified,
                'status' => $verified ? 'verified' : 'pending',
            ])];
        });

        $listingRows = [
            ["Juan's Hatchery", 'Bangus', 'Chanos chanos', 'Bangus Fingerlings', 'Mandaue', 5000, 3.50, '4-5 inches', 'approved', ['Feeding routine video', 'Hatchery tank photo', 'Water quality video']],
            ['BlueLake Hatchery', 'Tilapia', 'Oreochromis niloticus', 'Tilapia Fingerlings', 'Consolacion', 10000, 3.00, '2-3 inches', 'approved', ['Pond aeration photo', 'Feeding video']],
            ['IslaFin Aqua', 'Grouper', 'Epinephelus', 'Grouper Fingerlings', 'Compostela', 1200, 5.00, '3-4 inches', 'pending', []],
            ["Juan's Hatchery", 'Milkfish', 'Chanos chanos', 'Milkfish Nursery Batch', 'Mandaue', 1800, 3.75, '3 inches', 'pending', ['Nursery inspection photo']],
            ['CebuFish Farm', 'Catfish', 'Clarias batrachus', 'Catfish Fingerlings', 'Talisay', 3000, 3.80, '3 inches', 'approved', ['Tank photo']],
            ['IslaFin Aqua', 'Sea Bass', 'Lates calcarifer', 'Sea Bass Fingerlings', 'Lapu-Lapu', 800, 5.00, '3 inches', 'approved', ['Nursery tank video']],
        ];

        $listings = collect($listingRows)->map(function ($row) use ($sellers, $municipalities) {
            [$seller, $species, $scientific, $title, $municipality, $qty, $price, $size, $approval, $media] = $row;
            $listing = FingerlingListing::create([
                'seller_profile_id' => $sellers[$seller]->id,
                'municipality_id' => $municipalities[$municipality]->id,
                'species' => $species,
                'scientific_name' => $scientific,
                'title' => $title,
                'quantity' => $qty,
                'price_per_piece' => $price,
                'average_size' => $size,
                'availability_status' => $qty < 1500 ? 'limited' : 'in_stock',
                'approval_status' => $approval,
            ]);

            foreach ($media as $item) {
                ListingMedia::create([
                    'listing_id' => $listing->id,
                    'type' => str_contains(strtolower($item), 'video') ? 'video' : 'photo',
                    'title' => $item,
                ]);
            }

            return $listing;
        });

        $orders = [
            ['FG-0041', $listings[0], 2000, 'completed', 'held'],
            ['FG-0039', $listings[1], 1400, 'completed', 'held'],
            ['FG-0038', $listings[1], 5000, 'completed', 'released'],
            ['FG-0036', $listings[3], 900, 'in_transit', 'awaiting'],
        ];

        foreach ($orders as [$number, $listing, $qty, $status, $paymentStatus]) {
            $order = Order::create([
                'order_number' => $number,
                'buyer_id' => $buyer->id,
                'seller_profile_id' => $listing->seller_profile_id,
                'listing_id' => $listing->id,
                'quantity' => $qty,
                'unit_price' => $listing->price_per_piece,
                'total_amount' => $qty * $listing->price_per_piece,
                'status' => $status,
            ]);

            MockPayment::create([
                'order_id' => $order->id,
                'amount' => $order->total_amount,
                'status' => $paymentStatus,
                'provider' => 'paymongo',
                'released_at' => $paymentStatus === 'released' ? now() : null,
            ]);
        }

        Message::create([
            'sender_id' => $buyer->id,
            'receiver_id' => $sellers["Juan's Hatchery"]->user_id,
            'body' => 'Available pa ang 2,000 bangus fingerlings for pickup this week?',
        ]);

        Review::create([
            'order_id' => Order::where('order_number', 'FG-0038')->first()->id,
            'buyer_id' => $buyer->id,
            'seller_profile_id' => $sellers['BlueLake Hatchery']->id,
            'rating' => 5,
            'comment' => 'Healthy fingerlings and clear pickup coordination.',
        ]);

        AiConversation::create([
            'user_id' => $buyer->id,
            'language' => 'Bisaya',
            'message' => 'Unsay maayo nga isda para sa beginner?',
            'response' => 'Tambag: Tilapia is a practical beginner species. Buy verified fingerlings and keep water quality stable.',
        ]);
    }
}
