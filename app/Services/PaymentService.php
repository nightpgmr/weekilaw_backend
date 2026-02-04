<?php

namespace App\Services;

use App\Services\MongoUserService;
use App\Services\PaymentGateway\PaymentGatewayFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Universal Payment Service
 * 
 * Handles payment initiation and verification for all payment gateways
 */
class PaymentService
{
    protected MongoUserService $mongoUserService;

    public function __construct(MongoUserService $mongoUserService)
    {
        $this->mongoUserService = $mongoUserService;
    }

    /**
     * Recursively convert BSONDocument objects to arrays
     * 
     * @param mixed $data
     * @return mixed
     */
    private function convertBSONToArray($data)
    {
        if ($data instanceof \MongoDB\Model\BSONDocument) {
            $data = iterator_to_array($data);
        } elseif ($data instanceof \MongoDB\Model\BSONArray) {
            $data = iterator_to_array($data);
        } elseif ($data instanceof \MongoDB\BSON\UTCDateTime) {
            $data = $data->toDateTime()->format('Y-m-d H:i:s');
        } elseif ($data instanceof \MongoDB\BSON\ObjectId) {
            $data = (string) $data;
        }
        
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->convertBSONToArray($value);
            }
        }
        
        return $data;
    }

    /**
     * Get payment gateways from MongoDB
     * 
     * @return array List of active gateways
     */
    public function getGateways(): array
    {
        try {
            $client = $this->mongoUserService->getClient();
            $database = $client->selectDatabase(config('mongodb.database'));
            
            // Use payment_gateways collection (matches Node.js)
            $collection = $database->selectCollection('payment_gateways');
            
            // Only return gateways where is_active is true
            $gateways = $collection->find([
                'is_active' => true,
            ], [
                'sort' => ['display_order' => 1],
            ]);

            $result = [];
            foreach ($gateways as $gateway) {
                $gatewayArray = iterator_to_array($gateway);
                if (isset($gatewayArray['_id'])) {
                    $gatewayArray['_id'] = (string) $gatewayArray['_id'];
                }
                $result[] = $gatewayArray;
            }

            Log::info('[PaymentService] Gateways fetched', [
                'count' => count($result),
                'filter' => 'is_active = true',
            ]);

            return $result;
        } catch (\Exception $e) {
            Log::error('[PaymentService] Error fetching gateways', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return [];
        }
    }

    /**
     * Get coin packages from MongoDB
     * 
     * @return array List of coin packages
     */
    public function getCoinPackages(): array
    {
        try {
            $client = $this->mongoUserService->getClient();
            $database = $client->selectDatabase(config('mongodb.database'));
            $collection = $database->selectCollection('coinpackages');

            // Sort by amount field (which contains coin count) or display_order
            $packages = $collection->find([], [
                'sort' => ['display_order' => 1, 'amount' => 1],
            ]);

            $result = [];
            $firstPackageRaw = null;
            
            foreach ($packages as $index => $package) {
                $packageArray = iterator_to_array($package);
                
                // Store first package raw data for debugging
                if ($index === 0) {
                    $firstPackageRaw = $packageArray;
                }
                
                if (isset($packageArray['_id'])) {
                    $packageArray['_id'] = (string) $packageArray['_id'];
                }
                
                // Normalize coins field - check multiple possible field names
                // This ensures frontend always gets 'coins' field
                $coinsValue = null;
                
                // Check if coins field exists - MongoDB uses 'amount' for coin count
                // Priority order: amount (coins), then other variations
                if (isset($packageArray['amount']) && $packageArray['amount'] !== null) {
                    // 'amount' field contains the coin count (not price - price is separate)
                    $coinsValue = $packageArray['amount'];
                } elseif (isset($packageArray['coins']) && $packageArray['coins'] !== null) {
                    $coinsValue = $packageArray['coins'];
                } elseif (isset($packageArray['coinAmount']) && $packageArray['coinAmount'] !== null) {
                    $coinsValue = $packageArray['coinAmount'];
                } elseif (isset($packageArray['coin_amount']) && $packageArray['coin_amount'] !== null) {
                    $coinsValue = $packageArray['coin_amount'];
                } elseif (isset($packageArray['coin']) && $packageArray['coin'] !== null) {
                    $coinsValue = $packageArray['coin'];
                } elseif (isset($packageArray['quantity']) && $packageArray['quantity'] !== null) {
                    $coinsValue = $packageArray['quantity'];
                }
                
                // If still no coins value found, try to extract from package ID
                if ($coinsValue === null) {
                    $packageId = $packageArray['_id'] ?? $packageArray['id'] ?? $packageArray['packageId'] ?? '';
                    
                    // Try to extract coin amount from ID format like "coin_package_10"
                    if (is_string($packageId) && str_starts_with($packageId, 'coin_package_')) {
                        $extractedCoins = (int) str_replace('coin_package_', '', $packageId);
                        if ($extractedCoins > 0) {
                            $coinsValue = $extractedCoins;
                        }
                    }
                }
                
                // If still no coins value, log detailed warning for first package only
                if ($coinsValue === null && $index === 0) {
                    Log::warning('[PaymentService] No coins field found in package - RAW DATA:', [
                        'package_id' => $packageId ?? 'unknown',
                        'all_fields' => array_keys($packageArray),
                        'all_values' => $packageArray,
                        'raw_mongodb_document' => $firstPackageRaw,
                    ]);
                    $coinsValue = 0;
                } elseif ($coinsValue === null) {
                    $coinsValue = 0;
                }
                
                // Ensure coins is an integer (handle string numbers, floats, etc.)
                $packageArray['coins'] = (int) $coinsValue;
                
                $result[] = $packageArray;
            }

            // Log detailed sample data for debugging
            $sample = count($result) > 0 ? $result[0] : [];
            Log::info('[PaymentService] Coin packages fetched', [
                'count' => count($result),
                'sample_fields' => array_keys($sample),
                'sample_coins' => $sample['coins'] ?? 'NOT_FOUND',
                'sample_price' => $sample['price'] ?? $sample['toman'] ?? 'NOT_FOUND',
                'sample_amount_coins' => $sample['amount'] ?? 'NOT_FOUND',
                'sample_raw' => $firstPackageRaw, // Log raw MongoDB document
            ]);

            return $result;
        } catch (\Exception $e) {
            Log::error('[PaymentService] Error fetching coin packages', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return [];
        }
    }

    /**
     * Get purchase data from MongoDB collection
     * 
     * @param string $purchaseType Collection name (e.g., 'coinpackages')
     * @param string $id Item ID (can be ObjectId string or custom ID)
     * @return array|null Purchase item data
     */
    public function getPurchaseItem(string $purchaseType, string $id): ?array
    {
        try {
            $client = $this->mongoUserService->getClient();
            $database = $client->selectDatabase(config('mongodb.database'));
            $collection = $database->selectCollection($purchaseType);

            // Try to find by _id first (if it's a valid ObjectId)
            $item = null;
            try {
                if (strlen($id) === 24 && ctype_xdigit($id)) {
                    // Looks like ObjectId, try to find by it
                    $item = $collection->findOne(['_id' => new \MongoDB\BSON\ObjectId($id)]);
                }
            } catch (\Exception $e) {
                // Not a valid ObjectId, continue to other searches
            }

            if (!$item) {
                // Try finding by other identifier fields
                $item = $collection->findOne([
                    '$or' => [
                        ['_id' => $id],
                        ['id' => $id],
                        ['packageId' => $id],
                        ['coinPackageId' => $id],
                        ['gateway_id' => $id], // For gateways
                    ],
                ]);
            }

            if ($item) {
                $itemArray = iterator_to_array($item);
                if (isset($itemArray['_id'])) {
                    $itemArray['_id'] = (string) $itemArray['_id'];
                }
                return $itemArray;
            }

            return null;
        } catch (\Exception $e) {
            Log::error('[PaymentService] Error fetching purchase item', [
                'purchase_type' => $purchaseType,
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Initiate payment
     * 
     * @param array $paymentData ['purchase_type' => string, 'id' => string, 'gateway_id' => string, 'user_id' => string, 'user_phone' => string, 'platform' => string]
     * @return array ['success' => bool, 'link' => string, 'body' => array, ...]
     */
    public function initiatePayment(array $paymentData): array
    {
        $purchaseType = $paymentData['purchase_type'];
        $purchaseId = $paymentData['id'];
        $gatewayId = $paymentData['gateway_id'];
        $userId = $paymentData['user_id'];
        $userPhone = $paymentData['user_phone'] ?? '';
        $platform = $paymentData['platform'] ?? 'website'; // Default to 'website'

        Log::info('[PaymentService] Initiating payment', [
            'purchase_type' => $purchaseType,
            'purchase_id' => $purchaseId,
            'gateway_id' => $gatewayId,
            'user_id' => $userId,
            'platform' => $platform,
        ]);

        try {
            // Get purchase item from MongoDB
            $purchaseItem = $this->getPurchaseItem($purchaseType, $purchaseId);
            if (!$purchaseItem) {
                return [
                    'success' => false,
                    'error' => 'PURCHASE_ITEM_NOT_FOUND',
                    'message' => 'بسته خرید یافت نشد',
                ];
            }

            // Extract amount and coins
            // Amount can be in Toman or Rial - check currency field
            $amount = (int) ($purchaseItem['price'] ?? $purchaseItem['amount'] ?? $purchaseItem['toman'] ?? 0);
            $coins = (int) ($purchaseItem['coins'] ?? $purchaseItem['coinAmount'] ?? 0);
            $currency = $purchaseItem['currency'] ?? 'IRT'; // Default to Toman

            if ($amount <= 0) {
                return [
                    'success' => false,
                    'error' => 'INVALID_AMOUNT',
                    'message' => 'مبلغ نامعتبر است',
                ];
            }

            // Get gateway configuration from MongoDB
            $gateways = $this->getGateways();
            $gatewayConfig = null;
            foreach ($gateways as $gateway) {
                if (($gateway['gateway_id'] ?? $gateway['_id'] ?? '') === $gatewayId) {
                    $gatewayConfig = $gateway;
                    break;
                }
            }

            if (!$gatewayConfig) {
                return [
                    'success' => false,
                    'error' => 'GATEWAY_NOT_FOUND',
                    'message' => 'درگاه پرداخت یافت نشد',
                ];
            }

            // Extract config and convert BSONDocument to array if needed
            $config = $gatewayConfig['config'] ?? [];
            if ($config instanceof \MongoDB\Model\BSONDocument) {
                $config = iterator_to_array($config);
            } elseif (!is_array($config)) {
                $config = [];
            }

            // Recursively convert any nested BSONDocuments to arrays
            $config = $this->convertBSONToArray($config);

            // Log config for debugging
            Log::info('[PaymentService] Gateway config extracted', [
                'gateway_id' => $gatewayId,
                'config_keys' => array_keys($config),
                'has_terminal_id' => isset($config['terminal_id']),
                'has_terminalId' => isset($config['terminalId']),
                'has_merchant_id' => isset($config['merchant_id']),
                'has_merchantId' => isset($config['merchantId']),
                'terminal_id_value' => $config['terminal_id'] ?? $config['terminalId'] ?? 'NOT_SET',
                'merchant_id_value' => $config['merchant_id'] ?? $config['merchantId'] ?? 'NOT_SET',
                'full_config' => $config, // Log full config to see structure
                'full_gateway_config' => $gatewayConfig, // Log full gateway document
            ]);

            // Create gateway instance
            $gateway = PaymentGatewayFactory::create($gatewayId, $config);

            // Generate transaction ID and reserve number
            $transactionId = 'TXN-' . time() . '-' . Str::random(8);
            $reserveNumber = Str::random(20);

            // Determine listener URL - Gateway POSTs to backend route (matches Node.js weekila-server-iran)
            // Backend route receives POST and returns HTML page with JavaScript that verifies payment
            // IMPORTANT: Saman gateway requires a publicly accessible URL (cannot use localhost)
            // Always use production callback URL - must be registered in Saman merchant panel
            $backendListenerUrl = env('SEP_CALLBACK_URL') 
                ?: config('services.sep.callback_url')
                ?: 'https://weekilaw.com/api/payment/payment-listener';

            // Convert amount to Rials if needed (gateways typically work with Rials)
            // IRT = Toman, IRR = Rial
            $amountInRial = ($currency === 'IRT' || $currency === 'Toman') ? $amount * 10 : $amount;

            // Initiate payment with gateway
            // Gateway will POST to backend listener route, which encodes POST body and redirects to React frontend
            $gatewayResult = $gateway->initiatePayment([
                'amount' => $amountInRial,
                'reserve_number' => $reserveNumber,
                'redirect_url' => $backendListenerUrl, // Gateway POSTs to backend route
                'cell_number' => $userPhone,
                'settlement_ibans' => $paymentData['settlement_ibans'] ?? [],
            ]);

            if (!$gatewayResult['success']) {
                return [
                    'success' => false,
                    'error' => $gatewayResult['error'] ?? 'GATEWAY_ERROR',
                    'message' => $gatewayResult['message'] ?? 'خطا در ارتباط با درگاه پرداخت',
                ];
            }

            // Store payment record in MongoDB for tracking and verification
            // Structure matches Node.js payment model
            try {
                $client = $this->mongoUserService->getClient();
                $database = $client->selectDatabase(config('mongodb.database'));
                $paymentsCollection = $database->selectCollection('payments');

                // Map purchase_type to payment_type enum
                $paymentType = match($purchaseType) {
                    'coinpackages' => 'coin_package',
                    'subscriptions' => 'subscription',
                    'consultations' => 'consultation',
                    default => 'coin_package', // Default fallback
                };

                // Convert userId to ObjectId if it's a string
                $userIdObject = is_string($userId) && strlen($userId) === 24 && ctype_xdigit($userId)
                    ? new \MongoDB\BSON\ObjectId($userId)
                    : $userId;

                // Validate platform enum
                $validPlatforms = ['website', 'app', 'webapp'];
                $platform = in_array($platform, $validPlatforms) ? $platform : 'website';

                // Calculate total amount (amount + fee)
                $fee = 0; // Can be calculated based on gateway fees
                $totalAmount = $amountInRial + $fee;

                $paymentRecord = [
                    'user_id' => $userIdObject,
                    'transaction_id' => $transactionId,
                    'gateway_id' => $gatewayId,
                    'amount' => $amountInRial, // Amount in Rials
                    'fee' => $fee,
                    'total_amount' => $totalAmount,
                    'plan_id' => $purchaseId, // Store purchase ID as plan_id
                    'payment_type' => $paymentType,
                    'description' => $paymentType === 'coin_package' 
                        ? "خرید {$coins} سکه" 
                        : "پرداخت {$purchaseType}",
                    'status' => 'pending',
                    'platform' => $platform, // Track platform: 'website', 'app', 'webapp'
                    'gateway_response' => [
                        'reserve_number' => $reserveNumber,
                        'token' => $gatewayResult['token'] ?? null,
                        'initiated_at' => new \MongoDB\BSON\UTCDateTime(),
                    ],
                    'createdAt' => new \MongoDB\BSON\UTCDateTime(),
                    'updatedAt' => new \MongoDB\BSON\UTCDateTime(),
                ];

                // Add coins field for coin_package payments (for easy reference)
                if ($paymentType === 'coin_package') {
                    $paymentRecord['coins'] = $coins;
                }

                $paymentsCollection->insertOne($paymentRecord);

                Log::info('[PaymentService] Payment record stored', [
                    'transaction_id' => $transactionId,
                    'reserve_number' => $reserveNumber,
                    'platform' => $platform,
                    'payment_type' => $paymentType,
                ]);
            } catch (\Exception $e) {
                Log::error('[PaymentService] Error storing payment record', [
                    'error' => $e->getMessage(),
                    'transaction_id' => $transactionId,
                ]);
                // Don't fail payment initiation if record storage fails
            }

            Log::info('[PaymentService] Payment initiated successfully', [
                'transaction_id' => $transactionId,
                'reserve_number' => $reserveNumber,
                'gateway' => $gatewayId,
                'platform' => $platform,
            ]);

            // Return response matching Node.js format
            // Support both payment_url (GET) and payment_html (POST form)
            $response = [
                'success' => true,
                'token' => $gatewayResult['token'] ?? null,
                'transaction_id' => $transactionId,
                'reserve_number' => $reserveNumber,
                'amount' => $amount, // Base amount (without tax)
                'tax' => 0, // Tax can be added if needed
                'total_amount' => $amountInRial, // Total amount (with tax)
                'method' => $gatewayResult['method'] ?? 'POST',
                'message' => 'درگاه پرداخت آماده است'
            ];

            // Add payment_url if available (GET redirect method)
            if (isset($gatewayResult['payment_url'])) {
                $response['payment_url'] = $gatewayResult['payment_url'];
            }

            // Add payment_html if available (POST form method)
            if (isset($gatewayResult['payment_html'])) {
                $response['payment_html'] = $gatewayResult['payment_html'];
            }

            // Legacy format support (for React listener compatibility)
            if (isset($gatewayResult['link']) && isset($gatewayResult['body'])) {
                $response['link'] = $gatewayResult['link'];
                $response['body'] = $gatewayResult['body'];
            }

            return $response;

        } catch (\Exception $e) {
            Log::error('[PaymentService] Payment initiation error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => 'INTERNAL_ERROR',
                'message' => 'خطا در پردازش درخواست پرداخت',
            ];
        }
    }

    /**
     * Verify payment
     * 
     * Supports two formats:
     * 1. New format (from listener HTML): ['ResNum' => string, 'RefNum' => string, 'State' => string, 'platform' => string, ...]
     * 2. Legacy format (from React listener): ['link' => string, 'body' => array, 'gateway_id' => string]
     * 
     * @param array $verificationData
     * @return array ['success' => bool, 'verified' => bool, 'message' => string, 'transaction_id' => string, ...]
     */
    public function verifyPayment(array $verificationData): array
    {
        // Check if this is the new format (direct gateway data) or legacy format (link + body)
        if (isset($verificationData['ResNum']) && isset($verificationData['RefNum'])) {
            // New format: Direct gateway data from listener HTML
            $resNum = $verificationData['ResNum'];
            $refNum = $verificationData['RefNum'];
            $state = $verificationData['State'] ?? null;
            $platform = $verificationData['platform'] ?? 'unknown';
            
            // Find gateway ID from payment record
            $gatewayId = null;
        } else {
            // Legacy format: From React listener
            $link = $verificationData['link'] ?? '';
            $body = $verificationData['body'] ?? [];
            $gatewayId = $verificationData['gateway_id'] ?? 'saman_bank';

            // Extract gateway data from body
            $resNum = $body['ResNum'] ?? $body['res_num'] ?? null;
            $refNum = $body['RefNum'] ?? $body['ref_num'] ?? null;
            $state = $body['State'] ?? $body['state'] ?? null;
            $platform = 'web';
        }

        Log::info('[PaymentService] Verifying payment', [
            'res_num' => $resNum,
            'ref_num' => $refNum,
            'state' => $state,
            'platform' => $platform ?? 'unknown',
        ]);

        try {
            // Find payment record by reserve number first (to get gateway_id)
            $client = $this->mongoUserService->getClient();
            $database = $client->selectDatabase(config('mongodb.database'));
            $paymentsCollection = $database->selectCollection('payments');

            $payment = $paymentsCollection->findOne([
                'gateway_response.reserve_number' => $resNum,
            ]);

            if (!$payment) {
                Log::error('[PaymentService] Payment not found for ResNum', ['res_num' => $resNum]);
                return [
                    'success' => false,
                    'verified' => false,
                    'error' => 'PAYMENT_NOT_FOUND',
                    'message' => 'تراکنش پرداخت یافت نشد'
                ];
            }

            $paymentArray = iterator_to_array($payment);
            
            // Get gateway ID from payment record if not provided
            if (!$gatewayId) {
                $gatewayId = $paymentArray['gateway_id'] ?? 'saman_bank';
            }

            // Check if already completed
            if (($paymentArray['status'] ?? '') === 'completed') {
                Log::info('[PaymentService] Payment already completed');
                return [
                    'success' => true,
                    'verified' => true,
                    'status' => 'completed',
                    'transaction_id' => $paymentArray['transaction_id'] ?? null,
                    'amount' => $paymentArray['amount'] ?? 0,
                    'ref_num' => $refNum,
                    'message' => 'پرداخت قبلاً تایید شده است'
                ];
            }

            // Get gateway configuration from MongoDB
            $gatewayConfig = $this->getPurchaseItem('payment_gateway', $gatewayId);
            
            // If not found by _id, try to find in gateways list
            if (!$gatewayConfig) {
                $gateways = $this->getGateways();
                foreach ($gateways as $gateway) {
                    $gatewayIdField = $gateway['gateway_id'] ?? $gateway['_id'] ?? '';
                    if ($gatewayIdField === $gatewayId) {
                        $gatewayConfig = $gateway;
                        break;
                    }
                }
            }

            if (!$gatewayConfig) {
                return [
                    'success' => false,
                    'verified' => false,
                    'error' => 'GATEWAY_NOT_FOUND',
                    'message' => 'درگاه پرداخت یافت نشد'
                ];
            }

            // Extract config and convert BSONDocument to array if needed
            $config = $gatewayConfig['config'] ?? [];
            if ($config instanceof \MongoDB\Model\BSONDocument) {
                $config = iterator_to_array($config);
            } elseif (!is_array($config)) {
                $config = [];
            }

            // Recursively convert any nested BSONDocuments to arrays
            $config = $this->convertBSONToArray($config);

            // Create gateway instance
            $gateway = PaymentGatewayFactory::create($gatewayId, $config);

            // Verify payment with gateway
            // Pass gateway data (ResNum, RefNum, State, etc.) to gateway for verification
            $gatewayVerificationData = [
                'ResNum' => $resNum,
                'RefNum' => $refNum,
                'State' => $state,
            ];
            
            // Include all other fields from verification data
            foreach ($verificationData as $key => $value) {
                if (!in_array($key, ['link', 'body', 'gateway_id']) && !isset($gatewayVerificationData[$key])) {
                    $gatewayVerificationData[$key] = $value;
                }
            }

            $verifyResult = $gateway->verifyPayment($gatewayVerificationData);

            if (!($verifyResult['verified'] ?? false)) {
                // Update payment status to failed
                $paymentsCollection->updateOne(
                    ['_id' => $paymentArray['_id']],
                    [
                        '$set' => [
                            'status' => 'failed',
                            'gateway_response.verification_failed_at' => new \MongoDB\BSON\UTCDateTime(),
                            'updatedAt' => new \MongoDB\BSON\UTCDateTime(),
                        ],
                        '$merge' => ['gateway_response' => array_merge($paymentArray['gateway_response'] ?? [], $verifyResult['gatewayResponse'] ?? [])],
                    ]
                );

                return [
                    'success' => false,
                    'verified' => false,
                    'status' => 'failed',
                    'transaction_id' => $paymentArray['transaction_id'] ?? null,
                    'message' => $verifyResult['message'] ?? 'تایید پرداخت ناموفق بود'
                ];
            }

            // Use verified ref_num from gateway response
            $refNum = $verifyResult['ref_num'] ?? $refNum;

            Log::info('[PaymentService] Payment verified successfully by gateway');
            Log::info('[PaymentService] Updating payment status to completed...');

            // Update payment record to completed (matches Node.js flow)
            $updateData = [
                '$set' => [
                    'status' => 'completed',
                    'paid_at' => new \MongoDB\BSON\UTCDateTime(),
                    'updatedAt' => new \MongoDB\BSON\UTCDateTime(),
                ],
            ];

            // Merge gateway response with verification details (gateway_response may be BSONDocument from MongoDB)
            $existing = $paymentArray['gateway_response'] ?? [];
            $existing = is_array($existing) ? $existing : $this->convertBSONToArray($existing);
            $existing = is_array($existing) ? $existing : [];
            $verified = $verifyResult['gatewayResponse'] ?? [];
            $verified = is_array($verified) ? $verified : $this->convertBSONToArray($verified);
            $verified = is_array($verified) ? $verified : [];
            $gatewayResponseUpdate = array_merge($existing, $verified, [
                'redirect_platform' => $platform,
                'verified_at' => new \MongoDB\BSON\UTCDateTime(),
            ]);

            $updateData['$set']['gateway_response'] = $gatewayResponseUpdate;

            $paymentsCollection->updateOne(
                ['_id' => $paymentArray['_id']],
                $updateData
            );

            Log::info('[PaymentService] Payment record updated');

            // ========== HANDLE COIN PACKAGE PAYMENTS ==========
            $planId = $paymentArray['plan_id'] ?? null;
            $userId = $paymentArray['user_id'] ?? null;

            if ($planId && $userId && str_starts_with($planId, 'coin_package_')) {
                Log::info('[PaymentService] Processing coin package payment', [
                    'payment_id' => (string) $paymentArray['_id'],
                    'user_id' => is_object($userId) ? (string) $userId : $userId,
                    'plan_id' => $planId,
                ]);

                try {
                    // Extract coin amount from plan_id (e.g., "coin_package_10" -> 10)
                    $coinAmount = (int) str_replace('coin_package_', '', $planId);

                    if ($coinAmount <= 0) {
                        Log::error('[PaymentService] Invalid coin amount', ['plan_id' => $planId]);
                        throw new \Exception("Invalid coin package ID");
                    }

                    Log::info('[PaymentService] Coin amount extracted', ['coins' => $coinAmount]);

                    // Get users collection
                    $usersCollection = $database->selectCollection('users');

                    // Convert userId to ObjectId if needed
                    $userIdObject = is_string($userId) && strlen($userId) === 24 && ctype_xdigit($userId)
                        ? new \MongoDB\BSON\ObjectId($userId)
                        : (is_object($userId) ? $userId : new \MongoDB\BSON\ObjectId($userId));

                    // Get current user balance
                    $user = $usersCollection->findOne(['_id' => $userIdObject]);
                    $balanceBefore = $user ? ((int) ($user['coins'] ?? 0)) : 0;
                    $newBalance = $balanceBefore + $coinAmount;

                    Log::info('[PaymentService] Updating user coins', [
                        'balance_before' => $balanceBefore,
                        'adding' => $coinAmount,
                        'balance_after' => $newBalance,
                    ]);

                    // Atomically increment user coins (matches Node.js $inc)
                    $usersCollection->updateOne(
                        ['_id' => $userIdObject],
                        [
                            '$inc' => ['coins' => $coinAmount],
                            '$set' => ['updatedAt' => new \MongoDB\BSON\UTCDateTime()],
                        ]
                    );

                    Log::info('[PaymentService] Added coins to user account', [
                        'coins_added' => $coinAmount,
                        'new_balance' => $newBalance,
                    ]);

                } catch (\Exception $coinError) {
                    Log::error('[PaymentService] Error processing coin package payment', [
                        'error' => $coinError->getMessage(),
                        'trace' => $coinError->getTraceAsString(),
                    ]);
                    // Don't fail the payment if coin update fails - payment is still successful
                    // Coins can be added manually if needed
                }
            }

            Log::info('[PaymentService] PAYMENT VERIFIED AND COMPLETED SUCCESSFULLY');

            // Get updated payment record for response
            $updatedPayment = $paymentsCollection->findOne(['_id' => $paymentArray['_id']]);
            $updatedPaymentArray = $updatedPayment ? iterator_to_array($updatedPayment) : $paymentArray;

            // Return result matching Node.js format
            return [
                'success' => true,
                'verified' => true,
                'status' => 'completed',
                'transaction_id' => $paymentArray['transaction_id'] ?? null,
                'amount' => $verifyResult['amount'] ?? $paymentArray['amount'] ?? 0,
                'ref_num' => $verifyResult['ref_num'] ?? $refNum,
                'plan_id' => $paymentArray['plan_id'] ?? null,
                'payment' => $updatedPaymentArray, // Include updated payment object
                'message' => 'پرداخت با موفقیت تایید شد'
            ];

        } catch (\Exception $e) {
            Log::error('[PaymentService] Payment verification error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'message' => 'خطا در تایید پرداخت',
                'link' => config('services.sep.web_app_url', config('app.frontend_url', 'https://weekilaw.com')) . '/account',
            ];
        }
    }

    /**
     * Get payment listener HTML page
     * This page shows loading animation and verifies payment via JavaScript
     * Matches Node.js PaymentService.getListenerHTML() implementation
     */
    public function getListenerHTML(string $resNum, array $samanPostData): string
    {
        Log::info('[PaymentService] Generating listener HTML', [
            'res_num' => $resNum,
            'saman_data_keys' => array_keys($samanPostData),
        ]);

        try {
            // Find payment by reserve number
            $client = $this->mongoUserService->getClient();
            $database = $client->selectDatabase(config('mongodb.database'));
            $paymentsCollection = $database->selectCollection('payments');

            $payment = $paymentsCollection->findOne([
                'gateway_response.reserve_number' => $resNum,
            ]);

            $detectedPlatform = 'web';
            $gatewayId = 'saman_bank';

            if ($payment) {
                $paymentArray = iterator_to_array($payment);
                $detectedPlatform = $paymentArray['platform'] ?? 'web';
                $gatewayId = $paymentArray['gateway_id'] ?? 'saman_bank';

                Log::info('[PaymentService] Found payment', [
                    'transaction_id' => $paymentArray['transaction_id'] ?? null,
                    'platform' => $detectedPlatform,
                    'status' => $paymentArray['status'] ?? null,
                ]);

                // Mark payment as failed if it was canceled/failed
                $isFailed = ($samanPostData['State'] ?? '') === 'Failed' ||
                           ($samanPostData['State'] ?? '') === 'Canceled' ||
                           ($samanPostData['Status'] ?? '') === '3' ||
                           (int)($samanPostData['Status'] ?? 0) === 3;

                if ($isFailed) {
                    Log::info('[PaymentService] Payment was canceled/failed - updating status');
                    $paymentsCollection->updateOne(
                        ['_id' => $paymentArray['_id']],
                        [
                            '$set' => [
                                'status' => 'failed',
                                'gateway_response.failed_reason' => 'User canceled or payment failed at gateway',
                                'gateway_response.failed_at' => new \MongoDB\BSON\UTCDateTime(),
                                'updatedAt' => new \MongoDB\BSON\UTCDateTime(),
                            ],
                            '$merge' => ['gateway_response' => $samanPostData],
                        ]
                    );
                }
            } else {
                Log::warning('[PaymentService] Payment not found for ResNum', ['res_num' => $resNum]);
            }

            // Configure URLs - Always use production URLs (Saman requires publicly accessible URLs)
            $verifyUrl = env('SEP_VERIFY_URL') 
                ?: config('services.sep.verify_url')
                ?: 'https://weekilaw.com/api/payment/verify-callback';

            $webAppUrl = env('SEP_WEB_APP_URL')
                ?: config('services.sep.web_app_url')
                ?: config('app.frontend_url', 'https://weekilaw.com');

            Log::info('[PaymentService] Listener URLs configured', [
                'verify_url' => $verifyUrl,
                'web_app_url' => $webAppUrl,
            ]);

            // Get gateway instance to generate HTML
            $gateways = $this->getGateways();
            $gatewayConfig = null;
            foreach ($gateways as $gateway) {
                if (($gateway['gateway_id'] ?? $gateway['_id'] ?? '') === $gatewayId) {
                    $gatewayConfig = $gateway;
                    break;
                }
            }

            if (!$gatewayConfig) {
                throw new \Exception('Gateway configuration not found');
            }

            // Extract config and convert BSONDocument to array if needed
            $config = $gatewayConfig['config'] ?? [];
            if ($config instanceof \MongoDB\Model\BSONDocument) {
                $config = iterator_to_array($config);
            } elseif (!is_array($config)) {
                $config = [];
            }

            // Recursively convert any nested BSONDocuments to arrays
            $config = $this->convertBSONToArray($config);

            $gateway = PaymentGatewayFactory::create($gatewayId, $config);

            // Generate HTML using gateway's method
            if (method_exists($gateway, 'generateListenerHTML')) {
                return $gateway->generateListenerHTML([
                    'platform' => $detectedPlatform,
                    'verifyUrl' => $verifyUrl,
                    'webAppUrl' => $webAppUrl,
                    'samanData' => json_encode($samanPostData),
                ]);
            }

            // Fallback: Generate basic HTML if gateway doesn't have the method
            return $this->generateBasicListenerHTML($detectedPlatform, $verifyUrl, $webAppUrl, $samanPostData);

        } catch (\Exception $e) {
            Log::error('[PaymentService] Error generating listener HTML', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Generate basic listener HTML (fallback)
     */
    private function generateBasicListenerHTML(string $platform, string $verifyUrl, string $webAppUrl, array $samanData): string
    {
        $samanDataJson = json_encode($samanData, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
        
        return <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>بررسی پرداخت</title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
      font-family: Tahoma, Arial, sans-serif;
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      display: flex;
      justify-content: center;
      align-items: center;
      min-height: 100vh;
    }
    .container {
      background: white;
      border-radius: 20px;
      padding: 40px;
      box-shadow: 0 20px 60px rgba(0,0,0,0.3);
      text-align: center;
      max-width: 500px;
      width: 90%;
    }
    .spinner {
      border: 4px solid #f3f3f3;
      border-top: 4px solid #667eea;
      border-radius: 50%;
      width: 60px;
      height: 60px;
      animation: spin 1s linear infinite;
      margin: 20px auto;
    }
    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }
    h1 { color: #667eea; margin-bottom: 20px; font-size: 24px; }
    p { color: #666; font-size: 16px; margin: 10px 0; }
    .status { 
      margin-top: 20px; 
      padding: 15px; 
      border-radius: 10px; 
      font-weight: bold;
    }
    .status.success { background: #d4edda; color: #155724; }
    .status.error { background: #f8d7da; color: #721c24; }
    .status.closing { background: #fff3cd; color: #856404; }
    .btn {
      margin-top: 20px;
      padding: 12px 30px;
      background: #667eea;
      color: white;
      border: none;
      border-radius: 8px;
      font-size: 16px;
      cursor: pointer;
      text-decoration: none;
      display: inline-block;
      transition: background 0.3s;
    }
    .btn:hover { background: #5568d3; }
    .close-info {
      margin-top: 15px;
      font-size: 14px;
      color: #888;
    }
  </style>
</head>
<body>
  <div class="container">
    <h1>🔒 بررسی پرداخت</h1>
    <div class="spinner" id="spinner"></div>
    <p id="message">لطفاً صبر کنید...</p>
    <div id="status"></div>
    <div id="actions"></div>
  </div>

  <script>
    const messageEl = document.getElementById('message');
    const statusEl = document.getElementById('status');
    const spinnerEl = document.getElementById('spinner');
    const actionsEl = document.getElementById('actions');
    
    const platform = '{$platform}';
    const verifyUrl = '{$verifyUrl}';
    const webAppUrl = '{$webAppUrl}';
    const samanData = {$samanDataJson};

    console.log('=== Payment Listener Debug ===');
    console.log('Platform:', platform);
    console.log('Saman Data:', samanData);
    console.log('State:', samanData.State);
    console.log('Status:', samanData.Status);
    console.log('RefNum:', samanData.RefNum);
    console.log('ResNum:', samanData.ResNum);

    // Send postMessage to opener window (the main app tab)
    function sendMessageToOpener(type, success, transactionId, refNum) {
      const message = {
        type: type,
        success: success,
        transaction_id: transactionId,
        ref_num: refNum
      };

      console.log('📤 Sending postMessage:', message);

      // Try sending to opener (if opened via window.open)
      if (window.opener) {
        try {
          window.opener.postMessage(message, '*');
          console.log('✅ postMessage sent to opener');
        } catch (e) {
          console.warn('⚠️ Could not send to opener:', e);
        }
      }

      // Also try parent (in case of iframe)
      if (window.parent && window.parent !== window) {
        try {
          window.parent.postMessage(message, '*');
          console.log('✅ postMessage sent to parent');
        } catch (e) {
          console.warn('⚠️ Could not send to parent:', e);
        }
      }

      // Broadcast to all possible origins
      try {
        window.postMessage(message, '*');
      } catch (e) {
        console.warn('⚠️ Broadcast postMessage failed:', e);
      }
    }

    // Attempt to close the tab
    function closeTab() {
      console.log('🔐 Attempting to close tab...');
      
      statusEl.innerHTML = '<div class="status closing">در حال بستن صفحه...</div>';
      messageEl.textContent = '';
      
      setTimeout(() => {
        try {
          window.close();
        } catch (e) {
          console.warn('window.close() failed:', e);
        }
        
        setTimeout(() => {
          if (!window.closed) {
            console.log('⚠️ Tab did not close automatically');
            statusEl.innerHTML = '<div class="status success">✅ پرداخت تکمیل شد</div>';
            messageEl.textContent = 'لطفاً این صفحه را ببندید';
            actionsEl.innerHTML = '<p class="close-info">می‌توانید این تب را ببندید و به اپلیکیشن بازگردید</p>' +
              '<button class="btn" onclick="window.close()">بستن این صفحه</button>';
          }
        }, 500);
      }, 1000);
    }

    function showError(title, message, transactionId) {
      spinnerEl.style.display = 'none';
      statusEl.innerHTML = '<div class="status error">' + title + '</div>';
      messageEl.textContent = message;
      
      sendMessageToOpener('PAYMENT_FAILED', false, transactionId || samanData.ResNum, null);
      
      setTimeout(() => {
        actionsEl.innerHTML = '<button class="btn" onclick="window.close()">بستن صفحه</button>' +
          '<p class="close-info" style="margin-top: 10px;">یا به صفحه اصلی بازگردید</p>' +
          '<a href="' + webAppUrl + '/account" class="btn" style="background: #6c757d; margin-top: 10px;">بازگشت به سایت</a>';
      }, 1500);
    }

    function showSuccess(message, transactionId, refNum) {
      spinnerEl.style.display = 'none';
      statusEl.innerHTML = '<div class="status success">✅ پرداخت موفق</div>';
      messageEl.textContent = message;
      
      // Send success message to opener MULTIPLE times to ensure delivery
      for (let i = 0; i < 5; i++) {
        setTimeout(() => {
          sendMessageToOpener('PAYMENT_COMPLETE', true, transactionId, refNum);
        }, i * 200);
      }
      
      setTimeout(() => {
        closeTab();
      }, 2000);
    }

    async function handlePayment() {
      // Check if payment was canceled or failed
      const isFailed = samanData.State === 'Failed' || 
                      samanData.State === 'Canceled' || 
                      samanData.Status === '3' || 
                      samanData.Status === 3 ||
                      parseInt(samanData.Status) === 3;

      console.log('Is Failed?', isFailed);

      if (isFailed) {
        console.log('Payment failed/canceled - showing error');
        showError('❌ پرداخت لغو شد', 'پرداخت توسط کاربر لغو شد یا ناموفق بود', samanData.ResNum);
        return;
      }

      // Check if payment was successful (State = "OK")
      if (samanData.State !== 'OK') {
        console.log('Payment state is not OK:', samanData.State);
        showError('❌ پرداخت ناموفق', 'وضعیت پرداخت: ' + samanData.State, samanData.ResNum);
        return;
      }

      // Check if we have required data for successful payment
      if (!samanData.ResNum || !samanData.RefNum) {
        console.error('Missing payment data');
        showError('❌ اطلاعات ناقص', 'اطلاعات پرداخت ناقص است', null);
        return;
      }

      // Payment was successful, verify it
      try {
        messageEl.textContent = 'در حال تایید پرداخت...';
        console.log('Verifying payment...');

        const verifyResponse = await fetch(verifyUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ ...samanData, platform: platform })
        });

        const result = await verifyResponse.json();
        console.log('Verification result:', result);

        if (result.success && result.verified) {
          const txId = result.transaction_id || samanData.ResNum;
          const refNum = samanData.RefNum;
          
          if (platform === 'web' || platform === 'unknown' || platform === 'website') {
            showSuccess('پرداخت با موفقیت انجام شد', txId, refNum);
          } else {
            showSuccess('پرداخت با موفقیت انجام شد - در حال انتقال...', txId, refNum);
            setTimeout(() => {
              window.location.href = webAppUrl + '/account?payment=success&transaction_id=' + txId;
            }, 1500);
          }
        } else {
          showError('❌ تایید ناموفق', result.message || 'خطا در تایید پرداخت', samanData.ResNum);
        }
      } catch (error) {
        console.error('Verification error:', error);
        showError('❌ خطا در تایید', 'خطا در ارتباط با سرور', samanData.ResNum);
      }
    }

    // Start payment handling immediately
    console.log('Starting payment handler...');
    handlePayment();
  </script>
</body>
</html>
HTML;
    }
}
