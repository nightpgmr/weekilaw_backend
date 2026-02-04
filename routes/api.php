<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\LawyerSearchController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\CacheController;
use App\Http\Controllers\Auth\PhoneAuthController;
use App\Http\Controllers\Auth\GoogleAuthController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Lawyer Search API
Route::post('/lawyers/search', [LawyerSearchController::class, 'search'])
    ->name('api.lawyers.search');

// Lawyer Verification API
Route::post('/lawyers/verify', [LawyerSearchController::class, 'verify'])
    ->name('api.lawyers.verify');
    
Route::post('/chat', [ChatController::class, 'ask']);

// AI Chat API (Public - no authentication required for chatting)
Route::post('/chat/ask', [ChatController::class, 'ask'])
    ->name('api.chat.ask');

Route::get('/chat/health', [ChatController::class, 'health'])
    ->name('api.chat.health');

// Chat Management Routes (Protected)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/chats/recent', [ChatController::class, 'getRecentChats'])
        ->name('api.chats.recent');

    Route::get('/chats/{chatId}', [ChatController::class, 'getChat'])
        ->name('api.chats.show');

    Route::put('/chats/{chatId}', [ChatController::class, 'updateChat'])
        ->name('api.chats.update');

    Route::delete('/chats/{chatId}', [ChatController::class, 'deleteChat'])
        ->name('api.chats.delete');
});

// Unified auth routes (used by frontend sign-in/sign-up)
Route::post('/auth/send-otp', [PhoneAuthController::class, 'sendOTP'])
    ->name('auth.send-otp');
Route::post('/auth/verify-otp', [PhoneAuthController::class, 'verifyOTP'])
    ->name('auth.verify-otp');

// Phone Authentication Routes (legacy)
Route::prefix('auth/phone')->group(function () {
    Route::post('/send-login-otp', [PhoneAuthController::class, 'sendLoginOTP'])
        ->name('auth.phone.send-login-otp');

    Route::post('/send-register-otp', [PhoneAuthController::class, 'sendRegisterOTP'])
        ->name('auth.phone.send-register-otp');

    Route::post('/verify-login-otp', [PhoneAuthController::class, 'verifyLoginOTP'])
        ->name('auth.phone.verify-login-otp');

    Route::post('/complete-registration', [PhoneAuthController::class, 'completeRegistration'])
        ->name('auth.phone.complete-registration');

    Route::post('/resend-otp', [PhoneAuthController::class, 'resendOTP'])
        ->name('auth.phone.resend-otp');
});

// Google Authentication Routes
Route::prefix('auth/google')->group(function () {
    Route::get('/url', [GoogleAuthController::class, 'getGoogleAuthUrl'])
        ->name('auth.google.url');

    Route::get('/callback', [GoogleAuthController::class, 'handleGoogleCallback'])
        ->name('auth.google.callback');
});

// Auth Profile Route (used by frontend)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/profile', [UserController::class, 'getProfile'])
        ->name('api.auth.profile');
});

// User Profile Routes (Protected)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user/profile', [UserController::class, 'getProfile'])
        ->name('api.user.profile');

    Route::put('/user/profile', [UserController::class, 'updateProfile'])
        ->name('api.user.update-profile');

    Route::delete('/user/account', [UserController::class, 'deleteAccount'])
        ->name('api.user.delete-account');
});

// Payment Routes (Public)
// Gateway POSTs to /api/payment/listener (backend route)
// Backend receives POST, returns HTML page with JavaScript that verifies payment
// JavaScript verifies payment via /api/payment/verify-callback and sends postMessage to opener

// Payment listener route (receives POST from gateway, returns HTML page, no auth required)
Route::post('/payment/listener', [PaymentController::class, 'listener'])
    ->name('api.payment.listener');

// Payment listener route (Node.js compatible path - same as weekila-server-iran)
Route::post('/payment/payment-listener', [PaymentController::class, 'listener'])
    ->name('api.payment.payment-listener');

// Gateway redirect route (generates payment form HTML from token, no auth required)
Route::get('/payment/gateway-redirect', [PaymentController::class, 'gatewayRedirect'])
    ->name('api.payment.gateway-redirect');

// Payment verify callback route (called by listener HTML JavaScript, no auth required)
Route::post('/payment/verify-callback', [PaymentController::class, 'verifyCallback'])
    ->name('api.payment.verify-callback');

// Payment Routes (Protected)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/payment/gateways', [PaymentController::class, 'getGateways'])
        ->name('api.payment.gateways');
    
    Route::get('/payment/coin-packages', [PaymentController::class, 'getCoinPackages'])
        ->name('api.payment.coin-packages');
    
    Route::post('/payment/initiate', [PaymentController::class, 'initiate'])
        ->name('api.payment.initiate');
});

// Payment verify route (called by listener page, no auth required)
Route::post('/payment/verify', [PaymentController::class, 'verify'])
    ->name('api.payment.verify');

// Cache Control Routes (Admin/Backend)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/cache/stats', [CacheController::class, 'stats'])
        ->name('api.cache.stats');
    
    Route::post('/cache/invalidate', [CacheController::class, 'invalidate'])
        ->name('api.cache.invalidate');
    
    Route::post('/cache/clear', [CacheController::class, 'clear'])
        ->name('api.cache.clear');
});

// Wallet Routes (Legacy - kept for backward compatibility)
// SEP Bank sends callback as POST with form data
Route::post('/wallet/callback', [WalletController::class, 'callback'])
    ->name('api.wallet.callback');

// SEP Payment callback - same path as Node.js server (registered in SEP panel)
Route::post('/payment/payment-listener', [WalletController::class, 'callback'])
    ->name('api.payment.listener.legacy');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/wallet/balance', [WalletController::class, 'getBalance'])
        ->name('api.wallet.balance');

    Route::post('/wallet/add-money', [WalletController::class, 'addMoney'])
        ->name('api.wallet.add-money');

    Route::post('/wallet/add-money-government', [WalletController::class, 'addMoneyGovernment'])
        ->name('api.wallet.add-money-government');

    Route::get('/wallet/transactions', [WalletController::class, 'getTransactions'])
        ->name('api.wallet.transactions');
});

// Health check endpoint for CI/CD
Route::get('/health', function () {
    try {
        // Test database connection
        $dbStatus = \DB::connection()->getPdo() ? 'OK' : 'ERROR';

        // Test cache
        $cacheStatus = 'ERROR';
        try {
            cache()->put('health_test', 'ok', 10);
            $cacheStatus = cache()->get('health_test') === 'ok' ? 'OK' : 'ERROR';
        } catch (\Exception $e) {
            $cacheStatus = 'ERROR';
        }

        return response()->json([
            'status' => 'healthy',
            'database' => $dbStatus,
            'cache' => $cacheStatus,
            'timestamp' => now()->toISOString()
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'unhealthy',
            'error' => $e->getMessage()
        ], 500);
    }
});

// Under Construction Status Endpoint
Route::get('/under-construction', function () {
    try {
        $flag = \App\Models\FeatureFlag::where('key', 'under_construction')->first();
        return response()->json([
            'enabled' => $flag ? $flag->enabled : false,
        ]);
    } catch (\Exception $e) {
        // If database is not available, assume site is live
        return response()->json(['enabled' => false]);
    }
});

// Test cache endpoint
Route::post("/test-cache", function(Request $request) {
    $phone = $request->input("phone", "09123456789");
    $key = "register_otp_" . $phone;

    // Store OTP
    cache()->put($key, [
        "otp" => Illuminate\Support\Facades\Hash::make("12345"),
        "expires_at" => Carbon\Carbon::now()->addMinutes(5)
    ], 5);

    // Retrieve and check
    $data = cache()->get($key);
    $valid = $data && Illuminate\Support\Facades\Hash::check("12345", $data["otp"]);

    return response()->json([
        "success" => true,
        "message" => "Cache test",
        "key" => $key,
        "stored" => !!$data,
        "valid" => $valid
    ]);
});

