<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SEPPaymentService
{
    private string $merchantId;
    private string $terminalId;
    private string $baseUrl;
    private bool $sandbox;

    public function __construct()
    {
        $this->merchantId = config('services.sep.merchant_id', '');
        $this->terminalId = config('services.sep.terminal_id', '');
        $this->sandbox = config('services.sep.sandbox', true);
        $this->baseUrl = $this->sandbox
            ? 'https://sep.shaparak.ir/OnlinePG/OnlinePG'
            : 'https://sep.shaparak.ir/OnlinePG/OnlinePG';
    }

    /**
     * Request payment token from SEP
     *
     * @param int $amount Amount in Rials
     * @param string $resNum Reservation number (transaction ID)
     * @param string $redirectUrl Callback URL after payment
     * @param string $cellNumber Optional mobile number
     * @param array $settlementIBANs Array of IBAN settlement info for government payments
     * @return array
     */
    public function requestPayment(
        int $amount,
        string $resNum,
        string $redirectUrl,
        string $cellNumber = '',
        array $settlementIBANs = []
    ): array
    {
        try {
            // Ensure callback URL is properly formatted for SEP
            // SEP is very strict about URL format - must be exact match with whitelist
            // Old browsers might send URLs with different encoding or format, so we normalize here
            $redirectUrl = trim($redirectUrl);
            
            // Parse URL to normalize it
            $parsedUrl = parse_url($redirectUrl);
            
            if ($parsedUrl === false) {
                Log::error('SEP Callback URL parse failed:', ['url' => $redirectUrl]);
                return [
                    'success' => false,
                    'message' => 'Invalid callback URL format',
                ];
            }
            
            // Ensure HTTPS (SEP requires HTTPS for callbacks)
            $scheme = isset($parsedUrl['scheme']) ? strtolower($parsedUrl['scheme']) : 'https';
            if ($scheme !== 'https') {
                $scheme = 'https';
                Log::warning('SEP Callback URL scheme changed to HTTPS:', ['original' => $redirectUrl]);
            }
            
            // Normalize host (lowercase, remove default ports)
            $host = isset($parsedUrl['host']) ? strtolower($parsedUrl['host']) : '';
            $port = isset($parsedUrl['port']) ? $parsedUrl['port'] : null;
            
            // Remove default ports (80 for HTTP, 443 for HTTPS)
            if ($port == 80 || $port == 443) {
                $port = null;
            }
            
            // Build normalized path (remove trailing slash, ensure leading slash)
            $path = isset($parsedUrl['path']) ? $parsedUrl['path'] : '/';
            $path = '/' . ltrim($path, '/');
            $path = rtrim($path, '/');
            if (empty($path)) {
                $path = '/';
            }
            
            // Remove query string and fragment (SEP doesn't allow them in callback URL)
            // Old browsers might add query parameters, so we strip them
            $redirectUrl = $scheme . '://' . $host;
            if ($port !== null) {
                $redirectUrl .= ':' . $port;
            }
            $redirectUrl .= $path;
            
            $payload = [
                'action' => 'token',
                'TerminalId' => $this->terminalId,
                'Amount' => $amount,
                'ResNum' => $resNum,
                'RedirectUrl' => $redirectUrl,
            ];
            
            // Log the exact URL being sent to SEP for debugging
            Log::info('SEP Callback URL being sent:', [
                'original_url' => $redirectUrl,
                'normalized_url' => $redirectUrl,
                'url_length' => strlen($redirectUrl),
                'url_encoded' => urlencode($redirectUrl),
                'parsed_components' => [
                    'scheme' => $scheme,
                    'host' => $host,
                    'port' => $port,
                    'path' => $path,
                ],
            ]);

            // Add optional cell number
            if (!empty($cellNumber)) {
                $payload['CellNumber'] = $cellNumber;
            }

            // For government payments with IBAN settlement
            if (!empty($settlementIBANs)) {
                $payload['TranType'] = 'Government';
                $payload['SettlementIBANInfo'] = $settlementIBANs;
            }

            Log::info('SEP Payment Request Payload:', $payload);

            $response = Http::timeout(30)->post("{$this->baseUrl}", $payload);

            $responseData = $response->json();
            $responseStatus = $response->status();
            $responseBody = $response->body();
            
            Log::info('SEP Payment Request Response:', [
                'status_code' => $responseStatus,
                'response_data' => $responseData,
                'response_body' => $responseBody,
                'callback_url_sent' => $redirectUrl,
                'callback_url_length' => strlen($redirectUrl),
            ]);

            // Check for callback URL errors specifically
            if (!$response->successful() || (isset($responseData['status']) && $responseData['status'] != 1)) {
                $errorMessage = $responseData['errorDesc'] ?? $responseData['error'] ?? $responseBody ?? 'Unknown error';
                
                // Log detailed error for callback URL issues
                if (stripos($errorMessage, 'callback') !== false || 
                    stripos($errorMessage, 'بازگشت') !== false ||
                    stripos($errorMessage, 'آدرس') !== false) {
                    Log::error('SEP Callback URL Error:', [
                        'error_message' => $errorMessage,
                        'callback_url_sent' => $redirectUrl,
                        'expected_format' => 'https://payment.weekilaw.com/api/payment/payment-listener',
                        'url_matches' => $redirectUrl === 'https://payment.weekilaw.com/api/payment/payment-listener',
                        'response_full' => $responseData,
                    ]);
                }
            }

            if ($response->successful() && isset($responseData['status']) && $responseData['status'] == 1) {
                $token = $responseData['token'];
                // SEP payment gateway URLs
                // SEP typically uses sep.shaparak.ir for both sandbox and production
                // The difference is in the credentials (sandbox vs production terminal_id/merchant_id)
                // Some implementations may use sandbox.sep.shaparak.ir, but standard is sep.shaparak.ir for both
                // If sandbox URL doesn't work, SEP uses the same URL for both environments
                $paymentUrl = "https://sep.shaparak.ir/OnlinePG/SendToken?token={$token}";
                
                Log::info('SEP Payment URL generated:', [
                    'sandbox' => $this->sandbox,
                    'payment_url' => $paymentUrl,
                    'token' => $token,
                    'note' => 'SEP uses same URL for sandbox and production, difference is in credentials',
                ]);

                return [
                    'success' => true,
                    'token' => $token,
                    'payment_url' => $paymentUrl,
                    'res_num' => $resNum,
                ];
            }

            // Extract error message - SEP returns errorDesc in Persian
            $errorMessage = $responseData['errorDesc'] ?? $responseData['error'] ?? 'Payment request failed';
            $errorCode = $responseData['status'] ?? $responseStatus ?? null;
            
            Log::error('SEP Payment Request Failed:', [
                'error_message' => $errorMessage,
                'error_code' => $errorCode,
                'response_data' => $responseData,
                'callback_url' => $redirectUrl,
            ]);

            return [
                'success' => false,
                'message' => $errorMessage,
                'error_code' => $errorCode,
            ];

        } catch (\Exception $e) {
            Log::error('SEP payment request error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Payment gateway error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Verify payment with SEP
     *
     * @param string $resNum Reservation number
     * @param int $amount Amount in Rials
     * @return array
     */
    public function verifyPayment(string $resNum, int $amount): array
    {
        try {
            $payload = [
                'action' => 'verify',
                'TerminalId' => $this->terminalId,
                'Amount' => $amount,
                'ResNum' => $resNum,
            ];

            Log::info('SEP Payment Verification Payload:', $payload);

            $response = Http::timeout(30)->post("{$this->baseUrl}", $payload);

            $responseData = $response->json();
            Log::info('SEP Payment Verification Response:', $responseData);

            if ($response->successful() && isset($responseData['status']) && $responseData['status'] == 1) {
                return [
                    'success' => true,
                    'ref_num' => $responseData['RefNum'] ?? null,
                    'trace_no' => $responseData['TraceNo'] ?? null,
                    'amount' => $responseData['Amount'] ?? $amount,
                ];
            }

            return [
                'success' => false,
                'message' => $responseData['errorDesc'] ?? 'Payment verification failed',
                'error_code' => $responseData['status'] ?? null,
            ];

        } catch (\Exception $e) {
            Log::error('SEP payment verification error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Payment verification error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Reverse payment (for failed transactions)
     *
     * @param string $resNum Reservation number
     * @param int $amount Amount in Rials
     * @return array
     */
    public function reversePayment(string $resNum, int $amount): array
    {
        try {
            $payload = [
                'action' => 'reverse',
                'TerminalId' => $this->terminalId,
                'Amount' => $amount,
                'ResNum' => $resNum,
            ];

            Log::info('SEP Payment Reverse Payload:', $payload);

            $response = Http::timeout(30)->post("{$this->baseUrl}", $payload);

            $responseData = $response->json();
            Log::info('SEP Payment Reverse Response:', $responseData);

            if ($response->successful() && isset($responseData['status']) && $responseData['status'] == 1) {
                return [
                    'success' => true,
                    'message' => 'Payment reversed successfully',
                ];
            }

            return [
                'success' => false,
                'message' => $responseData['errorDesc'] ?? 'Payment reverse failed',
                'error_code' => $responseData['status'] ?? null,
            ];

        } catch (\Exception $e) {
            Log::error('SEP payment reverse error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Payment reverse error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Check transaction status
     *
     * @param string $resNum Reservation number
     * @return array
     */
    public function checkTransaction(string $resNum): array
    {
        try {
            $payload = [
                'action' => 'check',
                'TerminalId' => $this->terminalId,
                'ResNum' => $resNum,
            ];

            Log::info('SEP Transaction Check Payload:', $payload);

            $response = Http::timeout(30)->post("{$this->baseUrl}", $payload);

            $responseData = $response->json();
            Log::info('SEP Transaction Check Response:', $responseData);

            if ($response->successful() && isset($responseData['status'])) {
                return [
                    'success' => true,
                    'status' => $responseData['status'],
                    'amount' => $responseData['Amount'] ?? null,
                    'ref_num' => $responseData['RefNum'] ?? null,
                    'message' => $responseData['errorDesc'] ?? 'Transaction found',
                ];
            }

            return [
                'success' => false,
                'message' => $responseData['errorDesc'] ?? 'Transaction check failed',
                'error_code' => $responseData['status'] ?? null,
            ];

        } catch (\Exception $e) {
            Log::error('SEP transaction check error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Transaction check error: ' . $e->getMessage(),
            ];
        }
    }
}