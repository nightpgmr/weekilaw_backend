<?php

require_once 'vendor/autoload.php';

use App\Services\OTPService;

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Test OTP service
$otpService = new OTPService();

echo "🧪 Testing OTP Service\n";
echo "======================\n\n";

$testPhone = '09123456789'; // Test phone number

echo "📱 Testing with phone: $testPhone\n\n";

try {
    echo "🔄 Generating OTP code...\n";
    $otpCode = $otpService->generateOTP();
    echo "✅ Generated OTP: $otpCode\n\n";

    echo "📤 Sending OTP via SMS...\n";
    $result = $otpService->sendOTP($testPhone, $otpCode);

    echo "📊 Result:\n";
    echo "   Success: " . ($result['success'] ? '✅ Yes' : '❌ No') . "\n";
    echo "   Message: " . $result['message'] . "\n";

    if (isset($result['error'])) {
        echo "   Error: " . $result['error'] . "\n";
    }

    if (isset($result['data'])) {
        echo "   API Response: " . json_encode($result['data'], JSON_PRETTY_PRINT) . "\n";
    }

} catch (Exception $e) {
    echo "❌ Exception occurred: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\n🏁 Test completed.\n";




