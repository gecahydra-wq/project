<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('announcements:publish')->everyFiveMinutes();
Schedule::command('orders:expire-unpaid')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
