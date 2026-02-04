<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OTPService;
use App\Services\MongoUserService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Throwable;

class PhoneAuthController extends Controller
{
    protected OTPService $otpService;
    protected MongoUserService $mongoUserService;

    public function __construct(OTPService $otpService, MongoUserService $mongoUserService)
    {
        $this->otpService = $otpService;
        $this->mongoUserService = $mongoUserService;
    }

    /**
     * Unified send OTP (used by frontend auth/sign-in).
     * For sign-in flow: sends login OTP. Accepts optional type=register for sign-up.
     */
    public function sendOTP(Request $request): JsonResponse
    {
        Log::debug('[Auth] sendOTP request received', [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'type' => $request->input('type'),
            'phone' => $request->input('phone'),
            'env' => config('app.env'),
            'all_input' => $request->all(),
        ]);
        
        try {
            Log::debug('[Auth] sendOTP checking MongoDB service availability');
            $mongoAvailable = class_exists(\App\Services\MongoUserService::class);
            Log::debug('[Auth] sendOTP MongoDB service available', ['available' => $mongoAvailable]);
            
            $type = $request->input('type', 'login');
            Log::debug('[Auth] sendOTP type determined', ['type' => $type]);
            
            if ($type === 'register') {
                Log::debug('[Auth] sendOTP → sendRegisterOTP');
                return $this->sendRegisterOTP($request);
            }
            
            Log::debug('[Auth] sendOTP → sendLoginOTP');
            return $this->sendLoginOTP($request);
        } catch (Throwable $e) {
            Log::error('[Auth] sendOTP exception caught', [
                'message' => $e->getMessage(),
                'class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'code' => $e->getCode(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // In dev, always show the real error so you can fix DB/driver issues
            $message = (config('app.debug') || config('app.env') !== 'production')
                ? $e->getMessage() . ' (Class: ' . get_class($e) . ')'
                : 'خطا در ارسال کد تایید. لطفا دوباره تلاش کنید.';
            
            return response()->json([
                'success' => false,
                'message' => $message,
                'error_class' => get_class($e),
            ], 500);
        }
    }

    /**
     * Unified verify OTP (used by frontend auth/sign-in).
     * Accepts phone + otp_code (or otp) and returns token + user.
     */
    public function verifyOTP(Request $request): JsonResponse
    {
        $otp = $request->input('otp_code') ?? $request->input('otp');
        Log::info('[OTP DEBUG] verifyOTP request', [
            'phone' => $request->input('phone'),
            'otp_code_present' => $request->has('otp_code'),
            'otp_present' => $request->has('otp'),
            'otp_length' => $otp ? strlen($otp) : 0,
        ]);
        $request->merge(['otp' => $otp]);
        return $this->verifyLoginOTP($request);
    }

    /**
     * Send OTP for login
     */
    public function sendLoginOTP(Request $request): JsonResponse
    {
        Log::debug('[Auth] sendLoginOTP start', ['body' => $request->all()]);

        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|regex:/^09\d{9}$/',
        ]);

        if ($validator->fails()) {
            Log::debug('[Auth] sendLoginOTP validation failed', ['errors' => $validator->errors()->toArray()]);
            return response()->json([
                'success' => false,
                'message' => 'شماره موبایل نامعتبر است',
                'errors' => $validator->errors(),
            ], 422);
        }

        $phone = $request->input('phone');
        Log::debug('[Auth] sendLoginOTP phone validated', ['phone' => $phone]);

        // Check if user exists in MongoDB (shared with Node.js backend)
        Log::debug('[Auth] sendLoginOTP checking MongoDB for user', [
            'phone' => $phone,
            'mongo_service_class' => get_class($this->mongoUserService),
            'mongodb_extension_loaded' => extension_loaded('mongodb'),
        ]);
        
        try {
            // Check if user exists in MongoDB (any user, regardless of registration status)
            $mongoUser = $this->mongoUserService->findUserByPhone($phone);
            Log::debug('[Auth] sendLoginOTP MongoDB lookup completed', [
                'user_found' => $mongoUser !== null,
                'user_id' => $mongoUser['_id'] ?? null,
                'user_name' => $mongoUser['full_name'] ?? $mongoUser['name'] ?? null,
            ]);
        } catch (\Exception $e) {
            Log::error('[Auth] sendLoginOTP MongoDB lookup failed', [
                'phone' => $phone,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'mongodb_extension_loaded' => extension_loaded('mongodb'),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }

        if (!$mongoUser) {
            // User must exist in MongoDB cloud database - don't send OTP if not found
            Log::debug('[Auth] sendLoginOTP user not found in MongoDB', ['phone' => $phone]);
            return response()->json([
                'success' => false,
                'message' => 'کاربری با این شماره موبایل یافت نشد. لطفا ابتدا ثبت نام کنید.',
            ], 404);
        }

        // Generate OTP (in dev: uses OTP_DEV_CODE e.g. 12345, no SMS)
        $otpCode = $this->otpService->generateOTP();
        $expiresAt = Carbon::now()->addMinutes(5);
        Log::info('[OTP DEBUG] sendLoginOTP OTP generated and will be stored (hashed)', [
            'phone' => $phone,
            'otp_length' => strlen($otpCode),
            'otp_preview' => strlen($otpCode) >= 2 ? (substr($otpCode, 0, 1) . '***' . substr($otpCode, -1)) : '***',
            'expires_at' => $expiresAt->toIso8601String(),
            'APP_ENV' => config('app.env'),
            'OTP_DEV_MODE_config' => config('services.kavenegar.otp_dev_mode'),
        ]);

        // Store OTP in temp_otps table (SQL) for verification
        \DB::table('temp_otps')->where('expires_at', '<', Carbon::now())->delete();
        \DB::table('temp_otps')->insert([
            'phone' => $phone,
            'otp' => Hash::make($otpCode),
            'expires_at' => $expiresAt,
            'type' => 'login',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
        Log::info('[OTP DEBUG] sendLoginOTP OTP stored in temp_otps', ['phone' => $phone]);

        // Send OTP via SMS (in dev: skipped, returns success + dev_otp)
        $result = $this->otpService->sendOTP($phone, $otpCode);
        Log::info('[OTP DEBUG] sendLoginOTP sendOTP result', [
            'success' => $result['success'] ?? false,
            'has_dev_otp' => isset($result['dev_otp']),
            'message' => $result['message'] ?? null,
            'error' => $result['error'] ?? null,
        ]);

        if ($result['success']) {
            $response = [
                'success' => true,
                'message' => 'کد تایید به شماره موبایل شما ارسال شد',
                'expires_in' => 300, // 5 minutes
            ];

            // In development mode, include the OTP for testing (e.g. 12345)
            if (config('app.env') !== 'production' && isset($result['dev_otp'])) {
                $response['dev_otp'] = $result['dev_otp'];
                $response['code'] = $result['dev_otp']; // Frontend may look for 'code'
                Log::info('[Auth] sendLoginOTP dev response includes OTP', ['dev_otp' => $result['dev_otp']]);
            }

            return response()->json($response);
        } else {
            Log::warning('[Auth] sendLoginOTP send failed', ['error' => $result['error'] ?? 'unknown']);
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
        Log::debug('[Auth] sendRegisterOTP start', ['body' => $request->all()]);

        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|regex:/^09\d{9}$/',
        ]);

        if ($validator->fails()) {
            Log::debug('[Auth] sendRegisterOTP validation failed', ['errors' => $validator->errors()->toArray()]);
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
            Log::debug('[Auth] sendRegisterOTP phone already registered', ['phone' => $phone]);
            return response()->json([
                'success' => false,
                'message' => 'این شماره موبایل قبلاً ثبت شده است. لطفا وارد شوید.',
            ], 422);
        }

        // Generate OTP (in dev: 12345, no SMS)
        $otpCode = $this->otpService->generateOTP();
        $expiresAt = Carbon::now()->addMinutes(5);
        Log::info('[OTP DEBUG] sendRegisterOTP OTP generated', [
            'phone' => $phone,
            'otp_length' => strlen($otpCode),
            'APP_ENV' => config('app.env'),
            'OTP_DEV_MODE_config' => config('services.kavenegar.otp_dev_mode'),
        ]);

        // Store OTP in database instead of cache (more reliable)
        \DB::table('temp_otps')->where('expires_at', '<', Carbon::now())->delete();
        \DB::table('temp_otps')->insert([
            'phone' => $phone,
            'otp' => Hash::make($otpCode),
            'expires_at' => $expiresAt,
            'type' => 'register',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
        Log::info('[OTP DEBUG] sendRegisterOTP OTP stored in temp_otps', ['phone' => $phone]);

        // Send OTP via SMS (in dev: skipped, returns success + dev_otp)
        $result = $this->otpService->sendOTP($phone, $otpCode);
        Log::info('[OTP DEBUG] sendRegisterOTP sendOTP result', [
            'success' => $result['success'] ?? false,
            'error' => $result['error'] ?? null,
        ]);

        if ($result['success']) {
            $response = [
                'success' => true,
                'message' => 'کد تایید به شماره موبایل شما ارسال شد',
                'expires_in' => 300,
            ];
            if (config('app.env') !== 'production' && isset($result['dev_otp'])) {
                $response['dev_otp'] = $result['dev_otp'];
                $response['code'] = $result['dev_otp'];
                Log::info('[Auth] sendRegisterOTP dev response includes OTP', ['dev_otp' => $result['dev_otp']]);
            }
            return response()->json($response);
        } else {
            Log::warning('[Auth] sendRegisterOTP send failed', ['error' => $result['error'] ?? 'unknown']);
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
        Log::info('[OTP DEBUG] verifyLoginOTP start', [
            'phone' => $request->input('phone'),
            'otp_length' => strlen((string) ($request->input('otp') ?? '')),
        ]);

        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|regex:/^09\d{9}$/',
            'otp' => 'required|string|regex:/^\d{4,6}$/',
        ]);

        if ($validator->fails()) {
            Log::debug('[Auth] verifyLoginOTP validation failed', ['errors' => $validator->errors()->toArray()]);
            return response()->json([
                'success' => false,
                'message' => 'اطلاعات وارد شده نامعتبر است',
                'errors' => $validator->errors(),
            ], 422);
        }

        $phone = $request->input('phone');
        $otp = $request->input('otp');

        // Get user from MongoDB (shared with Node.js backend)
        $mongoUser = $this->mongoUserService->findUserByPhone($phone);

        if (!$mongoUser) {
            Log::debug('[Auth] verifyLoginOTP user not found in MongoDB', ['phone' => $phone]);
            return response()->json([
                'success' => false,
                'message' => 'کاربری با این شماره موبایل یافت نشد',
            ], 404);
        }

        // Get OTP from temp_otps table (SQL)
        $otpRecord = \DB::table('temp_otps')
            ->where('phone', $phone)
            ->where('type', 'login')
            ->where('expires_at', '>', Carbon::now())
            ->first();

        Log::info('[OTP DEBUG] verifyLoginOTP temp_otps lookup', [
            'phone' => $phone,
            'record_found' => $otpRecord !== null,
            'expires_at' => $otpRecord->expires_at ?? null,
            'now' => Carbon::now()->toIso8601String(),
        ]);

        if (!$otpRecord) {
            Log::info('[OTP DEBUG] verifyLoginOTP FAIL: no record or expired', ['phone' => $phone]);
            return response()->json([
                'success' => false,
                'message' => 'کد تایید منقضی شده است',
            ], 422);
        }

        // Verify OTP
        $hashCheck = Hash::check($otp, $otpRecord->otp);
        Log::info('[OTP DEBUG] verifyLoginOTP Hash::check', [
            'phone' => $phone,
            'hash_check_result' => $hashCheck,
            'submitted_otp_length' => strlen($otp),
        ]);

        if (!$hashCheck) {
            Log::info('[OTP DEBUG] verifyLoginOTP FAIL: wrong code', ['phone' => $phone]);
            return response()->json([
                'success' => false,
                'message' => 'کد تایید اشتباه است',
            ], 422);
        }

        Log::info('[OTP DEBUG] verifyLoginOTP OTP valid, creating token');

        // Clear OTP record
        \DB::table('temp_otps')->where('id', $otpRecord->id)->delete();

        // Create or get a local User model for Sanctum token (we need Eloquent model for Sanctum)
        // Use MongoDB _id as identifier, or create a mapping
        $localUser = User::firstOrCreate(
            ['phone' => $phone],
            [
                'name' => $mongoUser['name'] ?? 'کاربر',
                'email' => $mongoUser['email'] ?? 'user-' . $phone . '@mongodb.local',
                'password' => Hash::make(Str::random(32)),
                'auth_provider' => 'phone',
                'phone_verified_at' => Carbon::now(),
            ]
        );

        // Update local user with latest MongoDB data
        // MongoDB uses 'full_name', but check both for compatibility
        $mongoUserName = $mongoUser['full_name'] ?? $mongoUser['name'] ?? null;
        $localUser->update([
            'name' => $mongoUserName ?? $localUser->name,
            'phone_verified_at' => Carbon::now(),
        ]);

        // Create Sanctum tokens (access_token and refresh_token)
        $accessToken = $localUser->createToken('access-token', ['*'], now()->addHours(24))->plainTextToken;
        $refreshToken = $localUser->createToken('refresh-token', ['refresh'], now()->addDays(30))->plainTextToken;

        // Prepare user data matching Node.js format
        // MongoDB uses 'full_name', but check both for compatibility
        $userName = $mongoUser['full_name'] ?? $mongoUser['name'] ?? $localUser->name;
        $userData = [
            'id' => $mongoUser['_id'] ?? (string) $localUser->id,
            'name' => $userName,
            'full_name' => $mongoUser['full_name'] ?? null,
            'phone' => $mongoUser['phone'] ?? $phone,
            'email' => $mongoUser['email'] ?? $localUser->email,
            'role' => $mongoUser['role'] ?? $localUser->role ?? 'user',
        ];

        Log::info('[Auth] verifyLoginOTP success', [
            'user_id' => $userData['id'],
            'phone' => $phone,
        ]);

        // Return response matching Node.js backend format
        return response()->json([
            'success' => true,
            'message' => 'ورود با موفقیت انجام شد',
            'access_token' => $accessToken,
            'auth_token' => $accessToken, // Backward compatibility
            'refresh_token' => $refreshToken,
            'token' => $accessToken, // Legacy support
            'user' => $userData,
            'user_data' => $userData, // Frontend expects this key
        ]);
    }

    /**
     * Complete registration with OTP verification
     */
    public function completeRegistration(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|regex:/^09\d{9}$/',
            'otp' => 'required|string|regex:/^\d{4,6}$/',
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

        // Get OTP from database
        $otpRecord = \DB::table('temp_otps')
            ->where('phone', $phone)
            ->where('type', 'register')
            ->where('expires_at', '>', Carbon::now())
            ->first();

        Log::info('[OTP DEBUG] completeRegistration temp_otps lookup', [
            'phone' => $phone,
            'record_found' => $otpRecord !== null,
        ]);

        if (!$otpRecord) {
            Log::info('[OTP DEBUG] completeRegistration FAIL: no OTP record or expired', ['phone' => $phone]);
            return response()->json([
                'success' => false,
                'message' => 'کد تایید منقضی شده است یا یافت نشد. لطفا دوباره درخواست دهید.',
            ], 422);
        }

        // Verify OTP
        $hashCheck = Hash::check($otp, $otpRecord->otp);
        Log::info('[OTP DEBUG] completeRegistration Hash::check', ['result' => $hashCheck, 'phone' => $phone]);
        if (!$hashCheck) {
            Log::info('[OTP DEBUG] completeRegistration FAIL: wrong code', ['phone' => $phone]);
            return response()->json([
                'success' => false,
                'message' => 'کد تایید اشتباه است',
            ], 422);
        }

        // Clear the OTP record
        \DB::table('temp_otps')->where('id', $otpRecord->id)->delete();

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
            // For register, check database OTP timing
            $lastOtp = \DB::table('temp_otps')
                ->where('phone', $phone)
                ->where('type', 'register')
                ->orderBy('created_at', 'desc')
                ->first();

            if ($lastOtp) {
                $lastSent = Carbon::parse($lastOtp->created_at);
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
            // Store OTP in database for registration
            \DB::table('temp_otps')->insert([
                'phone' => $phone,
                'otp' => Hash::make($otpCode),
                'expires_at' => $expiresAt,
                'type' => 'register',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
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
