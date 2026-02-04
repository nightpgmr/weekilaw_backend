<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OTPService
{
    protected ?string $apiKey;
    protected string $template;
    protected string $devCode;

    public function __construct()
    {
        $this->apiKey = config('services.kavenegar.api_key');
        $this->template = config('services.kavenegar.template', 'liantemp');
        $this->devCode = (string) (config('services.kavenegar.dev_code') ?? env('OTP_DEV_CODE', '12345'));

        // [OTP DEBUG] Always log config on boot so we can see server values
        Log::info('[OTP DEBUG] OTPService constructed', [
            'APP_ENV' => config('app.env'),
            'OTP_DEV_MODE_env' => env('OTP_DEV_MODE'),
            'otp_dev_mode_config' => config('services.kavenegar.otp_dev_mode'),
            'kavenegar_api_key_set' => !empty($this->apiKey),
            'kavenegar_api_key_preview' => $this->apiKey ? (substr($this->apiKey, 0, 6) . '...' . substr($this->apiKey, -4)) : 'empty',
            'kavenegar_template' => $this->template,
            'dev_code_length' => strlen($this->devCode),
        ]);
    }

    /**
     * Whether to use dev mode (fixed OTP, no SMS). False when APP_ENV=production, OTP_DEV_MODE=false, or request is from production host (weekilaw.com).
     */
    protected function isDevMode(): bool
    {
        $appEnv = config('app.env');
        $otpDevMode = config('services.kavenegar.otp_dev_mode', true);
        $isProduction = ($appEnv === 'production');

        // Safeguard: if request host is production domain, always use real SMS (so server works even when APP_ENV is wrong)
        $productionHosts = ['weekilaw.com', 'www.weekilaw.com', 'panel.weekilaw.com'];
        $host = request()->getHost();
        $isProductionHost = in_array($host, $productionHosts, true) || str_ends_with($host, '.weekilaw.com');

        $result = ($isProduction || $isProductionHost) ? false : $otpDevMode;

        Log::info('[OTP DEBUG] isDevMode()', [
            'APP_ENV' => $appEnv,
            'otp_dev_mode_config' => $otpDevMode,
            'is_production' => $isProduction,
            'request_host' => $host,
            'is_production_host' => $isProductionHost,
            'result_dev_mode' => $result,
        ]);

        return $result;
    }

    /**
     * Send OTP code via SMS
     */
    public function sendOTP(string $phone, string $otpCode): array
    {
        Log::info('[OTP DEBUG] sendOTP called', [
            'phone' => $phone,
            'otp_code_length' => strlen($otpCode),
            'otp_code_preview' => strlen($otpCode) >= 2 ? (substr($otpCode, 0, 1) . '***' . substr($otpCode, -1)) : '***',
        ]);

        if ($this->isDevMode()) {
            Log::info('[OTP DEBUG] sendOTP → dev mode branch (Kavenegar SKIPPED)', [
                'phone' => $phone,
                'otp_used' => $otpCode,
            ]);
            return [
                'success' => true,
                'message' => 'کد تایید برای شما ارسال شد',
                'code' => $otpCode,
                'dev_otp' => $otpCode, // Frontend can show this in dev
            ];
        }

        try {
            Log::info('[OTP DEBUG] sendOTP → Kavenegar branch (sending real SMS)');

            if (!$this->apiKey) {
                Log::error('[OTP DEBUG] Kavenegar API key not configured');
                return [
                    'success' => false,
                    'message' => 'سرویس ارسال پیامک تنظیم نشده است',
                    'error' => 'Kavenegar API key not configured'
                ];
            }

            // Clean phone number (remove any non-numeric characters except +)
            $cleanPhone = preg_replace('/[^\d+]/', '', $phone);

            // Validate and format phone number
            if (preg_match('/^09\d{9}$/', $phone)) {
                // Convert Iranian mobile format (09123456789) to international (+989123456789)
                $cleanPhone = '+98' . substr($phone, 1);
            } elseif (!preg_match('/^\+989\d{9}$/', $cleanPhone)) {
                Log::warning('[OTP DEBUG] Invalid phone format', ['phone' => $phone, 'clean' => $cleanPhone]);
                return [
                    'success' => false,
                    'message' => 'فرمت شماره موبایل نامعتبر است',
                    'error' => 'Invalid phone number format'
                ];
            }

            $apiUrl = 'https://api.kavenegar.com/v1/' . $this->apiKey . '/verify/lookup.json';
            $params = [
                'receptor' => $cleanPhone,
                'token' => $otpCode,
                'template' => $this->template,
            ];
            Log::info('[OTP DEBUG] Kavenegar request', [
                'url_preview' => 'https://api.kavenegar.com/v1/***/verify/lookup.json',
                'receptor' => $cleanPhone,
                'template' => $this->template,
                'token_length' => strlen($otpCode),
            ]);

            // Kavenegar API call using GET method with query parameters
            $response = Http::timeout(10)->get($apiUrl, $params);
            $statusCode = $response->status();
            $responseBody = $response->body();

            Log::info('[OTP DEBUG] Kavenegar response', [
                'http_status' => $statusCode,
                'body_length' => strlen($responseBody),
                'body_preview' => strlen($responseBody) > 500 ? substr($responseBody, 0, 500) . '...' : $responseBody,
            ]);

            if ($response->successful()) {
                $data = $response->json();

                // Check if the response indicates success
                if (isset($data['return']) && $data['return']['status'] == 200) {
                    Log::info('[OTP DEBUG] Kavenegar success', [
                        'phone' => $phone,
                        'message_id' => $data['entries'][0]['messageid'] ?? 'unknown',
                    ]);
                    return [
                        'success' => true,
                        'message' => 'کد تایید برای شما ارسال شد',
                        'data' => $data
                    ];
                } else {
                    Log::error('[OTP DEBUG] Kavenegar API error status in body', [
                        'return' => $data['return'] ?? null,
                        'full_response' => $data,
                    ]);
                    return [
                        'success' => false,
                        'message' => 'خطا در ارسال کد تایید. لطفا دوباره تلاش کنید.',
                        'error' => 'API returned error status',
                        'details' => $data
                    ];
                }
            } else {
                Log::error('[OTP DEBUG] Kavenegar HTTP error', [
                    'http_status' => $statusCode,
                    'body' => $responseBody,
                ]);

                // Provide user-friendly error messages based on status codes
                $userMessage = 'خطا در ارسال کد تایید. لطفا دوباره تلاش کنید.';
                if ($statusCode === 401) {
                    $userMessage = 'تنظیمات سرویس پیامک نامعتبر است';
                } elseif ($statusCode === 403) {
                    $userMessage = 'دسترسی به سرویس پیامک مسدود شده است';
                } elseif ($statusCode >= 500) {
                    $userMessage = 'سرویس پیامک موقتاً در دسترس نیست. لطفا بعداً تلاش کنید.';
                }

                return [
                    'success' => false,
                    'message' => $userMessage,
                    'error' => 'API returned status ' . $statusCode,
                    'details' => $responseBody
                ];
            }

        } catch (\Exception $error) {
            Log::error('[OTP DEBUG] Exception in sendOTP', [
                'message' => $error->getMessage(),
                'file' => $error->getFile(),
                'line' => $error->getLine(),
                'trace' => $error->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => 'خطا در ارسال کد تایید. لطفا دوباره تلاش کنید.',
                'error' => $error->getMessage()
            ];
        }
    }

    /**
     * Send SMS message
     */
    public function sendSMS(string $phone, string $message): array
    {
        try {
            if ($this->isDevMode()) {
                Log::info('[OTP DEBUG] sendSMS → dev mode (skipped)', ['phone' => $phone]);
                return [
                    'success' => true,
                    'message' => 'پیامک با موفقیت ارسال شد (حالت توسعه)'
                ];
            }

            if (!$this->apiKey) {
                Log::error('[OTP Service] Kavenegar API key not configured');
                return [
                    'success' => false,
                    'message' => 'سرویس ارسال پیامک تنظیم نشده است',
                    'error' => 'Kavenegar API key not configured'
                ];
            }

            // Clean phone number
            $cleanPhone = preg_replace('/\D/', '', $phone);

            // Validate phone number format
            if (!preg_match('/^09\d{9}$/', $phone)) {
                return [
                    'success' => false,
                    'message' => 'فرمت شماره موبایل نامعتبر است',
                    'error' => 'Invalid phone number format'
                ];
            }

            // Kavenegar SMS API call
            $response = Http::timeout(10)->post('https://api.kavenegar.com/v1/' . $this->apiKey . '/sms/send.json', [
                'message' => $message,
                'receptor' => $cleanPhone,
            ]);

            if ($response->successful()) {
                $data = $response->json();

                // Check if the response indicates success
                if (isset($data['return']) && $data['return']['status'] == 200) {
                    Log::info('[OTP Service] SMS sent successfully to: ' . $phone . ' (message ID: ' . ($data['entries'][0]['messageid'] ?? 'unknown') . ')');
                    return [
                        'success' => true,
                        'message' => 'پیامک با موفقیت ارسال شد',
                        'data' => $data
                    ];
                } else {
                    Log::error('[OTP Service] SMS API returned error status: ' . json_encode($data));
                    return [
                        'success' => false,
                        'message' => 'خطا در ارسال پیامک. لطفا دوباره تلاش کنید.',
                        'error' => 'API returned error status',
                        'details' => $data
                    ];
                }
            } else {
                $statusCode = $response->status();
                $responseBody = $response->body();

                Log::error('[OTP Service] SMS HTTP error: Status ' . $statusCode . ', body: ' . $responseBody);

                // Provide user-friendly error messages based on status codes
                $userMessage = 'خطا در ارسال پیامک. لطفا دوباره تلاش کنید.';
                if ($statusCode === 401) {
                    $userMessage = 'تنظیمات سرویس پیامک نامعتبر است';
                } elseif ($statusCode === 403) {
                    $userMessage = 'دسترسی به سرویس پیامک مسدود شده است';
                } elseif ($statusCode >= 500) {
                    $userMessage = 'سرویس پیامک موقتاً در دسترس نیست. لطفا بعداً تلاش کنید.';
                }

                return [
                    'success' => false,
                    'message' => $userMessage,
                    'error' => 'API returned status ' . $statusCode,
                    'details' => $responseBody
                ];
            }

        } catch (\Exception $error) {
            Log::error('[OTP Service] Exception sending SMS: ' . $error->getMessage() . ' (File: ' . $error->getFile() . ':' . $error->getLine() . ')');

            return [
                'success' => false,
                'message' => 'خطا در ارسال پیامک. لطفا دوباره تلاش کنید.',
                'error' => $error->getMessage()
            ];
        }
    }

    /**
     * Generate a random OTP code
     */
    public function generateOTP(): string
    {
        if ($this->isDevMode()) {
            // In development, use OTP_DEV_CODE exactly (e.g. 12345) - no SMS sent
            $devCode = $this->devCode ?: '12345';
            // Allow 4–6 digits for dev (e.g. 1234, 12345)
            $devCode = preg_replace('/\D/', '', $devCode);
            if ($devCode === '') {
                $devCode = '12345';
            }
            Log::info('[OTP DEBUG] generateOTP → dev code', ['dev_otp' => $devCode]);
            return $devCode;
        }

        // In production, generate cryptographically secure random 4-digit code
        try {
            $code = random_int(1000, 9999);
            $padded = str_pad((string) $code, 4, '0', STR_PAD_LEFT);
            Log::info('[OTP DEBUG] generateOTP → random 4-digit', ['length' => 4]);
            return $padded;
        } catch (\Exception $e) {
            // Fallback if random_int fails (very unlikely)
            Log::warning('[OTP DEBUG] random_int failed, using mt_rand fallback');
            $code = mt_rand(1000, 9999);
            return str_pad((string) $code, 4, '0', STR_PAD_LEFT);
        }
    }

    /**
     * Validate OTP code format (4–6 digits for dev 5-digit codes like 12345)
     */
    public function validateOTPFormat(string $otp): bool
    {
        return preg_match('/^\d{4,6}$/', $otp) === 1;
    }
}
