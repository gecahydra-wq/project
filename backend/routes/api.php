<?php

use App\Http\Controllers\Api\AiAssistantController;
use App\Http\Controllers\Api\BuyerController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LguController;
use App\Http\Controllers\Api\ListingController;
use App\Http\Controllers\Api\PlatformController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\SellerController;
use App\Http\Controllers\Api\SuperAdminController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

Route::post('paymongo/webhook', [OrderController::class, 'paymongoWebhook']);

Route::get('listings', [ListingController::class, 'index']);
Route::get('listings/{listing}', [ListingController::class, 'show']);

Route::middleware(['auth:sanctum', 'role:buyer'])->group(function () {
    Route::get('buyer/dashboard', [BuyerController::class, 'dashboard']);
    Route::get('buyer/notifications', [BuyerController::class, 'notifications']);
    Route::patch('buyer/notifications/{notification}/read', [BuyerController::class, 'markNotificationRead']);
    Route::post('orders', [OrderController::class, 'store']);
    Route::post('orders/{order}/checkout', [OrderController::class, 'checkout']);
    Route::post('orders/{order:order_number}/payment-success', [OrderController::class, 'markPaymentSuccess']);
    Route::post('orders/{order:order_number}/payment-cancelled', [OrderController::class, 'markPaymentCancelled']);
});

Route::middleware(['auth:sanctum', 'role:seller'])->group(function () {
    Route::get('seller/dashboard', [SellerController::class, 'dashboard']);
    Route::get('seller/analytics', [SellerController::class, 'analytics']);
    Route::post('listings', [ListingController::class, 'store']);
    Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus']);
});

Route::middleware(['auth:sanctum', 'role:buyer,seller'])->group(function () {
    Route::get('orders', [OrderController::class, 'index']);
});

Route::prefix('lgu')->middleware(['auth:sanctum', 'role:lgu_admin'])->group(function () {
    Route::get('dashboard', [LguController::class, 'dashboard']);
    Route::patch('listings/{listing}/approve', [LguController::class, 'approveListing']);
    Route::patch('listings/{listing}/reject', [LguController::class, 'rejectListing']);
    Route::patch('sellers/{seller}/verify', [LguController::class, 'verifySeller']);
    Route::get('reviews', [PlatformController::class, 'lguReviews']);
    Route::get('reports', [PlatformController::class, 'lguReports']);
});

Route::prefix('super-admin')->middleware(['auth:sanctum', 'role:super_admin'])->group(function () {
    Route::get('dashboard', [SuperAdminController::class, 'dashboard']);
    Route::patch('payments/{payment}/release', [SuperAdminController::class, 'releasePayment']);
    Route::get('lgu-admins', [PlatformController::class, 'lguAdmins']);
    Route::get('reports', [PlatformController::class, 'superReports']);
});

Route::post('ai-assistant/ask', [AiAssistantController::class, 'ask'])->middleware('auth:sanctum');
