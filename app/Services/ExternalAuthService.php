<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExternalAuthService
{
    /**
     * Base URL for the external authentication API
     * Use api.weekilaw.com if available, otherwise app.weekilaw.com
     */
    private const BASE_URL = 'https://api.weekilaw.com/api';
    private const FALLBACK_URL = 'https://app.weekilaw.com/api';
    private const TIMEOUT = 30; // seconds

    /**
     * Get the API base URL
     */
    private function getBaseUrl(): string
    {
        // Try to use configured URL from env, fallback to default
        $url = env('EXTERNAL_AUTH_API_URL', self::BASE_URL);
        
        // If base URL doesn't work, we can fallback to app.weekilaw.com
        // This will be handled in the request method
        return $url;
    }

    /**
     * Make a request to the external API
     */
    private function makeRequest(string $method, string $endpoint, array $data = [], ?string $token = null): array
    {
        $baseUrl = $this->getBaseUrl();
        $url = rtrim($baseUrl, '/') . '/' . ltrim($endpoint, '/');

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        if ($token) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        try {
            $response = Http::timeout(self::TIMEOUT)
                ->withHeaders($headers)
                ->{strtolower($method)}($url, $data);

            $statusCode = $response->status();
            $responseData = $response->json();

            // If request failed and we haven't tried fallback, try fallback URL
            if (!$response->successful() && $baseUrl === self::BASE_URL) {
                Log::warning('External auth API request failed, trying fallback URL', [
                    'endpoint' => $endpoint,
                    'status' => $statusCode,
                    'response' => $responseData,
                ]);

                // Try fallback URL
                $fallbackUrl = rtrim(self::FALLBACK_URL, '/') . '/' . ltrim($endpoint, '/');
                $response = Http::timeout(self::TIMEOUT)
                    ->withHeaders($headers)
                    ->{strtolower($method)}($fallbackUrl, $data);

                $statusCode = $response->status();
                $responseData = $response->json();
            }

            return [
                'success' => $response->successful(),
                'status' => $statusCode,
                'data' => $responseData,
                'raw' => $response->body(),
            ];
        } catch (\Exception $e) {
            Log::error('External auth API request exception', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'status' => 500,
                'data' => [
                    'success' => false,
                    'message' => 'خطا در ارتباط با سرور احراز هویت',
                    'error' => $e->getMessage(),
                ],
            ];
        }
    }

    /**
     * Send OTP to phone number
     */
    public function sendOTP(string $phone): array
    {
        $result = $this->makeRequest('POST', '/auth/send-otp', [
            'phone' => $phone,
        ]);

        // Log the raw response for debugging
        Log::info('[ExternalAuthService] OTP request response', [
            'phone' => substr($phone, 0, 4) . '****',
            'success' => $result['success'] ?? false,
            'status' => $result['status'] ?? null,
            'response_keys' => array_keys($result['data'] ?? []),
            'raw_response' => $result['raw'] ?? null,
        ]);

        return $result;
    }

    /**
     * Verify OTP code
     */
    public function verifyOTP(string $phone, string $otpCode, ?string $userType = null): array
    {
        $data = [
            'phone' => $phone,
            'otp_code' => $otpCode,
        ];

        if ($userType) {
            $data['user_type'] = $userType;
        }

        return $this->makeRequest('POST', '/auth/verify-otp', $data);
    }

    /**
     * Login with phone and password
     */
    public function login(string $phone, string $password): array
    {
        return $this->makeRequest('POST', '/auth/login', [
            'phone' => $phone,
            'password' => $password,
        ]);
    }

    /**
     * Refresh access token
     */
    public function refreshToken(string $refreshToken): array
    {
        return $this->makeRequest('POST', '/auth/refresh', [
            'refresh_token' => $refreshToken,
        ]);
    }

    /**
     * Get user profile
     */
    public function getProfile(string $accessToken): array
    {
        return $this->makeRequest('GET', '/auth/profile', [], $accessToken);
    }
}
