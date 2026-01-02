<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function testGoogleEnv(): JsonResponse
    {
        return response()->json([
            'GOOGLE_CLIENT_ID' => env('GOOGLE_CLIENT_ID'),
            'GOOGLE_CLIENT_SECRET' => substr(env('GOOGLE_CLIENT_SECRET'), 0, 10) . '...',
            'GOOGLE_REDIRECT_URI' => env('GOOGLE_REDIRECT_URI'),
            'config_google' => config('services.google'),
        ]);
    }
}
