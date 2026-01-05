<?php

require_once 'vendor/autoload.php';

use Illuminate\Support\Facades\Http;

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$apiKey = env('KAVENEGAR_API_KEY');

if (!$apiKey) {
    echo "❌ KAVENEGAR_API_KEY not found in .env\n";
    exit(1);
}

echo "🔍 Testing Kavenegar API connection...\n";
echo "API Key: " . substr($apiKey, 0, 10) . "...\n\n";

try {
    // Test basic connectivity
    $response = Http::timeout(10)->get("https://api.kavenegar.com/v1/{$apiKey}/account/info.json");

    if ($response->successful()) {
        $data = $response->json();
        echo "✅ API connection successful!\n";
        echo "Account Info: " . json_encode($data, JSON_PRETTY_PRINT) . "\n\n";
    } else {
        echo "❌ API connection failed: Status {$response->status()}\n";
        echo "Response: {$response->body()}\n";
        exit(1);
    }

    // Test template lookup
    echo "🔍 Checking available templates...\n";
    $response = Http::timeout(10)->get("https://api.kavenegar.com/v1/{$apiKey}/sms/verify/lookup.json", [
        'receptor' => '09123456789',
        'token' => '123456',
        'template' => 'liantemp'
    ]);

    if ($response->successful()) {
        $data = $response->json();
        echo "✅ Template 'liantemp' exists and is approved!\n";
    } else {
        echo "❌ Template issue: Status {$response->status()}\n";
        echo "Response: {$response->body()}\n\n";

        echo "💡 Suggestions:\n";
        echo "1. Go to https://panel.kavenegar.com/\n";
        echo "2. Navigate to SMS → Templates\n";
        echo "3. Create a new template for OTP\n";
        echo "4. Wait for approval (usually takes a few hours)\n";
        echo "5. Update KAVENEGAR_TEMPLATE in .env with the approved template name\n";
    }

} catch (\Exception $e) {
    echo "❌ Exception: " . $e->getMessage() . "\n";
}




