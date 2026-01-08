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

// Phone Authentication Routes
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
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user/profile', [UserController::class, 'getProfile'])
        ->name('api.user.profile');

    Route::put('/user/profile', [UserController::class, 'updateProfile'])
        ->name('api.user.update-profile');

    Route::delete('/user/account', [UserController::class, 'deleteAccount'])
        ->name('api.user.delete-account');
});

// Wallet Routes
Route::get('/wallet/callback', [WalletController::class, 'callback'])
    ->name('api.wallet.callback');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/wallet/balance', [WalletController::class, 'getBalance'])
        ->name('api.wallet.balance');

    Route::post('/wallet/add-money', [WalletController::class, 'addMoney'])
        ->name('api.wallet.add-money');

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

