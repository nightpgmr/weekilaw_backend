<?php

/**
 * Test OTP Code 12345
 * 
 * This script tests that the OTP dev code 12345 is properly configured
 * and can be used for testing authentication.
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\OTPService;

echo "═══════════════════════════════════════════════════════════\n";
echo "  Testing OTP Dev Code: 12345\n";
echo "═══════════════════════════════════════════════════════════\n\n";

try {
    $otpService = app(OTPService::class);
    
    // Test 1: Generate OTP (should return 12345 in dev mode)
    echo "Test 1: Generating OTP code...\n";
    $generatedCode = $otpService->generateOTP();
    echo "   Generated code: {$generatedCode}\n";
    
    if ($generatedCode === '12345') {
        echo "   ✅ PASS: Code matches expected 12345\n";
    } else {
        echo "   ❌ FAIL: Expected 12345, got {$generatedCode}\n";
    }
    
    echo "\n";
    
    // Test 2: Validate OTP format
    echo "Test 2: Validating OTP format...\n";
    $isValid = $otpService->validateOTPFormat('12345');
    echo "   Code '12345' is valid: " . ($isValid ? 'YES' : 'NO') . "\n";
    
    if ($isValid) {
        echo "   ✅ PASS: Code format is valid\n";
    } else {
        echo "   ❌ FAIL: Code format validation failed\n";
    }
    
    echo "\n";
    
    // Test 3: Check environment variable
    echo "Test 3: Checking environment configuration...\n";
    $envCode = env('OTP_DEV_CODE', 'not-set');
    $configCode = config('services.kavenegar.dev_code', 'not-set');
    echo "   OTP_DEV_CODE from .env: {$envCode}\n";
    echo "   Config value: {$configCode}\n";
    
    if ($envCode === '12345' || $configCode === '12345') {
        echo "   ✅ PASS: Configuration is set correctly\n";
    } else {
        echo "   ⚠️  WARNING: Configuration might not be set to 12345\n";
    }
    
    echo "\n";
    
    // Test 4: Test with a phone number (simulation)
    echo "Test 4: Simulating OTP send...\n";
    $testPhone = '09123456789';
    $result = $otpService->sendOTP($testPhone, '12345');
    
    if (isset($result['success']) && $result['success']) {
        echo "   ✅ PASS: OTP service is working\n";
        if (isset($result['dev_otp'])) {
            echo "   Dev OTP returned: {$result['dev_otp']}\n";
        }
    } else {
        echo "   ⚠️  WARNING: OTP send returned unexpected result\n";
        print_r($result);
    }
    
    echo "\n";
    echo "═══════════════════════════════════════════════════════════\n";
    echo "  Test Summary: OTP Dev Code 12345 is configured\n";
    echo "═══════════════════════════════════════════════════════════\n";
    echo "\n";
    echo "💡 Usage:\n";
    echo "   - In development mode, use code '12345' for any phone number\n";
    echo "   - No SMS will be sent in dev mode\n";
    echo "   - Code is configured in .env: OTP_DEV_CODE=12345\n";
    echo "\n";
    
} catch (\Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . "\n";
    echo "   Line: " . $e->getLine() . "\n";
    exit(1);
}
