<?php

use App\Http\Controllers\Api\AiAssistantController;
use App\Http\Controllers\Api\BuyerController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LguController;
use App\Http\Controllers\Api\ListingController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\PlatformController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\SellerController;
use App\Http\Controllers\Api\SellerProfileController;
use App\Http\Controllers\Api\SuperAdminController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::patch('password', [AuthController::class, 'changePassword']);
    });
});

Route::post('paymongo/webhook', [OrderController::class, 'paymongoWebhook']);

Route::get('listings', [ListingController::class, 'index']);
Route::get('listings/{listing}', [ListingController::class, 'show']);

Route::get('sellers', [SellerProfileController::class, 'index']);
Route::get('sellers/{seller}', [SellerProfileController::class, 'show']);

Route::get('municipalities', [PlatformController::class, 'municipalities']);

Route::middleware(['auth:sanctum', 'role:buyer'])->group(function () {
    Route::get('buyer/dashboard', [BuyerController::class, 'dashboard']);
    Route::patch('buyer/profile', [BuyerController::class, 'updateProfile']);
    Route::post('buyer/profile/picture', [BuyerController::class, 'uploadProfilePicture']);
    Route::delete('buyer/profile/picture', [BuyerController::class, 'removeProfilePicture']);
    Route::get('buyer/notifications', [BuyerController::class, 'notifications']);
    Route::patch('buyer/notifications/{notification}/read', [BuyerController::class, 'markNotificationRead']);
    Route::post('orders', [OrderController::class, 'store']);
    Route::post('orders/{order}/checkout', [OrderController::class, 'checkout']);
    Route::post('orders/{order:order_number}/payment-success', [OrderController::class, 'markPaymentSuccess']);
    Route::post('orders/{order:order_number}/payment-cancelled', [OrderController::class, 'markPaymentCancelled']);
    Route::post('orders/{order}/review', [ReviewController::class, 'store']);
});

Route::middleware(['auth:sanctum', 'role:seller'])->group(function () {
    Route::get('seller/dashboard', [SellerController::class, 'dashboard']);
    Route::get('seller/analytics', [SellerController::class, 'analytics']);
    Route::patch('seller/profile', [SellerController::class, 'updateProfile']);
    Route::post('seller/profile/picture', [SellerController::class, 'uploadProfilePicture']);
    Route::delete('seller/profile/picture', [SellerController::class, 'removeProfilePicture']);
    Route::post('seller/profile/cover-photo', [SellerController::class, 'uploadCoverPhoto']);
    Route::delete('seller/profile/cover-photo', [SellerController::class, 'removeCoverPhoto']);
    Route::post('listings', [ListingController::class, 'store']);
    Route::patch('listings/{listing}', [ListingController::class, 'update']);
    Route::delete('listings/{listing}', [ListingController::class, 'destroy']);
    Route::post('listings/{listing}/media', [ListingController::class, 'uploadMedia']);
    Route::delete('listings/{listing}/media/{media}', [ListingController::class, 'deleteMedia']);
    Route::patch('listings/{listing}/media/reorder', [ListingController::class, 'reorderMedia']);
    Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus']);
    Route::get('seller/notifications', [SellerController::class, 'notifications']);
    Route::patch('seller/notifications/{notification}/read', [SellerController::class, 'markNotificationRead']);
    Route::get('seller/wallet', [SellerController::class, 'wallet']);
    Route::post('seller/withdrawals', [SellerController::class, 'requestWithdrawal']);
    Route::get('seller/buyers/{buyer}', [SellerController::class, 'buyerProfile']);
});

Route::middleware(['auth:sanctum', 'role:buyer,seller'])->group(function () {
    Route::get('orders', [OrderController::class, 'index']);
    Route::get('messages/threads', [MessageController::class, 'threads']);
    Route::get('messages/thread/{user}', [MessageController::class, 'thread']);
    Route::post('messages', [MessageController::class, 'store']);
    Route::patch('messages/{message}', [MessageController::class, 'update']);
    Route::delete('messages/{message}', [MessageController::class, 'destroy']);
    Route::patch('messages/thread/{user}/read', [MessageController::class, 'markThreadRead']);
});

Route::prefix('lgu')->middleware(['auth:sanctum', 'role:lgu_admin'])->group(function () {
    Route::get('dashboard', [LguController::class, 'dashboard']);
    Route::get('listings/{listing}', [LguController::class, 'show']);
    Route::patch('listings/{listing}/approve', [LguController::class, 'approveListing']);
    Route::patch('listings/{listing}/reject', [LguController::class, 'rejectListing']);
    Route::get('sellers', [LguController::class, 'sellers']);
    Route::patch('sellers/{seller}/verify', [LguController::class, 'verifySeller']);
    Route::patch('sellers/{seller}/suspend', [LguController::class, 'suspendSeller']);
    Route::patch('sellers/{seller}/reinstate', [LguController::class, 'reinstateSeller']);
    Route::get('users', [LguController::class, 'users']);
    Route::get('reviews', [PlatformController::class, 'lguReviews']);
    Route::get('reports', [PlatformController::class, 'lguReports']);
    Route::get('earnings', [LguController::class, 'pendingEarnings']);
    Route::patch('payments/{payment}/approve', [LguController::class, 'approveEarnings']);
    Route::patch('notifications/{notification}/read', [LguController::class, 'markNotificationRead']);
});

Route::prefix('super-admin')->middleware(['auth:sanctum', 'role:super_admin'])->group(function () {
    Route::get('dashboard', [SuperAdminController::class, 'dashboard']);
    Route::get('lgu-admins', [PlatformController::class, 'lguAdmins']);
    Route::post('lgu-admins', [SuperAdminController::class, 'storeLguAdmin']);
    Route::patch('lgu-admins/{admin}', [SuperAdminController::class, 'updateLguAdmin']);
    Route::patch('lgu-admins/{admin}/disable', [SuperAdminController::class, 'disableLguAdmin']);
    Route::patch('lgu-admins/{admin}/enable', [SuperAdminController::class, 'enableLguAdmin']);
    Route::get('sellers', [PlatformController::class, 'sellers']);
    Route::get('reports', [PlatformController::class, 'superReports']);
    Route::get('withdrawals', [SuperAdminController::class, 'withdrawals']);
    Route::patch('withdrawals/{withdrawal}/approve', [SuperAdminController::class, 'approveWithdrawal']);
    Route::patch('withdrawals/{withdrawal}/reject', [SuperAdminController::class, 'rejectWithdrawal']);
    Route::patch('withdrawals/{withdrawal}/paid', [SuperAdminController::class, 'markWithdrawalPaid']);
});

Route::post('ai-assistant/ask', [AiAssistantController::class, 'ask'])->middleware('auth:sanctum');
