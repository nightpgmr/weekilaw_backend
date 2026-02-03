<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\ExternalAuthService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class PhoneAuthController extends Controller
{
    protected ExternalAuthService $authService;

    public function __construct(ExternalAuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Send OTP for login
     */
    public function sendLoginOTP(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|regex:/^09\d{9}$/',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'شماره موبایل نامعتبر است',
                'errors' => $validator->errors(),
            ], 422);
        }

        $phone = $request->input('phone');

        // Call external API to send OTP
        $result = $this->authService->sendOTP($phone);

        // Log full response for debugging
        \Log::info('[OTP] External API response (login)', [
            'phone' => substr($phone, 0, 4) . '****',
            'success' => $result['success'] ?? false,
            'status' => $result['status'] ?? null,
            'response_data' => $result['data'] ?? null,
            'has_code' => isset($result['data']['code']),
            'has_dev_otp' => isset($result['data']['dev_otp']),
            'has_otp' => isset($result['data']['otp']),
        ]);

        if ($result['success'] && isset($result['data']['success']) && $result['data']['success']) {
            $response = [
                'success' => true,
                'message' => $result['data']['message'] ?? 'کد تایید به شماره موبایل شما ارسال شد',
                'expires_in' => $result['data']['expires_in'] ?? 120,
            ];

            // Include OTP code if provided (check multiple possible fields)
            $otpCode = $result['data']['code'] ?? $result['data']['dev_otp'] ?? $result['data']['otp'] ?? null;
            
            if ($otpCode) {
                $response['code'] = $otpCode;
                \Log::info('[OTP] ✅ OTP Code found and logged (login)', [
                    'otp_code' => $otpCode,
                    'phone' => substr($phone, 0, 4) . '****',
                    'source_field' => isset($result['data']['code']) ? 'code' : (isset($result['data']['dev_otp']) ? 'dev_otp' : 'otp'),
                ]);
            } else {
                \Log::warning('[OTP] ⚠️ No OTP code found in response (login)', [
                    'phone' => substr($phone, 0, 4) . '****',
                    'available_keys' => array_keys($result['data'] ?? []),
                ]);
            }

            return response()->json($response);
        } else {
            return response()->json([
                'success' => false,
                'message' => $result['data']['message'] ?? 'خطا در ارسال کد تایید',
                'error_code' => $result['data']['error_code'] ?? null,
            ], $result['status'] ?? 500);
        }
    }

    /**
     * Send OTP for registration
     */
    public function sendRegisterOTP(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|regex:/^09\d{9}$/',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'شماره موبایل نامعتبر است',
                'errors' => $validator->errors(),
            ], 422);
        }

        $phone = $request->input('phone');

        // Call external API to send OTP (same endpoint for both login and registration)
        $result = $this->authService->sendOTP($phone);

        // Log full response for debugging
        \Log::info('[OTP] External API response (register)', [
            'phone' => substr($phone, 0, 4) . '****',
            'success' => $result['success'] ?? false,
            'status' => $result['status'] ?? null,
            'response_data' => $result['data'] ?? null,
            'has_code' => isset($result['data']['code']),
            'has_dev_otp' => isset($result['data']['dev_otp']),
            'has_otp' => isset($result['data']['otp']),
        ]);

        if ($result['success'] && isset($result['data']['success']) && $result['data']['success']) {
            $response = [
                'success' => true,
                'message' => $result['data']['message'] ?? 'کد تایید به شماره موبایل شما ارسال شد',
                'expires_in' => $result['data']['expires_in'] ?? 120,
            ];

            // Include OTP code if provided (check multiple possible fields)
            $otpCode = $result['data']['code'] ?? $result['data']['dev_otp'] ?? $result['data']['otp'] ?? null;
            
            if ($otpCode) {
                $response['code'] = $otpCode;
                \Log::info('[OTP] ✅ OTP Code found and logged (register)', [
                    'otp_code' => $otpCode,
                    'phone' => substr($phone, 0, 4) . '****',
                    'source_field' => isset($result['data']['code']) ? 'code' : (isset($result['data']['dev_otp']) ? 'dev_otp' : 'otp'),
                ]);
            } else {
                \Log::warning('[OTP] ⚠️ No OTP code found in response (register)', [
                    'phone' => substr($phone, 0, 4) . '****',
                    'available_keys' => array_keys($result['data'] ?? []),
                ]);
            }

            return response()->json($response);
        } else {
            return response()->json([
                'success' => false,
                'message' => $result['data']['message'] ?? 'خطا در ارسال کد تایید',
                'error_code' => $result['data']['error_code'] ?? null,
            ], $result['status'] ?? 500);
        }
    }

    /**
     * Verify OTP and login
     */
    public function verifyLoginOTP(Request $request): JsonResponse
    {
        // Accept both 'otp' and 'otp_code' for backward compatibility
        $otpValue = $request->input('otp_code') ?: $request->input('otp');
        
        $validator = Validator::make(array_merge($request->all(), ['otp_value' => $otpValue]), [
            'phone' => 'required|string|regex:/^09\d{9}$/',
            'otp_value' => 'required|string|regex:/^\d{4,6}$/',
            'user_type' => 'nullable|string|in:regular,lawyer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'اطلاعات وارد شده نامعتبر است',
                'errors' => $validator->errors(),
            ], 422);
        }

        $phone = $request->input('phone');
        $otp = $otpValue;
        $userType = $request->input('user_type', 'regular'); // Default to 'regular' if not provided

        // Call external API to verify OTP
        $result = $this->authService->verifyOTP($phone, $otp, $userType);

        if ($result['success'] && isset($result['data']['success']) && $result['data']['success']) {
            $response = [
                'success' => true,
                'message' => $result['data']['message'] ?? 'کد تایید تایید شد',
            ];

            // If access_token is provided, user is fully authenticated
            if (isset($result['data']['access_token'])) {
                $response['access_token'] = $result['data']['access_token'];
                $response['refresh_token'] = $result['data']['refresh_token'] ?? null;
                $response['token'] = $result['data']['access_token']; // Keep for backward compatibility
                
                if (isset($result['data']['user'])) {
                    $response['user'] = $result['data']['user'];
                }
                
                // Log token for debugging (only log first/last few chars for security)
                \Log::info('OTP verification successful - Token returned', [
                    'phone' => substr($phone, 0, 4) . '****',
                    'has_access_token' => true,
                    'token_length' => strlen($result['data']['access_token']),
                    'token_preview' => substr($result['data']['access_token'], 0, 20) . '...',
                    'has_user' => isset($result['data']['user']),
                ]);
            } elseif (isset($result['data']['preauth_token'])) {
                // User needs to complete registration
                $response['preauth_token'] = $result['data']['preauth_token'];
                $response['message'] = $result['data']['message'] ?? 'کد تایید تایید شد - ادامه ثبت‌نام لازم است';
                
                \Log::info('OTP verification - Preauth token returned (registration needed)', [
                    'phone' => substr($phone, 0, 4) . '****',
                ]);
            } else {
                \Log::warning('OTP verification successful but no token returned', [
                    'phone' => substr($phone, 0, 4) . '****',
                    'result_data' => array_keys($result['data'] ?? []),
                ]);
            }

            return response()->json($response);
        } else {
            return response()->json([
                'success' => false,
                'message' => $result['data']['message'] ?? 'کد تایید اشتباه است',
                'error_code' => $result['data']['error_code'] ?? null,
            ], $result['status'] ?? 400);
        }
    }

    /**
     * Complete registration with OTP verification
     * Note: This method now uses the external API's verify-otp endpoint
     * The registration completion is handled by the external API
     */
    public function completeRegistration(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|regex:/^09\d{9}$/',
            'otp' => 'required|string|regex:/^\d{4,6}$/',
            'name' => 'required|string|min:2|max:255',
            'user_type' => 'nullable|string|in:regular,lawyer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'اطلاعات وارد شده نامعتبر است',
                'errors' => $validator->errors(),
            ], 422);
        }

        $phone = $request->input('phone');
        $otp = $request->input('otp');
        $userType = $request->input('user_type', 'regular');

        // Call external API to verify OTP (this handles registration completion)
        $result = $this->authService->verifyOTP($phone, $otp, $userType);

        if ($result['success'] && isset($result['data']['success']) && $result['data']['success']) {
            $response = [
                'success' => true,
                'message' => $result['data']['message'] ?? 'ثبت نام با موفقیت انجام شد',
            ];

            // If access_token is provided, registration is complete
            if (isset($result['data']['access_token'])) {
                $response['access_token'] = $result['data']['access_token'];
                $response['refresh_token'] = $result['data']['refresh_token'] ?? null;
                $response['token'] = $result['data']['access_token']; // Keep for backward compatibility
                
                if (isset($result['data']['user'])) {
                    $response['user'] = $result['data']['user'];
                }
            } elseif (isset($result['data']['preauth_token'])) {
                // Still need to complete registration
                $response['preauth_token'] = $result['data']['preauth_token'];
            }

            return response()->json($response);
        } else {
            return response()->json([
                'success' => false,
                'message' => $result['data']['message'] ?? 'خطا در تکمیل ثبت نام',
                'error_code' => $result['data']['error_code'] ?? null,
            ], $result['status'] ?? 400);
        }
    }

    /**
     * Resend OTP
     */
    public function resendOTP(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|regex:/^09\d{9}$/',
            'type' => 'required|in:login,register',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'اطلاعات وارد شده نامعتبر است',
                'errors' => $validator->errors(),
            ], 422);
        }

        $phone = $request->input('phone');

        // Call external API to resend OTP (same endpoint as send-otp)
        $result = $this->authService->sendOTP($phone);

        // Log full response for debugging
        \Log::info('[OTP] External API response (resend)', [
            'phone' => substr($phone, 0, 4) . '****',
            'success' => $result['success'] ?? false,
            'status' => $result['status'] ?? null,
            'response_data' => $result['data'] ?? null,
            'has_code' => isset($result['data']['code']),
            'has_dev_otp' => isset($result['data']['dev_otp']),
            'has_otp' => isset($result['data']['otp']),
        ]);

        if ($result['success'] && isset($result['data']['success']) && $result['data']['success']) {
            $response = [
                'success' => true,
                'message' => $result['data']['message'] ?? 'کد تایید جدید ارسال شد',
                'expires_in' => $result['data']['expires_in'] ?? 120,
            ];

            // Include OTP code if provided (check multiple possible fields)
            $otpCode = $result['data']['code'] ?? $result['data']['dev_otp'] ?? $result['data']['otp'] ?? null;
            
            if ($otpCode) {
                $response['code'] = $otpCode;
                \Log::info('[OTP] ✅ OTP Code found and logged (resend)', [
                    'otp_code' => $otpCode,
                    'phone' => substr($phone, 0, 4) . '****',
                    'source_field' => isset($result['data']['code']) ? 'code' : (isset($result['data']['dev_otp']) ? 'dev_otp' : 'otp'),
                ]);
            } else {
                \Log::warning('[OTP] ⚠️ No OTP code found in response (resend)', [
                    'phone' => substr($phone, 0, 4) . '****',
                    'available_keys' => array_keys($result['data'] ?? []),
                ]);
            }

            return response()->json($response);
        } else {
            return response()->json([
                'success' => false,
                'message' => $result['data']['message'] ?? 'خطا در ارسال کد تایید',
                'error_code' => $result['data']['error_code'] ?? null,
            ], $result['status'] ?? 500);
        }
    }

    /**
     * Login with phone and password
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|regex:/^09\d{9}$/',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'اطلاعات وارد شده نامعتبر است',
                'errors' => $validator->errors(),
            ], 422);
        }

        $phone = $request->input('phone');
        $password = $request->input('password');

        // Call external API to login
        $result = $this->authService->login($phone, $password);

        if ($result['success'] && isset($result['data']['success']) && $result['data']['success']) {
            $response = [
                'success' => true,
                'message' => $result['data']['message'] ?? 'ورود با موفقیت انجام شد',
            ];

            if (isset($result['data']['access_token'])) {
                $response['access_token'] = $result['data']['access_token'];
                $response['refresh_token'] = $result['data']['refresh_token'] ?? null;
                $response['token'] = $result['data']['access_token']; // Keep for backward compatibility
            }

            if (isset($result['data']['user'])) {
                $response['user'] = $result['data']['user'];
            }

            return response()->json($response);
        } else {
            return response()->json([
                'success' => false,
                'message' => $result['data']['message'] ?? 'خطا در ورود',
                'error_code' => $result['data']['error_code'] ?? null,
            ], $result['status'] ?? 400);
        }
    }

    /**
     * Refresh access token
     */
    public function refreshToken(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'refresh_token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Refresh token مورد نیاز است',
                'code' => 'REFRESH_TOKEN_REQUIRED',
            ], 400);
        }

        $refreshToken = $request->input('refresh_token');

        // Call external API to refresh token
        $result = $this->authService->refreshToken($refreshToken);

        if ($result['success'] && isset($result['data']['success']) && $result['data']['success']) {
            $response = [
                'success' => true,
                'message' => $result['data']['message'] ?? 'توکن با موفقیت تازه شد',
            ];

            if (isset($result['data']['access_token'])) {
                $response['access_token'] = $result['data']['access_token'];
                $response['refresh_token'] = $result['data']['refresh_token'] ?? null;
            }

            return response()->json($response);
        } else {
            return response()->json([
                'success' => false,
                'message' => $result['data']['message'] ?? 'خطا در تازه‌سازی توکن',
                'code' => $result['data']['code'] ?? null,
            ], $result['status'] ?? 400);
        }
    }
}
