<?php

use App\Http\Controllers\Api\AiAssistantController;
use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\BuyerController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\GoogleAuthController;
use App\Http\Controllers\Api\LguController;
use App\Http\Controllers\Api\ListingController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\PlatformController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\SellerController;
use App\Http\Controllers\Api\SellerPostController;
use App\Http\Controllers\Api\SellerPostInteractionController;
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

    // Google OAuth -- both legs are full-page redirects (browser navigation,
    // not XHR), since the user has to actually visit Google's consent screen.
    Route::get('google/redirect', [GoogleAuthController::class, 'redirect']);
    Route::get('google/callback', [GoogleAuthController::class, 'callback']);
});

// Email verification -- unauthenticated by design; see EmailVerificationController.
Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->middleware('throttle:6,1')
    ->name('verification.verify');
Route::post('email/resend', [EmailVerificationController::class, 'resend'])
    ->middleware('throttle:6,1')
    ->name('verification.send');

// Safety net only: the 'verified' middleware falls back to this named route
// when a request doesn't look like an API/JSON call (real SPA/API traffic
// always gets a 403 JSON response instead, same as the 'auth:sanctum' 401
// case above). Nothing in this app links here directly.
Route::get('email/verify', fn () => redirect(rtrim(config('app.frontend_url'), '/').'/login'))
    ->name('verification.notice');

Route::post('paymongo/webhook', [OrderController::class, 'paymongoWebhook']);

Route::get('listings', [ListingController::class, 'index']);
Route::get('listings/{listing}', [ListingController::class, 'show']);

Route::get('sellers', [SellerProfileController::class, 'index']);
Route::get('sellers/{seller}', [SellerProfileController::class, 'show']);

Route::get('municipalities', [PlatformController::class, 'municipalities']);

Route::middleware(['auth:sanctum', 'verified', 'role:buyer'])->group(function () {
    Route::get('buyer/dashboard', [BuyerController::class, 'dashboard']);
    Route::patch('buyer/profile', [BuyerController::class, 'updateProfile']);
    Route::post('buyer/profile/picture', [BuyerController::class, 'uploadProfilePicture']);
    Route::delete('buyer/profile/picture', [BuyerController::class, 'removeProfilePicture']);
    Route::get('buyer/notifications', [BuyerController::class, 'notifications']);
    Route::patch('buyer/notifications/read-all', [BuyerController::class, 'markAllNotificationsRead']);
    Route::patch('buyer/notifications/{notification}/read', [BuyerController::class, 'markNotificationRead']);
    Route::get('buyer/analytics', [BuyerController::class, 'analytics']);
    Route::post('orders', [OrderController::class, 'store']);
    Route::post('orders/{order}/checkout', [OrderController::class, 'checkout']);
    Route::post('orders/{order:order_number}/payment-success', [OrderController::class, 'markPaymentSuccess']);
    Route::post('orders/{order:order_number}/payment-cancelled', [OrderController::class, 'markPaymentCancelled']);
    Route::post('orders/{order}/review', [ReviewController::class, 'store']);
});

Route::middleware(['auth:sanctum', 'verified', 'role:seller'])->group(function () {
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
    Route::patch('seller/notifications/read-all', [SellerController::class, 'markAllNotificationsRead']);
    Route::patch('seller/notifications/{notification}/read', [SellerController::class, 'markNotificationRead']);
    Route::get('seller/wallet', [SellerController::class, 'wallet']);
    Route::post('seller/withdrawals', [SellerController::class, 'requestWithdrawal']);
    // Seller Posts -- the seller's own farm/hatchery feed. Reads are public
    // (SellerProfileController::show); these writes are owner-only.
    Route::post('seller/posts', [SellerPostController::class, 'store']);
    Route::patch('seller/posts/{post}', [SellerPostController::class, 'update']);
    Route::delete('seller/posts/{post}', [SellerPostController::class, 'destroy']);
    Route::post('seller/posts/{post}/media', [SellerPostController::class, 'addMedia']);
    Route::delete('seller/posts/{post}/media/{media}', [SellerPostController::class, 'deleteMedia']);
    Route::get('seller/buyers/{buyer}', [SellerController::class, 'buyerProfile']);
    Route::post('orders/{order}/rate-buyer', [SellerController::class, 'rateBuyer']);
    Route::patch('orders/{order:order_number}/notes', [OrderController::class, 'updateSellerNotes']);
});

Route::middleware(['auth:sanctum', 'verified', 'role:buyer,seller'])->group(function () {
    Route::get('orders', [OrderController::class, 'index']);
    // Order Lookup by Order Number -- Buyer's own orders, or Seller's own
    // listings' orders (scoped in OrderController::show). Also how the
    // Seller Order Lookup search resolves an Order Number to a transaction.
    Route::get('orders/{order:order_number}', [OrderController::class, 'show']);
});

Route::middleware(['auth:sanctum', 'verified', 'role:buyer,seller,lgu_admin,super_admin'])->group(function () {
    Route::get('messages/threads', [MessageController::class, 'threads']);
    Route::get('messages/thread/{user}', [MessageController::class, 'thread']);
    Route::post('messages', [MessageController::class, 'store']);
    Route::patch('messages/{message}', [MessageController::class, 'update']);
    Route::delete('messages/{message}', [MessageController::class, 'destroy']);
    Route::patch('messages/thread/{user}/read', [MessageController::class, 'markThreadRead']);

    // Seller Post engagement -- likes and comments, open to every role. Reads
    // come with the public seller profile; these are the writes.
    Route::post('seller-posts/{post}/like', [SellerPostInteractionController::class, 'toggleLike']);
    Route::post('seller-posts/{post}/comments', [SellerPostInteractionController::class, 'storeComment']);
    Route::delete('seller-posts/comments/{comment}', [SellerPostInteractionController::class, 'destroyComment']);
});

Route::prefix('lgu')->middleware(['auth:sanctum', 'verified', 'role:lgu_admin'])->group(function () {
    Route::get('dashboard', [LguController::class, 'dashboard']);
    Route::get('listings', [LguController::class, 'listings']);
    Route::get('listings/{listing}', [LguController::class, 'show']);
    Route::patch('listings/{listing}/approve', [LguController::class, 'approveListing']);
    Route::patch('listings/{listing}/reject', [LguController::class, 'rejectListing']);
    Route::patch('listings/{listing}/archive', [LguController::class, 'archiveListing']);
    Route::delete('listings/{listing}', [LguController::class, 'destroyListing']);
    Route::get('sellers', [LguController::class, 'sellers']);
    Route::patch('sellers/{seller}/verify', [LguController::class, 'verifySeller']);
    Route::patch('sellers/{seller}/suspend', [LguController::class, 'suspendSeller']);
    Route::patch('sellers/{seller}/reinstate', [LguController::class, 'reinstateSeller']);
    Route::get('users', [LguController::class, 'users']);
    Route::get('reviews', [PlatformController::class, 'lguReviews']);
    Route::delete('reviews/{review}', [LguController::class, 'destroyReview']);
    Route::delete('buyer-ratings/{rating}', [LguController::class, 'destroyBuyerRating']);
    Route::get('reports', [PlatformController::class, 'lguReports']);
    Route::get('reports/export', [PlatformController::class, 'exportLguReport']);
    Route::get('earnings', [LguController::class, 'pendingEarnings']);
    Route::get('orders/{order:order_number}', [LguController::class, 'showOrder']);
    Route::patch('payments/{payment}/approve', [LguController::class, 'approveEarnings']);
    Route::patch('payments/{payment}/hold', [LguController::class, 'holdEarnings']);
    Route::patch('payments/{payment}/clear-hold', [LguController::class, 'clearHold']);
    Route::patch('payments/{payment}/reject', [LguController::class, 'rejectEarnings']);
    Route::patch('notifications/{notification}/read', [LguController::class, 'markNotificationRead']);
    Route::post('profile/picture', [LguController::class, 'uploadProfilePicture']);
    Route::delete('profile/picture', [LguController::class, 'removeProfilePicture']);
    Route::get('wallet', [LguController::class, 'wallet']);
    Route::post('withdrawals', [LguController::class, 'requestWithdrawal']);
    Route::get('activity-log', [LguController::class, 'activityLog']);
    Route::get('activity-log/actions', [LguController::class, 'activityLogActions']);
    Route::get('activity-log/categories', [LguController::class, 'activityLogCategories']);
});

Route::prefix('super-admin')->middleware(['auth:sanctum', 'verified', 'role:super_admin'])->group(function () {
    Route::get('dashboard', [SuperAdminController::class, 'dashboard']);
    Route::get('orders/{order:order_number}', [SuperAdminController::class, 'showOrder']);
    Route::get('activity-log', [SuperAdminController::class, 'activityLog']);
    Route::get('activity-log/actions', [SuperAdminController::class, 'activityLogActions']);
    Route::get('activity-log/categories', [SuperAdminController::class, 'activityLogCategories']);
    Route::post('municipalities', [SuperAdminController::class, 'storeMunicipality']);
    Route::get('lgu-admins', [PlatformController::class, 'lguAdmins']);
    Route::post('lgu-admins', [SuperAdminController::class, 'storeLguAdmin']);
    Route::patch('lgu-admins/{admin}', [SuperAdminController::class, 'updateLguAdmin']);
    Route::patch('lgu-admins/{admin}/disable', [SuperAdminController::class, 'disableLguAdmin']);
    Route::patch('lgu-admins/{admin}/enable', [SuperAdminController::class, 'enableLguAdmin']);
    Route::get('sellers', [PlatformController::class, 'sellers']);
    Route::patch('sellers/{seller}/suspend', [SuperAdminController::class, 'suspendSeller']);
    Route::patch('sellers/{seller}/reinstate', [SuperAdminController::class, 'reinstateSeller']);
    Route::get('users', [SuperAdminController::class, 'users']);
    Route::patch('buyers/{user}/suspend', [SuperAdminController::class, 'suspendBuyer']);
    Route::patch('buyers/{user}/reinstate', [SuperAdminController::class, 'reinstateBuyer']);
    Route::get('moderation-log', [SuperAdminController::class, 'moderationLog']);
    Route::get('reviews', [PlatformController::class, 'superReviews']);
    Route::delete('reviews/{review}', [SuperAdminController::class, 'destroyReview']);
    Route::delete('buyer-ratings/{rating}', [SuperAdminController::class, 'destroyBuyerRating']);
    Route::get('reports', [PlatformController::class, 'superReports']);
    Route::get('reports/export', [PlatformController::class, 'exportSuperReport']);
    Route::get('withdrawals', [SuperAdminController::class, 'withdrawals']);
    Route::patch('withdrawals/{withdrawal}/approve', [SuperAdminController::class, 'approveWithdrawal']);
    Route::patch('withdrawals/{withdrawal}/reject', [SuperAdminController::class, 'rejectWithdrawal']);
    Route::patch('withdrawals/{withdrawal}/paid', [SuperAdminController::class, 'markWithdrawalPaid']);
    Route::get('lgu-withdrawals', [SuperAdminController::class, 'lguWithdrawals']);
    Route::patch('lgu-withdrawals/{withdrawal}/approve', [SuperAdminController::class, 'approveLguWithdrawal']);
    Route::patch('lgu-withdrawals/{withdrawal}/reject', [SuperAdminController::class, 'rejectLguWithdrawal']);
    Route::patch('lgu-withdrawals/{withdrawal}/paid', [SuperAdminController::class, 'markLguWithdrawalPaid']);
    Route::get('notifications', [SuperAdminController::class, 'notifications']);
    Route::patch('notifications/{notification}/read', [SuperAdminController::class, 'markNotificationRead']);
    Route::post('profile/picture', [SuperAdminController::class, 'uploadProfilePicture']);
    Route::delete('profile/picture', [SuperAdminController::class, 'removeProfilePicture']);
    Route::get('listings', [SuperAdminController::class, 'listings']);
    Route::get('listings/{listing}', [SuperAdminController::class, 'showListing']);
    Route::patch('listings/{listing}', [SuperAdminController::class, 'updateListing']);
    Route::patch('listings/{listing}/approve', [SuperAdminController::class, 'approveListing']);
    Route::patch('listings/{listing}/reject', [SuperAdminController::class, 'rejectListing']);
    Route::patch('listings/{listing}/archive', [SuperAdminController::class, 'archiveListing']);
    Route::delete('listings/{listing}', [SuperAdminController::class, 'destroyListing']);
    Route::get('announcements', [AnnouncementController::class, 'index']);
    Route::post('announcements', [AnnouncementController::class, 'store']);
    Route::patch('announcements/{announcement}', [AnnouncementController::class, 'update']);
    Route::delete('announcements/{announcement}', [AnnouncementController::class, 'destroy']);
});

Route::middleware(['auth:sanctum', 'verified', 'role:buyer,seller,lgu_admin,super_admin'])->group(function () {
    Route::post('ai-assistant/ask', [AiAssistantController::class, 'ask']);
    Route::get('ai-assistant/history', [AiAssistantController::class, 'history']);
    Route::get('announcements/active', [AnnouncementController::class, 'active']);
});
