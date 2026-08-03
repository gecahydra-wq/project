<?php

use App\Http\Controllers\Api\AiAssistantController;
use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\BuyerController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CartController;
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
use App\Http\Controllers\Api\UserReportController;
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
    // "Buy later" cart -- saved listings only. Checkout still goes through
    // POST /orders + /orders/{order}/checkout below; see CartController.
    Route::get('cart', [CartController::class, 'index']);
    Route::post('cart', [CartController::class, 'store']);
    Route::patch('cart/{item}', [CartController::class, 'update']);
    Route::delete('cart/{item}', [CartController::class, 'destroy']);
    Route::delete('cart', [CartController::class, 'clear']);
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
    // Notices to Explain raised against this seller by the automatic
    // low-rating check (App\Support\SellerReputation). The seller can read and
    // answer them; only their LGU can close one.
    Route::get('seller/notices', [SellerController::class, 'notices']);
    Route::post('seller/notices/{notice}/respond', [SellerController::class, 'respondToNotice']);
    Route::get('seller/buyers/{buyer}', [SellerController::class, 'buyerProfile']);
    Route::post('orders/{order}/rate-buyer', [SellerController::class, 'rateBuyer']);
    Route::patch('orders/{order:order_number}/notes', [OrderController::class, 'updateSellerNotes']);
});

Route::middleware(['auth:sanctum', 'verified', 'role:buyer,seller'])->group(function () {
    // User Reports -- a Buyer reports a Seller, a Seller reports a Buyer. The
    // direction is derived from the caller's role (UserReportController).
    Route::get('reports/reasons', [UserReportController::class, 'reasons']);
    Route::get('reports/mine', [UserReportController::class, 'mine']);
    Route::post('reports', [UserReportController::class, 'store']);
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
    // Seller Registration Approval, stage 1 (App\Support\SellerApproval).
    // 'verify' is kept as a backwards-compatible alias of 'approve'.
    Route::get('seller-registrations', [LguController::class, 'sellerRegistrations']);
    Route::patch('sellers/{seller}/approve-registration', [LguController::class, 'approveSellerRegistration']);
    Route::patch('sellers/{seller}/reject-registration', [LguController::class, 'rejectSellerRegistration']);
    Route::patch('sellers/{seller}/verify', [LguController::class, 'verifySeller']);
    Route::patch('sellers/{seller}/suspend', [LguController::class, 'suspendSeller']);
    Route::patch('sellers/{seller}/reinstate', [LguController::class, 'reinstateSeller']);
    // User Reports and Notices to Explain -- both scoped to this LGU's own
    // municipality (App\Support\UserReports / App\Support\SellerReputation).
    Route::get('user-reports', [LguController::class, 'userReports']);
    Route::patch('user-reports/{report}', [LguController::class, 'updateUserReport']);
    Route::get('seller-notices', [LguController::class, 'sellerNotices']);
    Route::patch('seller-notices/{notice}', [LguController::class, 'updateSellerNotice']);
    Route::get('users', [LguController::class, 'users']);
    Route::get('reviews', [PlatformController::class, 'lguReviews']);
    Route::delete('reviews/{review}', [LguController::class, 'destroyReview']);
    Route::delete('buyer-ratings/{rating}', [LguController::class, 'destroyBuyerRating']);
    Route::get('reports', [PlatformController::class, 'lguReports']);
    Route::get('reports/export', [PlatformController::class, 'exportLguReport']);
    Route::get('earnings', [LguController::class, 'pendingEarnings']);
    // Rejected-but-still-held transactions, and the way back out of a
    // rejection -- see LguController::reopenRejectedEarnings.
    Route::get('earnings/rejected', [LguController::class, 'rejectedEarnings']);
    Route::get('orders/{order:order_number}', [LguController::class, 'showOrder']);
    Route::patch('payments/{payment}/approve', [LguController::class, 'approveEarnings']);
    Route::patch('payments/{payment}/hold', [LguController::class, 'holdEarnings']);
    Route::patch('payments/{payment}/clear-hold', [LguController::class, 'clearHold']);
    Route::patch('payments/{payment}/reject', [LguController::class, 'rejectEarnings']);
    Route::patch('payments/{payment}/reopen', [LguController::class, 'reopenRejectedEarnings']);
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
    // Seller Registration Approval, stage 2 -- the final approval that makes a
    // seller verified and able to list (App\Support\SellerApproval).
    Route::get('seller-registrations', [SuperAdminController::class, 'sellerRegistrations']);
    Route::patch('sellers/{seller}/approve-registration', [SuperAdminController::class, 'approveSellerRegistration']);
    Route::patch('sellers/{seller}/reject-registration', [SuperAdminController::class, 'rejectSellerRegistration']);
    Route::patch('sellers/{seller}/suspend', [SuperAdminController::class, 'suspendSeller']);
    Route::patch('sellers/{seller}/reinstate', [SuperAdminController::class, 'reinstateSeller']);
    Route::delete('sellers/{seller}', [SuperAdminController::class, 'destroySeller']);
    // User Reports, platform-wide -- every municipality (App\Support\UserReports).
    Route::get('user-reports', [SuperAdminController::class, 'userReports']);
    Route::patch('user-reports/{report}', [SuperAdminController::class, 'updateUserReport']);
    Route::get('users', [SuperAdminController::class, 'users']);
    Route::patch('buyers/{user}/suspend', [SuperAdminController::class, 'suspendBuyer']);
    Route::patch('buyers/{user}/reinstate', [SuperAdminController::class, 'reinstateBuyer']);
    Route::delete('buyers/{user}', [SuperAdminController::class, 'destroyBuyer']);
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
