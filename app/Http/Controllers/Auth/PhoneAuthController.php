<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OTPService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Carbon\Carbon;

class PhoneAuthController extends Controller
{
    protected OTPService $otpService;

    public function __construct(OTPService $otpService)
    {
        $this->otpService = $otpService;
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

        // Check if user exists with this phone and has completed registration
        $user = User::where('phone', $phone)
            ->whereNotNull('name')
            ->where('name', '!=', 'کاربر جدید')
            ->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'کاربری با این شماره موبایل یافت نشد. لطفا ابتدا ثبت نام کنید.',
            ], 404);
        }

        // Generate OTP
        $otpCode = $this->otpService->generateOTP();
        $expiresAt = Carbon::now()->addMinutes(5);

        // Save OTP to user
        $user->update([
            'otp_code' => Hash::make($otpCode),
            'otp_expires_at' => $expiresAt,
        ]);

        // Send OTP via SMS
        $result = $this->otpService->sendOTP($phone, $otpCode);

        if ($result['success']) {
            $response = [
                'success' => true,
                'message' => 'کد تایید به شماره موبایل شما ارسال شد',
                'expires_in' => 300, // 5 minutes
            ];

            // In development mode, include the OTP for testing
            if (config('app.env') !== 'production' && isset($result['dev_otp'])) {
                $response['dev_otp'] = $result['dev_otp'];
            }

            return response()->json($response);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'خطا در ارسال کد تایید',
                'error' => $result['error'] ?? 'Unknown error',
            ], 500);
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

        // Check if user already exists and is fully registered (has a proper name)
        $existingUser = User::where('phone', $phone)->first();
        if ($existingUser && $existingUser->name !== 'کاربر جدید' && $existingUser->name !== null) {
            return response()->json([
                'success' => false,
                'message' => 'این شماره موبایل قبلاً ثبت شده است. لطفا وارد شوید.',
            ], 422);
        }

        // Generate OTP
        $otpCode = $this->otpService->generateOTP();
        $expiresAt = Carbon::now()->addMinutes(5);

        // Store OTP temporarily (we'll create the user only after OTP verification)
        // For now, we'll use a temporary storage or check if user exists
        $tempOtpKey = 'register_otp_' . $phone;
        cache()->put($tempOtpKey, [
            'otp' => Hash::make($otpCode),
            'expires_at' => $expiresAt,
        ], 300); // 5 minutes

        // If user doesn't exist, we'll create them during completeRegistration
        // If they exist but haven't completed registration, we'll update them

        // Send OTP via SMS
        $result = $this->otpService->sendOTP($phone, $otpCode);

        if ($result['success']) {
            $response = [
                'success' => true,
                'message' => 'کد تایید به شماره موبایل شما ارسال شد',
                'expires_in' => 300, // 5 minutes
            ];

            // In development mode, include the OTP for testing
            if (config('app.env') !== 'production' && isset($result['dev_otp'])) {
                $response['dev_otp'] = $result['dev_otp'];
            }

            return response()->json($response);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'خطا در ارسال کد تایید',
                'error' => $result['error'] ?? 'Unknown error',
            ], 500);
        }
    }

    /**
     * Verify OTP and login
     */
    public function verifyLoginOTP(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|regex:/^09\d{9}$/',
            'otp' => 'required|string|regex:/^\d{4}$/',
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

        $user = User::where('phone', $phone)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'کاربری با این شماره موبایل یافت نشد',
            ], 404);
        }

        // Check if OTP is expired
        if ($user->otp_expires_at && $user->otp_expires_at->isPast()) {
            return response()->json([
                'success' => false,
                'message' => 'کد تایید منقضی شده است',
            ], 422);
        }

        // Verify OTP
        if (!$user->otp_code || !Hash::check($otp, $user->otp_code)) {
            return response()->json([
                'success' => false,
                'message' => 'کد تایید اشتباه است',
            ], 422);
        }

        // Clear OTP and mark phone as verified
        $user->update([
            'otp_code' => null,
            'otp_expires_at' => null,
            'phone_verified_at' => Carbon::now(),
        ]);

        // Create Sanctum token for API access
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'ورود با موفقیت انجام شد',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'role' => $user->role,
            ],
        ]);
    }

    /**
     * Complete registration with OTP verification
     */
    public function completeRegistration(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|regex:/^09\d{9}$/',
            'otp' => 'required|string|regex:/^\d{4}$/',
            'name' => 'required|string|min:2|max:255',
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
        $name = $request->input('name');

        // Get OTP from cache
        $tempOtpKey = 'register_otp_' . $phone;
        $cachedOtpData = cache()->get($tempOtpKey);

        if (!$cachedOtpData) {
            return response()->json([
                'success' => false,
                'message' => 'کد تایید منقضی شده است یا یافت نشد. لطفا دوباره درخواست دهید.',
            ], 422);
        }

        // Check if OTP is expired
        if (Carbon::parse($cachedOtpData['expires_at'])->isPast()) {
            cache()->forget($tempOtpKey);
            return response()->json([
                'success' => false,
                'message' => 'کد تایید منقضی شده است',
            ], 422);
        }

        // Verify OTP
        if (!Hash::check($otp, $cachedOtpData['otp'])) {
            return response()->json([
                'success' => false,
                'message' => 'کد تایید اشتباه است',
            ], 422);
        }

        // Clear the cached OTP
        cache()->forget($tempOtpKey);

        // Find or create user
        $user = User::firstOrCreate(
            ['phone' => $phone],
            [
                'name' => $name,
                'password' => Hash::make(Str::random(32)),
                'auth_provider' => 'phone',
                'phone_verified_at' => Carbon::now(),
                'email_verified_at' => Carbon::now(), // Mark as verified since phone is verified
            ]
        );

        // If user already existed but hadn't completed registration, update their info
        if (!$user->wasRecentlyCreated) {
            $user->update([
                'name' => $name,
                'phone_verified_at' => Carbon::now(),
                'email_verified_at' => Carbon::now(),
            ]);
        }

        // Create Sanctum token for API access
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'ثبت نام با موفقیت انجام شد',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'role' => $user->role,
            ],
        ]);
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
        $type = $request->input('type');

        if ($type === 'login') {
            // For login, check if user exists and has completed registration
            $user = User::where('phone', $phone)
                ->whereNotNull('name')
                ->where('name', '!=', 'کاربر جدید')
                ->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'کاربری با این شماره موبایل یافت نشد',
                ], 404);
            }

            // Check if we can resend (minimum 1 minute between requests)
            $lastSent = $user->otp_expires_at ? $user->otp_expires_at->subMinutes(5) : null;
            if ($lastSent && $lastSent->addMinutes(1)->isFuture()) {
                return response()->json([
                    'success' => false,
                    'message' => 'لطفاً ۱ دقیقه صبر کنید قبل از ارسال مجدد کد',
                ], 429);
            }
        } else {
            // For register, check cached OTP timing
            $tempOtpKey = 'register_otp_' . $phone;
            $cachedOtpData = cache()->get($tempOtpKey);

            if ($cachedOtpData) {
                $lastSent = Carbon::parse($cachedOtpData['expires_at'])->subMinutes(5);
                if ($lastSent->addMinutes(1)->isFuture()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'لطفاً ۱ دقیقه صبر کنید قبل از ارسال مجدد کد',
                    ], 429);
                }
            }
        }

        // Generate new OTP
        $otpCode = $this->otpService->generateOTP();
        $expiresAt = Carbon::now()->addMinutes(5);

        if ($type === 'login') {
            // Store OTP in user record for login
            $user->update([
                'otp_code' => Hash::make($otpCode),
                'otp_expires_at' => $expiresAt,
            ]);
        } else {
            // Store OTP in cache for registration
            $tempOtpKey = 'register_otp_' . $phone;
            cache()->put($tempOtpKey, [
                'otp' => Hash::make($otpCode),
                'expires_at' => $expiresAt,
            ], 300); // 5 minutes
        }

        // Send OTP via SMS
        $result = $this->otpService->sendOTP($phone, $otpCode);

        if ($result['success']) {
            $response = [
                'success' => true,
                'message' => 'کد تایید جدید ارسال شد',
                'expires_in' => 300,
            ];

            // In development mode, include the OTP for testing
            if (config('app.env') !== 'production' && isset($result['dev_otp'])) {
                $response['dev_otp'] = $result['dev_otp'];
            }

            return response()->json($response);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'خطا در ارسال کد تایید',
                'error' => $result['error'] ?? 'Unknown error',
            ], 500);
        }
    }
}
