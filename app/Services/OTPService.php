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
                    'message' => 'OTP sent (development mode)',
                    'code' => $otpCode,
                    'dev_otp' => $otpCode, // Include OTP for development
                ];
            }

            if (!$this->apiKey) {
                throw new \Exception('Kavenegar API key not configured');
            }

            // Clean phone number (remove any non-numeric characters)
            $cleanPhone = preg_replace('/\D/', '', $phone);

            // Kavenegar API call
            $response = Http::post('https://api.kavenegar.com/v1/' . $this->apiKey . '/verify/lookup.json', [
                'receptor' => $cleanPhone,
                'token' => $otpCode,
                'template' => $this->template,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                Log::info('[OTP Service] Kavenegar success for phone: ' . $phone . ' ' . $otpCode);
                return [
                    'success' => true,
                    'message' => 'OTP sent successfully',
                    'data' => $data
                ];
            } else {
                Log::error('[OTP Service] Kavenegar error: Status ' . $response->status() . ', body: ' . $response->body());
                return [
                    'success' => false,
                    'message' => 'Failed to send OTP',
                    'error' => 'API returned status ' . $response->status(),
                ];
            }

        } catch (\Exception $error) {
            Log::error('[OTP Service] Error sending OTP: ' . $error->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to send OTP',
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
                Log::info('[OTP Service] Development mode - SMS to: ' . $phone);
                return [
                    'success' => true,
                    'message' => 'SMS sent successfully (development mode)'
                ];
            }

            if (!$this->apiKey) {
                throw new \Exception('Kavenegar API key not configured');
            }

            // Clean phone number
            $cleanPhone = preg_replace('/\D/', '', $phone);

            // Kavenegar SMS API call
            $response = Http::post('https://api.kavenegar.com/v1/' . $this->apiKey . '/sms/send.json', [
                'message' => $message,
                'receptor' => $cleanPhone,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                Log::info('[OTP Service] SMS sent successfully to: ' . $phone);
                return [
                    'success' => true,
                    'message' => 'SMS sent successfully',
                    'data' => $data
                ];
            } else {
                Log::error('[OTP Service] SMS error: Status ' . $response->status() . ', body: ' . $response->body());
                return [
                    'success' => false,
                    'message' => 'Failed to send SMS',
                    'error' => 'API returned status ' . $response->status(),
                ];
            }

        } catch (\Exception $error) {
            Log::error('[OTP Service] Error sending SMS: ' . $error->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to send SMS',
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
            // In development, use the configured dev code
            return $this->devCode ?: '1234';
        }

        // In production, generate random 4-digit code
        return str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Validate OTP code format
     */
    public function validateOTPFormat(string $otp): bool
    {
        return preg_match('/^\d{4}$/', $otp) === 1;
    }
}
