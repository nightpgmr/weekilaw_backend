<?php

namespace App\Services\PaymentGateway;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Saman Bank Payment Gateway Implementation
 * 
 * Implements payment processing for Saman Bank (SEP - Saman Electronic Payment)
 */
class SamanGateway extends BasePaymentGateway
{
    private string $merchantId;
    private ?int $terminalId;
    private string $tokenUrl;
    private string $verifyUrl;

    public function __construct(array $config)
    {
        parent::__construct($config);
        
        // Try multiple field name variations (matches Node.js behavior)
        // Priority: config array -> environment variable
        $this->merchantId = $config['merchant_id'] 
            ?? $config['merchantId'] 
            ?? config('services.sep.merchant_id', '');
        
        // Terminal ID: try config first, then environment variable (matches Node.js)
        // Check config array first, then fall back to env variable
        $terminalIdRaw = null;
        if (isset($config['terminal_id']) && !empty($config['terminal_id'])) {
            $terminalIdRaw = $config['terminal_id'];
        } elseif (isset($config['terminalId']) && !empty($config['terminalId'])) {
            $terminalIdRaw = $config['terminalId'];
        } else {
            // Fall back to environment variable - try both config() and direct env()
            $terminalIdRaw = config('services.sep.terminal_id');
            if (empty($terminalIdRaw)) {
                $terminalIdRaw = env('SEP_TERMINAL_ID');
            }
            // If still empty, try reading from merchant_id (same value)
            if (empty($terminalIdRaw) && !empty($this->merchantId)) {
                $terminalIdRaw = $this->merchantId;
            }
        }
        
        $this->terminalId = $terminalIdRaw ? (int) $terminalIdRaw : null;
        
        // SEP API URLs
        $this->tokenUrl = 'https://sep.shaparak.ir/OnlinePG/OnlinePG';
        $this->verifyUrl = 'https://sep.shaparak.ir/verifyTxnRandomSessionkey/ipg/VerifyTransaction';

        // Match Node.js behavior: only warn in constructor, validate in getPaymentToken
        if (empty($this->terminalId) || $this->terminalId <= 0) {
            \Log::warning('Saman Gateway: Invalid or missing Terminal ID', [
                'config_keys' => array_keys($config),
                'terminal_id_from_config' => $config['terminal_id'] ?? $config['terminalId'] ?? 'NOT_SET',
                'terminal_id_from_config_service' => config('services.sep.terminal_id'),
                'terminal_id_from_env_direct' => env('SEP_TERMINAL_ID'),
                'merchant_id' => $this->merchantId,
                'terminal_id_final' => $this->terminalId,
            ]);
        } else {
            \Log::info('Saman Gateway: Terminal ID loaded successfully', [
                'terminal_id' => $this->terminalId,
                'merchant_id' => $this->merchantId,
            ]);
        }
    }

    public function getGatewayId(): string
    {
        return 'saman_bank';
    }

    public function getGatewayName(): string
    {
        return 'Saman Bank';
    }

    /**
     * Initiate payment - Get token from Saman and return payment link/form
     * 
     * @param array $paymentData ['amount' => int (Rials), 'reserve_number' => string, 'redirect_url' => string, 'cell_number' => string?]
     * @return array ['success' => bool, 'link' => string, 'body' => array, 'token' => string, ...]
     */
    public function initiatePayment(array $paymentData): array
    {
        $amount = (int) $paymentData['amount']; // Amount in Rials
        $reserveNumber = $paymentData['reserve_number'];
        $redirectUrl = $this->validateRedirectUrl($paymentData['redirect_url']);
        $cellNumber = $paymentData['cell_number'] ?? '';

        // Validate terminal ID (matches Node.js behavior - check here, not in constructor)
        if (empty($this->terminalId) || $this->terminalId <= 0) {
            $this->logError('Terminal ID invalid', 'Terminal ID is required and must be greater than 0');
            return [
                'success' => false,
                'error' => 'TERMINAL_ID_INVALID',
                'message' => 'شناسه ترمینال درگاه پرداخت تنظیم نشده است',
            ];
        }

        $this->log('Initiating payment', [
            'amount' => $amount,
            'reserve_number' => $reserveNumber,
            'redirect_url' => $redirectUrl,
            'terminal_id' => $this->terminalId,
        ]);

        try {
            $payload = [
                'action' => 'token',
                'TerminalId' => $this->terminalId,
                'Amount' => $amount,
                'ResNum' => $reserveNumber,
                'RedirectUrl' => $redirectUrl,
            ];

            if (!empty($cellNumber)) {
                $payload['CellNumber'] = $cellNumber;
            }

            // For government payments with IBAN settlement
            if (!empty($paymentData['settlement_ibans'])) {
                $payload['TranType'] = 'Government';
                $payload['SettlementIBANInfo'] = $paymentData['settlement_ibans'];
            }

            // Configure HTTP client with SSL options
            // In development, we may need to disable SSL verification
            $httpClient = Http::timeout(30);
            
            // For development/local environments, disable SSL verification to avoid Windows cURL certificate issues
            if (config('app.env') === 'local' || config('app.env') === 'development' || config('app.debug')) {
                $httpClient = $httpClient->withoutVerifying();
            }
            
            $response = $httpClient->post($this->tokenUrl, $payload);

            $responseData = $response->json();
            $responseStatus = $response->status();

            // Check if gateway blocked (returns HTML instead of JSON)
            if (is_string($responseData) && str_contains($responseData, '<!DOCTYPE html>')) {
                $this->logError('Gateway blocked', 'Gateway returned HTML instead of JSON');
                return [
                    'success' => false,
                    'error' => 'GATEWAY_BLOCKED',
                    'message' => 'سرور در پنل سامان ثبت نشده است',
                ];
            }

            if ($response->successful() && isset($responseData['status']) && $responseData['status'] == 1) {
                $token = $responseData['token'];
                
                if (empty($token)) {
                    $this->logError('No token received', 'Token is empty');
                    return [
                        'success' => false,
                        'error' => 'NO_TOKEN',
                        'message' => 'توکن پرداخت دریافت نشد',
                    ];
                }

                // Generate payment gateway link
                $gatewayLink = "https://sep.shaparak.ir/OnlinePG/SendToken?token={$token}";

                // Generate form body for POST submission
                $formBody = [
                    'Token' => $token,
                    'GetMethod' => '',
                ];

                $this->log('Payment initiated successfully', [
                    'token' => substr($token, 0, 20) . '...',
                    'gateway_link' => $gatewayLink,
                ]);

                // Return response matching Node.js format
                // Generate payment URL for GET redirect (matches Node.js generatePaymentUrl)
                // Always use production URL (Saman requires publicly accessible URLs)
                $baseUrl = env('SEP_BASE_URL') 
                    ?: config('services.sep.base_url')
                    ?: 'https://weekilaw.com';
                
                $paymentUrl = rtrim($baseUrl, '/') . '/api/payment/gateway-redirect?token=' . urlencode($token);

                return [
                    'success' => true,
                    'payment_url' => $paymentUrl, // GET redirect URL
                    'payment_html' => $this->generatePaymentForm($token), // POST form HTML
                    'link' => $gatewayLink, // Legacy: direct gateway link
                    'body' => $formBody, // Legacy: form body
                    'token' => $token,
                    'method' => 'POST', // POST form method
                    'reserve_number' => $reserveNumber,
                    'gatewayResponse' => [
                        'token' => $token,
                        'terminal_id' => $this->terminalId,
                        'merchant_id' => $this->merchantId,
                        'reserve_number' => $reserveNumber,
                        'payment_url' => $paymentUrl,
                        'method' => 'post_form',
                        'token_received_at' => now()->toIso8601String(),
                    ],
                ];
            }

            // Handle errors
            $errorCode = $responseData['status'] ?? $responseStatus ?? null;
            $errorMessage = $responseData['errorDesc'] ?? $responseData['error'] ?? 'Payment request failed';

            // Map error codes to user-friendly messages
            $errorMessages = [
                '-1' => 'خطا در پارامترها',
                '5' => 'آدرس برگشت در پنل سامان ثبت نشده است',
                '8' => 'IP سرور در پنل سامان ثبت نشده است',
            ];

            $userMessage = $errorMessages[$errorCode] ?? $errorMessage;

            $this->logError('Payment initiation failed', $userMessage, [
                'error_code' => $errorCode,
                'response' => $responseData,
            ]);

            return [
                'success' => false,
                'error' => 'SAMAN_ERROR_' . $errorCode,
                'error_code' => $errorCode,
                'message' => $userMessage,
            ];

        } catch (\Exception $e) {
            $this->logError('Payment initiation exception', $e->getMessage(), [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => 'CONNECTION_ERROR',
                'message' => 'خطا در ارتباط با درگاه پرداخت: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Verify payment with Saman
     * 
     * @param array $verificationData ['ref_num' => string, 'res_num' => string, 'state' => string, ...]
     * @return array ['success' => bool, 'verified' => bool, 'amount' => int, 'ref_num' => string, ...]
     */
    public function verifyPayment(array $verificationData): array
    {
        $refNum = $verificationData['ref_num'] ?? $verificationData['RefNum'] ?? null;
        $state = $verificationData['state'] ?? $verificationData['State'] ?? null;
        $resNum = $verificationData['res_num'] ?? $verificationData['ResNum'] ?? null;

        $this->log('Verifying payment', [
            'ref_num' => $refNum,
            'state' => $state,
            'res_num' => $resNum,
        ]);

        // Check if payment was canceled/failed at gateway
        if ($state !== 'OK' && $state !== '0') {
            $this->log('Payment not successful at gateway', ['state' => $state]);
            return [
                'success' => false,
                'verified' => false,
                'status' => 'failed',
                'message' => 'پرداخت در درگاه ناموفق بود',
                'state' => $state,
            ];
        }

        if (empty($refNum)) {
            $this->logError('Verification failed', 'RefNum is required');
            return [
                'success' => false,
                'verified' => false,
                'message' => 'شماره مرجع پرداخت یافت نشد',
            ];
        }

        try {
            // Saman doc: POST VerifyTransaction with RefNum + TerminalNumber (Int64).
            // If verify is not called within 30 minutes, Saman REVERSES the transaction (money goes back).
            // Case-sensitive: RefNum, TerminalNumber (doc نکته ۴).
            $verifyPayload = [
                'RefNum' => (string) $refNum,
                'TerminalNumber' => (int) $this->terminalId,
            ];

            $httpClient = Http::timeout(30)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ]);

            if (config('app.env') === 'local' || config('app.env') === 'development' || config('app.debug')) {
                $httpClient = $httpClient->withoutVerifying();
            }

            $response = null;
            $result = null;
            $lastException = null;
            $maxTries = 3;

            for ($attempt = 1; $attempt <= $maxTries; $attempt++) {
                try {
                    $response = $httpClient->post($this->verifyUrl, $verifyPayload);
                    $result = $response->json();
                    break;
                } catch (\Exception $e) {
                    $lastException = $e;
                    Log::warning('Saman verify request attempt failed', [
                        'attempt' => $attempt,
                        'max_tries' => $maxTries,
                        'error' => $e->getMessage(),
                    ]);
                    if ($attempt < $maxTries) {
                        usleep(500000); // 0.5s before retry (doc: retry if response not received)
                    }
                }
            }

            if ($result === null || $response === null) {
                $msg = $lastException ? $lastException->getMessage() : 'No response from verify API';
                $this->logError('Verification request failed after retries', $msg);
                return [
                    'success' => false,
                    'verified' => false,
                    'message' => 'خطا در ارتباط با درگاه تایید: ' . $msg,
                ];
            }

            // Log raw Saman response for debugging (verify must succeed or money is reversed)
            Log::info('Saman verify API raw response', [
                'http_status' => $response->status(),
                'result_code' => $result['ResultCode'] ?? null,
                'success' => $result['Success'] ?? null,
                'result_description' => $result['ResultDescription'] ?? null,
            ]);

            if (!$response->successful() || !isset($result['ResultCode']) || $result['ResultCode'] !== 0 || !($result['Success'] ?? false)) {
                $errorMessage = $result['ResultDescription'] ?? 'تایید ناموفق';
                $this->logError('Verification failed', $errorMessage, [
                    'result_code' => $result['ResultCode'] ?? null,
                    'response' => $result,
                ]);
                return [
                    'success' => false,
                    'verified' => false,
                    'status' => 'failed',
                    'message' => $errorMessage,
                    'result_code' => $result['ResultCode'] ?? null,
                ];
            }

            // Doc: TransactionDetail may appear as "TransactionDetail" or " TransactionDetail"
            $transactionDetail = $result['TransactionDetail'] ?? $result[' TransactionDetail'] ?? [];
            $amountInRial = $transactionDetail['OrginalAmount'] ?? 0;
            $amountInToman = $this->convertRialToToman($amountInRial);

            $this->log('Payment verified successfully', [
                'ref_num' => $refNum,
                'amount_rial' => $amountInRial,
                'amount_toman' => $amountInToman,
            ]);

            return [
                'success' => true,
                'verified' => true,
                'status' => 'completed',
                'amount' => $amountInToman, // Return in Toman
                'amount_rial' => $amountInRial,
                'ref_num' => $refNum,
                'rrn' => $transactionDetail['RRN'] ?? null,
                'gatewayResponse' => [
                    'verification_result' => $result,
                    'transaction_detail' => $transactionDetail,
                    'amount_rial' => $amountInRial,
                    'amount_toman' => $amountInToman,
                    'verification_time' => now()->toIso8601String(),
                ],
            ];

        } catch (\Exception $e) {
            $this->logError('Verification exception', $e->getMessage(), [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'verified' => false,
                'message' => 'خطا در تایید پرداخت: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Generate payment form HTML (for POST submission)
     * 
     * @param string $token Payment token from Saman
     * @return string HTML form
     */
    public function generatePaymentForm(string $token): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="referrer" content="unsafe-url">
  <title>انتقال به درگاه</title>
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
    p { color: #666; font-size: 16px; line-height: 1.6; }
  </style>
</head>
<body>
  <div class="container">
    <h1>🔒 در حال انتقال به درگاه پرداخت</h1>
    <div class="spinner"></div>
    <p>لطفاً صبر کنید...</p>
  </div>

  <form id="paymentForm" action="https://sep.shaparak.ir/OnlinePG/OnlinePG" method="POST" style="display:none;" referrerpolicy="unsafe-url">
    <input type="hidden" name="Token" value="{$token}">
    <input type="hidden" name="GetMethod" value="">
  </form>

  <script>
    // Auto-submit form immediately
    document.getElementById('paymentForm').submit();
  </script>
</body>
</html>
HTML;
    }

    /**
     * Generate listener HTML with auto-close tab functionality
     * This page verifies the payment and then closes the tab
     * Matches Node.js SamanGateway.generateListenerHTML() implementation
     */
    public function generateListenerHTML(array $data): string
    {
        $platform = $data['platform'] ?? 'web';
        $verifyUrl = $data['verifyUrl'] ?? '';
        $webAppUrl = $data['webAppUrl'] ?? '';
        $samanData = $data['samanData'] ?? '{}';

        // Parse samanData if it's a string
        $parsedData = is_string($samanData) ? json_decode($samanData, true) : $samanData;
        $samanDataJson = json_encode($parsedData, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);

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
