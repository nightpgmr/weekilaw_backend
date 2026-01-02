<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\LawyerSearchController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\UserController;
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

// AI Chat API (Protected - requires authentication)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/chat/ask', [ChatController::class, 'ask'])
        ->name('api.chat.ask');
});

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

