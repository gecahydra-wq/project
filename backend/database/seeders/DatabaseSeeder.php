<?php

namespace Database\Seeders;

use App\Models\Municipality;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $municipalities = collect(['Mandaue', 'Consolacion', 'Compostela', 'Liloan', 'Lapu-Lapu', 'Talisay', 'Carmen', 'Cordova'])
            ->mapWithKeys(fn ($name) => [$name => Municipality::firstOrCreate(['name' => $name], ['province' => 'Cebu'])]);

        User::create([
            'name' => 'LGU Admin',
            'email' => 'lgu@gmail.com',
            'password' => Hash::make('admin2026'),
            'role' => 'lgu_admin',
            'municipality_id' => $municipalities['Mandaue']->id,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        User::create([
            'name' => 'Super Admin',
            'email' => 'superadmin@gmail.com',
            'password' => Hash::make('admin2026'),
            'role' => 'super_admin',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }
}
