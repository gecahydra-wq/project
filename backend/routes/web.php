<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Aiven's free MySQL plan powers the database off after a stretch with no
// client connections, which takes the whole site down until someone turns it
// back on by hand. The scheduler in routes/console.php already queries the
// database every five minutes, but only while both this container and the
// background `schedule:work` worker are alive -- start.sh starts that worker
// detached and deliberately lets the web server carry on without it. This
// endpoint gives an external uptime pinger a single URL that wakes the
// container AND forces a real database round-trip, so neither can sit idle
// unnoticed. Laravel's own /up health check never touches the database, which
// is why it cannot serve this purpose.
Route::get('/up/db', function () {
    try {
        DB::connection()->getPdo()->query('SELECT 1');
    } catch (\Throwable $e) {
        // Log it for us, but never hand connection details to an anonymous
        // caller -- this route is deliberately unauthenticated.
        report($e);

        return response()->json(['status' => 'error', 'database' => 'unreachable'], 503);
    }

    return response()->json(['status' => 'ok', 'database' => 'reachable']);
});

Route::get('/paymongo/success', function () {
    $role = request('role', 'buyer');
    $frontend = rtrim(config('app.frontend_url', 'http://127.0.0.1:5173'), '/');
    $dashboard = match ($role) {
        'seller' => '/seller/dashboard?tab=overview',
        'lgu_admin' => '/lgu/dashboard?tab=overview',
        'super_admin' => '/admin/dashboard?tab=overview',
        default => '/buyer/dashboard?tab=orders',
    };
    $notifications = match ($role) {
        'seller' => '/seller/dashboard?tab=overview',
        'lgu_admin' => '/lgu/dashboard?tab=overview',
        'super_admin' => '/admin/dashboard?tab=overview',
        default => '/buyer/dashboard?tab=notifications',
    };

    return view('paymongo-return', [
        'status' => 'success',
        'title' => 'Payment Successful',
        'headline' => 'Order received',
        'message' => 'Your payment was successful and your order is now held in escrow.',
        'primary_label' => 'Go to Dashboard',
        'primary_url' => $frontend.$dashboard,
        'secondary_label' => 'Open Notifications',
        'secondary_url' => $frontend.$notifications,
    ]);
});

Route::get('/paymongo/cancelled', function () {
    $role = request('role', 'buyer');
    $frontend = rtrim(config('app.frontend_url', 'http://127.0.0.1:5173'), '/');
    $merchant = match ($role) {
        'seller' => '/seller/dashboard?tab=overview',
        'lgu_admin' => '/lgu/dashboard?tab=overview',
        'super_admin' => '/admin/dashboard?tab=overview',
        default => '/buyer/dashboard?tab=browse',
    };

    return view('paymongo-return', [
        'status' => 'cancelled',
        'title' => 'Payment Not Completed',
        'headline' => 'Payment not completed',
        // The order is NOT failed here -- see OrderController::markPaymentCancelled.
        // It stays reserved and payable until orders:expire-unpaid closes the window.
        'message' => 'The payment did not go through. Your order is still reserved for you and you can pay for it from My Orders until the payment window closes.',
        'primary_label' => 'Return to Merchant',
        'primary_url' => $frontend.$merchant,
        'secondary_label' => 'Go Home',
        'secondary_url' => $frontend.'/',
    ]);
});
