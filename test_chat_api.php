<?php
/**
 * Test script for Chat API functionality
 * Run with: php test_chat_api.php
 */

// Test anonymous chat access
echo "Testing Anonymous Chat Access:\n";
echo "===============================\n";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://localhost:8000/api/chat/ask');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'question' => 'What is the law regarding contracts in Iran?'
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

echo "HTTP Status: $httpCode\n";
echo "Response: $response\n\n";

if ($httpCode === 200) {
    $data = json_decode($response, true);
    if (isset($data['success']) && $data['success']) {
        echo "✅ Anonymous chat works!\n";
        echo "Chat saved: " . ($data['saved'] ? 'Yes' : 'No (as expected)') . "\n";
        echo "Chat ID: " . ($data['chat_id'] ?? 'None') . "\n";
    } else {
        echo "❌ API returned error\n";
    }
} else {
    echo "❌ HTTP error: $httpCode\n";
}

curl_close($ch);

echo "\nTesting Authenticated Chat Access (requires token):\n";
echo "==================================================\n";
echo "Note: This would require a valid Bearer token\n";
echo "The endpoint should work the same but save chat data\n";
?>
