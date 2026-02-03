<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\LawyerSearchController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WalletController;
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

// AI Chat Streaming API (Server-Sent Events) - Keep existing stream
Route::post('/chat/ask-stream', [ChatController::class, 'askStream'])
    ->name('api.chat.ask-stream');

Route::get('/chat/health', [ChatController::class, 'health'])
    ->name('api.chat.health');

// Test streaming endpoint (for debugging)
Route::get('/chat/test-stream', [ChatController::class, 'testStream'])
    ->name('api.chat.test-stream');

// New Chat API endpoints from Postman collection
Route::post('/chat/sessions', [ChatController::class, 'createSession'])
    ->name('api.chat.sessions.create');

Route::post('/chat/ai-message', [ChatController::class, 'sendAiMessage'])
    ->name('api.chat.ai-message');

Route::get('/chat/sessions/{phonenumber}', [ChatController::class, 'getSessionsByPhone'])
    ->name('api.chat.sessions.by-phone');

Route::get('/chat/sessions/{sessionId}/messages', [ChatController::class, 'getSessionMessages'])
    ->name('api.chat.sessions.messages');

Route::post('/chat/ai-chat', [ChatController::class, 'aiChat'])
    ->name('api.chat.ai-chat');

Route::get('/chat/models', [ChatController::class, 'getModels'])
    ->name('api.chat.models');

// Chat Management Routes (Protected - uses JWT token validation, not Sanctum)
Route::get('/chats/recent', [ChatController::class, 'getRecentChats'])
    ->name('api.chats.recent');

Route::get('/chats/{chatId}', [ChatController::class, 'getChat'])
    ->name('api.chats.show');

Route::put('/chats/{chatId}', [ChatController::class, 'updateChat'])
    ->name('api.chats.update');

Route::delete('/chats/{chatId}', [ChatController::class, 'deleteChat'])
    ->name('api.chats.delete');

// New Authentication Routes (using external API)
Route::prefix('auth')->group(function () {
    // Send OTP (for both login and registration)
    Route::post('/send-otp', [PhoneAuthController::class, 'sendLoginOTP'])
        ->name('auth.send-otp');

    // Verify OTP
    Route::post('/verify-otp', [PhoneAuthController::class, 'verifyLoginOTP'])
        ->name('auth.verify-otp');

    // Login with phone and password
    Route::post('/login', [PhoneAuthController::class, 'login'])
        ->name('auth.login');

    // Refresh token
    Route::post('/refresh', [PhoneAuthController::class, 'refreshToken'])
        ->name('auth.refresh');

    // Get profile (protected - uses JWT token from external API, not Sanctum)
    // The controller handles token validation manually
    Route::get('/profile', [UserController::class, 'getProfile'])
        ->name('auth.profile');
});

// Legacy Phone Authentication Routes (kept for backward compatibility)
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

// User Profile Routes (Protected)
// Note: /auth/profile is now in the auth routes above
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user/profile', [UserController::class, 'getProfile'])
        ->name('api.user.profile');

    Route::put('/user/profile', [UserController::class, 'updateProfile'])
        ->name('api.user.update-profile');

    Route::delete('/user/account', [UserController::class, 'deleteAccount'])
        ->name('api.user.delete-account');
});

// Wallet Routes
// SEP payment gateway may use GET or POST for callbacks
Route::match(['get', 'post'], '/wallet/callback', [WalletController::class, 'callback'])
    ->name('api.wallet.callback');

// Wallet callback processor (for proxy use - returns JSON instead of redirect)
Route::get('/wallet/process-callback', [WalletController::class, 'processCallback'])
    ->name('api.wallet.process-callback');

// Test endpoint to check callback URL configuration
Route::get('/wallet/test-callback-url', function () {
    $callbackBaseUrl = config('services.sep.callback_url') 
        ? rtrim(config('services.sep.callback_url'), '/')
        : rtrim(config('app.url'), '/');
    
    $useDomainOnly = config('services.sep.callback_domain_only', false);
    
    if (config('services.sep.callback_url')) {
        if ($useDomainOnly) {
            $callbackUrl = $callbackBaseUrl;
        } else {
            $callbackPath = config('services.sep.callback_path', '/api/payment/payment-listener');
            $callbackPath = '/' . ltrim($callbackPath, '/');
            $callbackUrl = $callbackBaseUrl . $callbackPath;
        }
    } else {
        $callbackUrl = $callbackBaseUrl . '/api/wallet/callback';
    }
    
    $callbackUrl = rtrim($callbackUrl, '/');
    if (!str_starts_with($callbackUrl, 'http')) {
        $callbackUrl = 'https://' . ltrim($callbackUrl, '/');
    }
    
    return response()->json([
        'callback_url_being_sent' => $callbackUrl,
        'expected_full_url' => 'https://payment.weekilaw.com/api/payment/payment-listener',
        'expected_domain_only' => 'https://payment.weekilaw.com',
        'matches_full_url' => $callbackUrl === 'https://payment.weekilaw.com/api/payment/payment-listener',
        'matches_domain_only' => $callbackUrl === 'https://payment.weekilaw.com',
        'config' => [
            'sep_callback_url' => config('services.sep.callback_url'),
            'sep_callback_path' => config('services.sep.callback_path'),
            'sep_callback_domain_only' => config('services.sep.callback_domain_only', false),
            'app_url' => config('app.url'),
        ],
        'troubleshooting' => [
            'if_matches_full_url' => 'URL format is correct. Check SEP panel to ensure exact URL is whitelisted.',
            'if_matches_domain_only' => 'Using domain-only callback. Make sure domain is whitelisted in SEP.',
            'if_neither_matches' => 'URL format mismatch. Check your .env configuration.',
        ]
    ]);
})->name('api.wallet.test-callback-url');

// Wallet Routes (Protected - uses JWT token validation in controller)
Route::get('/wallet/balance', [WalletController::class, 'getBalance'])
    ->name('api.wallet.balance');

Route::post('/wallet/add-money', [WalletController::class, 'addMoney'])
    ->name('api.wallet.add-money');

Route::post('/wallet/add-money-government', [WalletController::class, 'addMoneyGovernment'])
    ->name('api.wallet.add-money-government');

Route::get('/wallet/transactions', [WalletController::class, 'getTransactions'])
    ->name('api.wallet.transactions');

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

