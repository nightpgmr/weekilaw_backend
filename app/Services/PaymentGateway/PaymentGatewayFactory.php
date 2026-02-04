<?php

namespace App\Services\PaymentGateway;

use App\Services\PaymentGateway\SamanGateway;
use InvalidArgumentException;

/**
 * Payment Gateway Factory
 * 
 * Creates payment gateway instances based on gateway ID
 */
class PaymentGatewayFactory
{
    /**
     * Create payment gateway instance
     * 
     * @param string $gatewayId Gateway identifier (e.g., 'saman_bank')
     * @param array $config Gateway configuration
     * @return BasePaymentGateway
     * @throws InvalidArgumentException
     */
    public static function create(string $gatewayId, array $config): BasePaymentGateway
    {
        return match ($gatewayId) {
            'saman_bank' => new SamanGateway($config),
            default => throw new InvalidArgumentException("Unknown payment gateway: {$gatewayId}"),
        };
    }

    /**
     * Get list of supported gateways
     * 
     * @return array
     */
    public static function getSupportedGateways(): array
    {
        return [
            'saman_bank' => 'Saman Bank',
        ];
    }
}
