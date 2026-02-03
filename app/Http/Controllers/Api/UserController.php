<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ExternalAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    protected ExternalAuthService $authService;

    public function __construct(ExternalAuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Update user profile
     * Note: This may need to be updated if the external API has a profile update endpoint
     */
    public function updateProfile(Request $request)
    {
        // For now, return error as profile update may need to be handled by external API
        // TODO: Implement if external API provides profile update endpoint
            return response()->json([
                'success' => false,
            'message' => 'Profile update not yet implemented with new API',
        ], 501);
    }

    /**
     * Delete user account
     * Note: This may need to be updated if the external API has an account deletion endpoint
     */
    public function deleteAccount(Request $request)
    {
        // For now, return error as account deletion may need to be handled by external API
        // TODO: Implement if external API provides account deletion endpoint
                    return response()->json([
                        'success' => false,
            'message' => 'Account deletion not yet implemented with new API',
        ], 501);
    }

    /**
     * Get user profile from external API
     */
    public function getProfile(Request $request)
    {
        try {
            // Get access token from request
            $token = $request->bearerToken();
            
            // Also check Authorization header directly as fallback
            if (!$token) {
                $authHeader = $request->header('Authorization');
                if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
                    $token = substr($authHeader, 7);
                }
            }

            if (!$token) {
                \Log::warning('Profile request without token', [
                    'has_authorization_header' => $request->hasHeader('Authorization'),
                    'authorization_header_preview' => $request->hasHeader('Authorization') 
                        ? substr($request->header('Authorization'), 0, 30) . '...' 
                        : 'N/A',
                    'ip' => $request->ip(),
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'توکن احراز هویت مورد نیاز است',
                    'code' => 'TOKEN_REQUIRED',
                ], 401);
            }
            
            \Log::info('Profile request received', [
                'has_token' => true,
                'token_length' => strlen($token),
                'token_preview' => substr($token, 0, 20) . '...',
            ]);

            // Call external API to get profile
            $result = $this->authService->getProfile($token);

            if ($result['success'] && isset($result['data']['success']) && $result['data']['success']) {
                \Log::info('Profile retrieved successfully from external API');
                return response()->json($result['data']);
            } else {
                \Log::warning('Failed to get profile from external API', [
                    'status' => $result['status'] ?? 'unknown',
                    'message' => $result['data']['message'] ?? 'Unknown error',
                    'code' => $result['data']['code'] ?? null,
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => $result['data']['message'] ?? 'خطا در دریافت پروفایل',
                    'code' => $result['data']['code'] ?? null,
                ], $result['status'] ?? 500);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت پروفایل',
                'error' => $e->getMessage()
            ], 500);
        }
    }

}
