<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    /**
     * Redirect to Google OAuth
     */
    public function redirectToGoogle(): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    /**
     * Handle Google OAuth callback
     */
    public function handleGoogleCallback(): JsonResponse
    {
        try {
            $googleUser = Socialite::driver('google')->user();

            // Check if user already exists with this Google ID
            $user = User::where('provider_id', $googleUser->getId())
                       ->where('auth_provider', 'google')
                       ->first();

            if (!$user) {
                // Check if user exists with same email
                $existingUser = User::where('email', $googleUser->getEmail())->first();

                if ($existingUser) {
                    // Link Google account to existing user
                    $existingUser->update([
                        'auth_provider' => 'google',
                        'provider_id' => $googleUser->getId(),
                        'email_verified_at' => now(),
                    ]);
                    $user = $existingUser;
                } else {
                    // Create new user
                    $user = User::create([
                        'name' => $googleUser->getName(),
                        'email' => $googleUser->getEmail(),
                        'password' => Hash::make(Str::random(32)), // Random password since OAuth
                        'auth_provider' => 'google',
                        'provider_id' => $googleUser->getId(),
                        'email_verified_at' => now(),
                        'role' => 'client',
                    ]);
                }
            }

            // Login the user
            Auth::login($user);

            return response()->json([
                'success' => true,
                'message' => 'ورود با گوگل با موفقیت انجام شد',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در ورود با گوگل',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get Google OAuth URL for frontend
     */
    public function getGoogleAuthUrl(): JsonResponse
    {
        try {
            $clientId = env('GOOGLE_CLIENT_ID');
            $redirectUri = env('GOOGLE_REDIRECT_URI');

            $params = [
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'scope' => 'openid profile email',
                'response_type' => 'code',
                'access_type' => 'offline',
                'prompt' => 'consent',
            ];

            $url = 'https://accounts.google.com/o/oauth2/auth?' . http_build_query($params);

            return response()->json([
                'success' => true,
                'auth_url' => $url,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت لینک گوگل',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
