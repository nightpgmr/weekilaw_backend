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
        $this->devCode = config('services.kavenegar.dev_code', '12345');
    }

    /**
     * Send OTP code via SMS
     */
    public function sendOTP(string $phone, string $otpCode): array
    {
        try {
            $isDev = config('app.env') !== 'production';

            if ($isDev) {
                Log::info('[OTP Service] Development mode - OTP: ' . $otpCode . ' for phone: ' . $phone);
                return [
                    'success' => true,
                    'message' => 'کد تایید برای شما ارسال شد',
                    'code' => $otpCode,
                    'dev_otp' => $otpCode, // Include OTP for development
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

            // Clean phone number (remove any non-numeric characters)
            $cleanPhone = preg_replace('/\D/', '', $phone);

            // Validate phone number format
            if (!preg_match('/^09\d{9}$/', $phone)) {
                return [
                    'success' => false,
                    'message' => 'فرمت شماره موبایل نامعتبر است',
                    'error' => 'Invalid phone number format'
                ];
            }

            // Kavenegar API call
            $response = Http::timeout(10)->post('https://api.kavenegar.com/v1/' . $this->apiKey . '/verify/lookup.json', [
                'receptor' => $cleanPhone,
                'token' => $otpCode,
                'template' => $this->template,
            ]);

            if ($response->successful()) {
                $data = $response->json();

                // Check if the response indicates success
                if (isset($data['return']) && $data['return']['status'] == 200) {
                    Log::info('[OTP Service] Kavenegar success for phone: ' . $phone . ' (message ID: ' . ($data['entries'][0]['messageid'] ?? 'unknown') . ')');
                    return [
                        'success' => true,
                        'message' => 'کد تایید برای شما ارسال شد',
                        'data' => $data
                    ];
                } else {
                    Log::error('[OTP Service] Kavenegar API returned error status: ' . json_encode($data));
                    return [
                        'success' => false,
                        'message' => 'خطا در ارسال کد تایید. لطفا دوباره تلاش کنید.',
                        'error' => 'API returned error status',
                        'details' => $data
                    ];
                }
            } else {
                $statusCode = $response->status();
                $responseBody = $response->body();

                Log::error('[OTP Service] Kavenegar HTTP error: Status ' . $statusCode . ', body: ' . $responseBody);

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
            Log::error('[OTP Service] Exception sending OTP: ' . $error->getMessage() . ' (File: ' . $error->getFile() . ':' . $error->getLine() . ')');

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
            $isDev = config('app.env') !== 'production';

            if ($isDev) {
                Log::info('[OTP Service] Development mode - SMS to: ' . $phone . ' - Message: ' . $message);
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
        $isDev = config('app.env') !== 'production';

        if ($isDev) {
            // In development, use the configured dev code or a default
            $devCode = $this->devCode ?: '1234';

            // Ensure it's exactly 4 digits
            if (strlen($devCode) !== 4) {
                $devCode = str_pad($devCode, 4, '0', STR_PAD_LEFT);
            }

            return $devCode;
        }

        // In production, generate cryptographically secure random 4-digit code
        try {
            $code = random_int(1000, 9999);
            return str_pad((string) $code, 4, '0', STR_PAD_LEFT);
        } catch (\Exception $e) {
            // Fallback if random_int fails (very unlikely)
            Log::warning('[OTP Service] random_int failed, using mt_rand fallback');
            $code = mt_rand(1000, 9999);
            return str_pad((string) $code, 4, '0', STR_PAD_LEFT);
        }
    }

    /**
     * Validate OTP code format
     */
    public function validateOTPFormat(string $otp): bool
    {
        return preg_match('/^\d{4}$/', $otp) === 1;
    }
}
