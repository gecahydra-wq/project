<?php

use App\Models\Municipality;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Municipality::firstOrCreate(
            ['name' => 'Cordova'],
            ['province' => 'Cebu']
        );
    }

    public function down(): void
    {
        // Intentionally not reversed: removing the row could cascade-delete any
        // seller profiles or LGU admins that have since been assigned to Cordova.
    }
};
