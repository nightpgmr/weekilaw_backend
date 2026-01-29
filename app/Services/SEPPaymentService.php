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
            $payload = [
                'action' => 'token',
                'TerminalId' => $this->terminalId,
                'Amount' => $amount,
                'ResNum' => $resNum,
                'RedirectUrl' => $redirectUrl,
            ];

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
                'callback_url' => $redirectUrl,
            ]);

            if ($response->successful() && isset($responseData['status']) && $responseData['status'] == 1) {
                $token = $responseData['token'];
                $paymentUrl = $this->sandbox
                    ? "https://sandbox.sep.ir/OnlinePG/SendToken?token={$token}"
                    : "https://sep.ir/OnlinePG/SendToken?token={$token}";

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