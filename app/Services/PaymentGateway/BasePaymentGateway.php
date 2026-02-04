<?php

namespace App\Services\PaymentGateway;

use Illuminate\Support\Facades\Log;

/**
 * Base Payment Gateway Abstract Class
 * 
 * All payment gateways must extend this class and implement required methods.
 * This provides a unified interface for different payment providers.
 */
abstract class BasePaymentGateway
{
    protected array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Get gateway identifier
     * 
     * @return string
     */
    abstract public function getGatewayId(): string;

    /**
     * Get gateway display name
     * 
     * @return string
     */
    abstract public function getGatewayName(): string;

    /**
     * Initiate payment and get payment data
     * 
     * @param array $paymentData Payment initiation data
     * @return array ['success' => bool, 'link' => string, 'body' => string|array, ...]
     */
    abstract public function initiatePayment(array $paymentData): array;

    /**
     * Verify payment with gateway
     * 
     * @param array $verificationData Data from gateway callback
     * @return array ['success' => bool, 'verified' => bool, 'amount' => int, 'ref_num' => string, ...]
     */
    abstract public function verifyPayment(array $verificationData): array;

    /**
     * Convert amount from Toman to Rial
     * In Iran: 1 Toman = 10 Rial
     * 
     * @param int|float $tomanAmount Amount in Toman
     * @return int Amount in Rial
     */
    protected function convertTomanToRial($tomanAmount): int
    {
        return (int) ($tomanAmount * 10);
    }

    /**
     * Convert amount from Rial to Toman
     * In Iran: 10 Rial = 1 Toman
     * 
     * @param int|float $rialAmount Amount in Rial
     * @return int|float Amount in Toman
     */
    protected function convertRialToToman($rialAmount)
    {
        return $rialAmount / 10;
    }

    /**
     * Validate redirect URL
     * 
     * @param string $redirectUrl
     * @return string Cleaned URL
     * @throws \InvalidArgumentException
     */
    protected function validateRedirectUrl(string $redirectUrl): string
    {
        if (empty($redirectUrl)) {
            throw new \InvalidArgumentException('Redirect URL is required');
        }

        // Clean URL (remove query params and fragments for validation)
        $cleanUrl = explode('?', $redirectUrl)[0];
        $cleanUrl = explode('#', $cleanUrl)[0];
        $cleanUrl = rtrim($cleanUrl, '/');

        // Check URL length (some gateways have limits)
        if (strlen($cleanUrl) > 2083) {
            throw new \InvalidArgumentException('Redirect URL is too long');
        }

        return $cleanUrl;
    }

    /**
     * Log payment operation
     * 
     * @param string $operation
     * @param array $data
     * @return void
     */
    protected function log(string $operation, array $data): void
    {
        Log::info("[PaymentGateway:{$this->getGatewayId()}] {$operation}", $data);
    }

    /**
     * Log payment error
     * 
     * @param string $operation
     * @param string $error
     * @param array $context
     * @return void
     */
    protected function logError(string $operation, string $error, array $context = []): void
    {
        Log::error("[PaymentGateway:{$this->getGatewayId()}] {$operation}: {$error}", $context);
    }
}
