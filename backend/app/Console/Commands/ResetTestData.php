<?php

namespace App\Console\Commands;

use App\Models\BuyerProfile;
use App\Models\Municipality;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * One-off reset of a test deployment to a clean slate:
 *
 *  - keeps every Super Admin and exactly one seller (--keep-seller) with their
 *    profile, listings and posts;
 *  - deletes every other account (all buyers, all LGU Admins, all other
 *    sellers) and all transactional data -- orders, payments, settlements,
 *    seller + LGU withdrawals, disputes, reports, notices, messages,
 *    notifications, activity/moderation logs -- so every wallet restarts at 0;
 *  - creates one LGU Admin per municipality and --buyers test buyers, all
 *    verified, on "+tag" aliases of --email so their mail reaches one inbox;
 *  - removes uploaded images no surviving record points at.
 *
 * Dry run by default: it only prints what it would do. Nothing changes
 * without --execute. Passwords are generated at run time and printed once;
 * they are never stored anywhere but as hashes.
 */
class ResetTestData extends Command
{
    protected $signature = 'app:reset-test-data
        {--email= : Base inbox for the test accounts, e.g. you@gmail.com (accounts become you+buyer1@gmail.com, ...)}
        {--keep-seller=Roido\'s Fisheries : Hatchery name of the only seller to keep}
        {--buyers=3 : How many test buyers to create}
        {--execute : Actually perform the reset (otherwise a dry run)}';

    protected $description = 'Wipe test accounts and transactions, keep one seller, and create fresh LGU + buyer test accounts';

    /** Wiped completely, children before parents. */
    private const WIPE_TABLES = [
        'disputes',
        'user_reports',
        'seller_notices',
        'withdrawal_requests',
        'lgu_withdrawal_requests',
        'cart_items',
        'ai_growth_logs',
        'reviews',
        'settlements',
        'payment_logs',
        'payments',
        'orders',
        'messages',
        'notifications',
        'activity_logs',
        'moderation_logs',
    ];

    /** Upload directories on the public disk swept for orphaned files. */
    private const UPLOAD_DIRECTORIES = ['profile-pictures', 'cover-photos', 'listings', 'seller-posts'];

    public function handle(): int
    {
        $email = (string) $this->option('email');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Pass --email=you@example.com (the inbox the test accounts will use).');

            return self::FAILURE;
        }

        $keepName = (string) $this->option('keep-seller');
        $kept = SellerProfile::whereRaw('LOWER(hatchery_name) = ?', [mb_strtolower($keepName)])->get();
        if ($kept->count() !== 1) {
            $this->error("Expected exactly one seller named \"{$keepName}\", found {$kept->count()}. Nothing was changed.");

            return self::FAILURE;
        }
        $keptSeller = $kept->first();

        $keepUserIds = User::where('role', 'super_admin')->pluck('id')->push($keptSeller->user_id)->unique()->values();
        $doomed = User::whereNotIn('id', $keepUserIds)->get(['id', 'email', 'role']);
        $municipalities = Municipality::orderBy('name')->get();
        $buyerCount = max(0, (int) $this->option('buyers'));

        $newAccounts = $this->plannedAccounts($email, $municipalities, $buyerCount);
        $clashes = User::whereIn('id', $keepUserIds)->whereIn('email', array_column($newAccounts, 'email'))->pluck('email');
        if ($clashes->isNotEmpty()) {
            $this->error('These test emails already belong to an account being kept: '.$clashes->implode(', '));

            return self::FAILURE;
        }

        $orphanFiles = $this->orphanFiles($keepUserIds, $keptSeller);

        $this->info($this->option('execute') ? 'RESET -- this will change the database.' : 'DRY RUN -- nothing will be changed.');
        $this->line('Keeping: '.User::whereIn('id', $keepUserIds)->get(['email', 'role'])->map(fn ($u) => "{$u->email} ({$u->role})")->implode(', '));
        $this->line("Kept seller: {$keptSeller->hatchery_name} -- {$keptSeller->listings()->count()} listing(s) stay.");
        $this->table(['Accounts to delete', 'Count'], $doomed->countBy('role')->map(fn ($n, $role) => [$role, $n])->values()->all());
        $this->table(['Table to wipe', 'Rows'], collect(self::WIPE_TABLES)
            ->filter(fn ($t) => Schema::hasTable($t))
            ->map(fn ($t) => [$t, DB::table($t)->count()])
            ->values()->all());
        $this->line('Accounts to create: '.count($newAccounts).' ('.$municipalities->count()." LGU Admins, {$buyerCount} buyers)");
        $this->line('Orphaned upload files to delete: '.count($orphanFiles));

        if (! $this->option('execute')) {
            $this->warn('Dry run only. Re-run with --execute to apply.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Permanently delete the data above and create the test accounts?')) {
            $this->warn('Cancelled. Nothing was changed.');

            return self::FAILURE;
        }

        $credentials = DB::transaction(function () use ($doomed, $keptSeller, $newAccounts) {
            foreach (self::WIPE_TABLES as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->delete();
                }
            }

            $ids = $doomed->pluck('id');
            foreach ($ids->chunk(500) as $chunk) {
                // Tokens and sessions carry no foreign key, so they would
                // otherwise outlive the accounts they belong to.
                DB::table('personal_access_tokens')->where('tokenable_type', User::class)->whereIn('tokenable_id', $chunk)->delete();
                DB::table('sessions')->whereIn('user_id', $chunk)->delete();
                // Cascades to buyer/seller profiles, listings, media, posts,
                // likes, comments, AI chats and cart items.
                User::whereIn('id', $chunk)->delete();
            }
            DB::table('password_reset_tokens')->whereIn('email', $doomed->pluck('email'))->delete();

            // Cached average of the reviews just wiped.
            $keptSeller->update(['rating' => 0]);

            $credentials = [];
            foreach ($newAccounts as $account) {
                $password = 'Test-'.Str::upper(Str::random(3)).Str::lower(Str::random(3)).random_int(100, 999);
                $user = User::create([
                    'name' => $account['name'],
                    'email' => $account['email'],
                    'password' => $password,
                    'role' => $account['role'],
                    'municipality_id' => $account['municipality_id'],
                    'status' => 'active',
                    'email_verified_at' => now(),
                ]);
                if ($account['role'] === 'buyer') {
                    BuyerProfile::create(['user_id' => $user->id, 'municipality_id' => null]);
                }
                $credentials[] = [$account['label'], $account['email'], $password];
            }

            return $credentials;
        });

        Storage::disk('public')->delete($orphanFiles);

        $this->info('Done. Save these now -- the passwords are not shown again:');
        $this->table(['Account', 'Email', 'Password'], $credentials);

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{label: string, name: string, email: string, role: string, municipality_id: ?int}>
     */
    private function plannedAccounts(string $email, $municipalities, int $buyerCount): array
    {
        [$local, $domain] = explode('@', $email, 2);
        $accounts = [];

        foreach ($municipalities as $municipality) {
            $accounts[] = [
                'label' => "LGU Admin -- {$municipality->name}",
                'name' => "LGU Admin {$municipality->name}",
                'email' => "{$local}+lgu.".Str::slug($municipality->name, '')."@{$domain}",
                'role' => 'lgu_admin',
                'municipality_id' => $municipality->id,
            ];
        }

        for ($i = 1; $i <= $buyerCount; $i++) {
            $accounts[] = [
                'label' => "Buyer {$i}",
                'name' => "Test Buyer {$i}",
                'email' => "{$local}+buyer{$i}@{$domain}",
                'role' => 'buyer',
                'municipality_id' => null,
            ];
        }

        return $accounts;
    }

    /**
     * Files in the upload directories that no surviving record references --
     * computed from the records being KEPT, so it is the same in a dry run.
     *
     * @return array<int, string>
     */
    private function orphanFiles($keepUserIds, SellerProfile $keptSeller): array
    {
        $references = collect()
            ->merge(User::whereIn('id', $keepUserIds)->pluck('profile_picture'))
            ->merge(BuyerProfile::whereIn('user_id', $keepUserIds)->pluck('profile_picture'))
            ->merge([$keptSeller->profile_picture, $keptSeller->cover_photo, json_encode($keptSeller->gallery, JSON_UNESCAPED_SLASHES)])
            ->merge(DB::table('listing_media')
                ->join('listings', 'listings.id', '=', 'listing_media.listing_id')
                ->where('listings.seller_profile_id', $keptSeller->id)
                ->pluck('listing_media.url'))
            ->merge(DB::table('seller_post_media')
                ->join('seller_posts', 'seller_posts.id', '=', 'seller_post_media.seller_post_id')
                ->where('seller_posts.seller_profile_id', $keptSeller->id)
                ->pluck('seller_post_media.url'))
            ->filter()
            ->implode("\n");

        $disk = Storage::disk('public');

        return collect(self::UPLOAD_DIRECTORIES)
            ->flatMap(fn ($directory) => $disk->allFiles($directory))
            ->reject(fn ($path) => str_contains($references, $path))
            ->values()
            ->all();
    }
}
