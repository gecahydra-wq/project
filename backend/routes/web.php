<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
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
        'title' => 'Payment Declined',
        'headline' => 'Card payment declined',
        'message' => 'The payment did not go through. Your order was marked failed and the reservation was released.',
        'primary_label' => 'Return to Merchant',
        'primary_url' => $frontend.$merchant,
        'secondary_label' => 'Go Home',
        'secondary_url' => $frontend.'/',
    ]);
});
