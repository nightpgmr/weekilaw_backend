<?php
/**
 * SEP Payment Callback Proxy
 * 
 * This script should be placed on a whitelisted domain (e.g., payment.weekilaw.com)
 * It receives the callback from SEP and forwards it to your backend.
 * 
 * Installation:
 * 1. Upload this file to your whitelisted domain's public directory
 * 2. Place it at: https://payment.weekilaw.com/api/payment/payment-listener
 * 3. Update $backendUrl below to point to your actual backend
 * 4. Make sure this domain is whitelisted in SEP payment gateway
 */

// Configuration
$backendUrl = 'https://panel.weekilaw.com/api/wallet/process-callback'; // Your actual backend URL

// Get callback parameters from SEP
$token = $_GET['Token'] ?? '';
$resNum = $_GET['ResNum'] ?? '';
$state = $_GET['State'] ?? '';
$refNum = $_GET['RefNum'] ?? '';
$traceNo = $_GET['TraceNo'] ?? '';

// Log the callback (optional, for debugging)
error_log("SEP Callback Proxy: Token=$token, ResNum=$resNum, State=$state");

// Validate required parameters
if (empty($token) || empty($resNum)) {
    // Redirect to frontend with error
    $frontendUrl = 'https://weekilaw.com/account?payment=failed&message=' . urlencode('Invalid callback parameters');
    header('Location: ' . $frontendUrl);
    exit;
}

// Build query string for backend
$queryParams = http_build_query([
    'Token' => $token,
    'ResNum' => $resNum,
    'State' => $state,
    'RefNum' => $refNum,
    'TraceNo' => $traceNo,
]);

// Forward request to backend
$backendRequestUrl = $backendUrl . '?' . $queryParams;

// Initialize cURL
$ch = curl_init($backendRequestUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); // Don't follow redirects, we want the JSON response
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
]);

// Execute request
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

// Handle errors
if ($error) {
    error_log("SEP Callback Proxy Error: " . $error);
    $redirectUrl = 'https://weekilaw.com/account?payment=failed&message=' . urlencode('Backend connection error');
    header('Location: ' . $redirectUrl);
    exit;
}

// Parse JSON response
$result = json_decode($response, true);

if ($httpCode !== 200 || !$result || !isset($result['redirect_url'])) {
    error_log("SEP Callback Proxy: Invalid response from backend. HTTP Code: $httpCode, Response: $response");
    $redirectUrl = 'https://weekilaw.com/account?payment=failed&message=' . urlencode('Backend processing error');
    header('Location: ' . $redirectUrl);
    exit;
}

// Redirect to the URL provided by backend
$redirectUrl = $result['redirect_url'];
header('Location: ' . $redirectUrl);
exit;
