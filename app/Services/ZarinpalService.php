<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ZarinpalService
{
    private string $merchantId;
    private string $baseUrl;
    private bool $sandbox;

    public function __construct()
    {
        // Use test merchant ID for sandbox mode
        $this->merchantId = config('services.zarinpal.merchant_id', 'XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX');
        $this->sandbox = config('services.zarinpal.sandbox', true);
        $this->baseUrl = $this->sandbox 
            ? 'https://sandbox.zarinpal.com/pg/v4/payment'
            : 'https://api.zarinpal.com/pg/v4/payment';
    }

    /**
     * Request payment from Zarinpal
     *
     * @param int $amount Amount in Toman (IRR / 10)
     * @param string $description Description of payment
     * @param string $callbackUrl Callback URL after payment
     * @param array $metadata Additional metadata
     * @return array
     */
    public function requestPayment(int $amount, string $description, string $callbackUrl, array $metadata = []): array
    {
        try {
            $response = Http::asJson()->post("{$this->baseUrl}/request.json", [
                'merchant_id' => $this->merchantId,
                'amount' => $amount, // Amount in Toman
                'description' => $description,
                'callback_url' => $callbackUrl,
                'metadata' => $metadata,
            ]);

            $data = $response->json();

            if ($data['data']['code'] == 100) {
                $authority = $data['data']['authority'];
                $paymentUrl = $this->sandbox
                    ? "https://sandbox.zarinpal.com/pg/StartPay/{$authority}"
                    : "https://www.zarinpal.com/pg/StartPay/{$authority}";

                return [
                    'success' => true,
                    'authority' => $authority,
                    'payment_url' => $paymentUrl,
                ];
            }

            return [
                'success' => false,
                'message' => $data['errors']['message'] ?? 'Payment request failed',
                'code' => $data['data']['code'] ?? null,
            ];

        } catch (\Exception $e) {
            Log::error('Zarinpal payment request error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Payment gateway error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Verify payment with Zarinpal
     *
     * @param string $authority Authority code from callback
     * @param int $amount Amount in Toman (IRR / 10)
     * @return array
     */
    public function verifyPayment(string $authority, int $amount): array
    {
        try {
            $response = Http::asJson()->post("{$this->baseUrl}/verify.json", [
                'merchant_id' => $this->merchantId,
                'authority' => $authority,
                'amount' => $amount,
            ]);

            $data = $response->json();

            if ($data['data']['code'] == 100 || $data['data']['code'] == 101) {
                return [
                    'success' => true,
                    'ref_id' => $data['data']['ref_id'] ?? null,
                    'code' => $data['data']['code'],
                ];
            }

            return [
                'success' => false,
                'message' => $data['errors']['message'] ?? 'Payment verification failed',
                'code' => $data['data']['code'] ?? null,
            ];

        } catch (\Exception $e) {
            Log::error('Zarinpal payment verification error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Payment verification error: ' . $e->getMessage(),
            ];
        }
    }
}
