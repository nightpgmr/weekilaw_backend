<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== SEP Debug Information ===\n\n";

// Check if .env file exists
$envFile = base_path('.env');
echo "1. .env file exists: " . (file_exists($envFile) ? 'YES' : 'NO') . "\n";

// Check environment variables directly
echo "2. Direct env() calls:\n";
echo "   SEP_MERCHANT_ID: '" . env('SEP_MERCHANT_ID', 'NOT_SET') . "'\n";
echo "   SEP_TERMINAL_ID: '" . env('SEP_TERMINAL_ID', 'NOT_SET') . "'\n";
echo "   SEP_SANDBOX: '" . env('SEP_SANDBOX', 'NOT_SET') . "'\n\n";

// Check config values
echo "3. Config values:\n";
echo "   services.sep.merchant_id: '" . config('services.sep.merchant_id', 'NOT_SET') . "'\n";
echo "   services.sep.terminal_id: '" . config('services.sep.terminal_id', 'NOT_SET') . "'\n";
echo "   services.sep.sandbox: '" . config('services.sep.sandbox', 'NOT_SET') . "'\n\n";

// Test service instantiation
echo "4. Service test:\n";
try {
    $service = app(\App\Services\SEPPaymentService::class);
    echo "   Service instantiated: YES\n";
    
    // Use reflection to check private properties
    $reflection = new ReflectionClass($service);
    $terminalIdProp = $reflection->getProperty('terminalId');
    $terminalIdProp->setAccessible(true);
    $merchantIdProp = $reflection->getProperty('merchantId');
    $merchantIdProp->setAccessible(true);
    
    echo "   Service terminalId: '" . $terminalIdProp->getValue($service) . "'\n";
    echo "   Service merchantId: '" . $merchantIdProp->getValue($service) . "'\n";
} catch (Exception $e) {
    echo "   Service instantiation failed: " . $e->getMessage() . "\n";
}

// Test actual API call
echo "\n5. Testing actual API call:\n";
try {
    $testAmount = 10000; // 1000 Toman = 10000 Rial
    $testResNum = 'DEBUG-' . time();
    $testCallback = 'http://localhost:3000/callback';
    
    $result = $service->requestPayment($testAmount, $testResNum, $testCallback);
    
    echo "   API call result: " . ($result['success'] ? 'SUCCESS' : 'FAILED') . "\n";
    if (!$result['success']) {
        echo "   Error: " . ($result['message'] ?? 'Unknown error') . "\n";
        echo "   Error Code: " . ($result['error_code'] ?? 'N/A') . "\n";
    } else {
        echo "   Token: " . ($result['token'] ?? 'N/A') . "\n";
        echo "   Payment URL: " . ($result['payment_url'] ?? 'N/A') . "\n";
    }
} catch (Exception $e) {
    echo "   API call exception: " . $e->getMessage() . "\n";
}

echo "\n=== End Debug ===\n";