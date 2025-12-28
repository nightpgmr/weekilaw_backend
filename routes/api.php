<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\LawyerSearchController;
use App\Http\Controllers\Api\ChatController;

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

// AI Chat API
Route::post('/chat/ask', [ChatController::class, 'ask'])
    ->name('api.chat.ask');

Route::get('/chat/health', [ChatController::class, 'health'])
    ->name('api.chat.health');
