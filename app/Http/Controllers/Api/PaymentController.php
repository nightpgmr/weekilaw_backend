<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PaymentService;
use App\Services\MongoUserService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    protected PaymentService $paymentService;
    protected MongoUserService $mongoUserService;

    public function __construct(PaymentService $paymentService, MongoUserService $mongoUserService)
    {
        $this->paymentService = $paymentService;
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
        }
        
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->convertBSONToArray($value);
            }
        }
        
        return $data;
    }

    /**
     * Get available payment gateways
     * GET /api/payment/gateways
     */
    public function getGateways(): JsonResponse
    {
        try {
            $gateways = $this->paymentService->getGateways();

            return response()->json([
                'success' => true,
                'gateways' => $gateways,
            ]);
        } catch (\Exception $e) {
            Log::error('[PaymentController] Error fetching gateways', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت درگاه‌های پرداخت',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get coin packages
     * GET /api/payment/coin-packages
     */
    public function getCoinPackages(): JsonResponse
    {
        try {
            $packages = $this->paymentService->getCoinPackages();

            // Debug: Include first package raw data in development
            $debug = [];
            if (config('app.env') !== 'production' && count($packages) > 0) {
                $debug = [
                    'first_package_fields' => array_keys($packages[0]),
                    'first_package_sample' => $packages[0],
                ];
            }

            return response()->json([
                'success' => true,
                'packages' => $packages,
                'debug' => $debug, // Remove this in production
            ]);
        } catch (\Exception $e) {
            Log::error('[PaymentController] Error fetching coin packages', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت بسته‌های سکه',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Initiate payment
     * POST /api/payment/initiate
     * 
     * Request body:
     * {
     *   "purchase_type": "coinpackages",
     *   "id": "coin_package_10",
     *   "gateway_id": "saman_bank"
     * }
     */
    public function initiate(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'purchase_type' => 'required|string',
                'id' => 'required|string',
                'gateway_id' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'اطلاعات ناقص است',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $user = Auth::user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'احراز هویت لازم است',
                ], 401);
            }

            // Get user phone from MongoDB
            $mongoUser = $this->mongoUserService->findUserByPhone($user->phone);
            $userPhone = $mongoUser['phone'] ?? $user->phone ?? '';

            // Detect platform from request
            // Default to 'website' for web requests
            $platform = $request->input('platform', 'website');
            
            // Validate platform enum
            $validPlatforms = ['website', 'app', 'webapp'];
            if (!in_array($platform, $validPlatforms)) {
                $platform = 'website'; // Default fallback
            }

            // Initiate payment
            $result = $this->paymentService->initiatePayment([
                'purchase_type' => $request->input('purchase_type'),
                'id' => $request->input('id'),
                'gateway_id' => $request->input('gateway_id'),
                'user_id' => $mongoUser['_id'] ?? (string) $user->id,
                'user_phone' => $userPhone,
                'platform' => $platform, // Track platform source
            ]);

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'] ?? 'خطا در شروع پرداخت',
                    'error' => $result['error'] ?? 'UNKNOWN_ERROR',
                ], 400);
            }

            // Return response matching Node.js format
            $response = [
                'success' => true,
                'token' => $result['token'] ?? null,
                'transaction_id' => $result['transaction_id'] ?? null,
                'reserve_number' => $result['reserve_number'] ?? null,
                'amount' => $result['amount'] ?? 0,
                'tax' => $result['tax'] ?? 0,
                'total_amount' => $result['total_amount'] ?? 0,
                'method' => $result['method'] ?? 'POST',
                'message' => $result['message'] ?? 'درگاه پرداخت آماده است'
            ];

            // Add payment_url if available (GET redirect method)
            if (isset($result['payment_url'])) {
                $response['payment_url'] = $result['payment_url'];
            }

            // Add payment_html if available (POST form method)
            if (isset($result['payment_html'])) {
                $response['payment_html'] = $result['payment_html'];
            }

            // Legacy format support (for React listener compatibility)
            if (isset($result['link']) && isset($result['body'])) {
                $response['link'] = $result['link'];
                $response['body'] = $result['body'];
            }

            return response()->json($response);

        } catch (\Exception $e) {
            Log::error('[PaymentController] Payment initiation error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطا در پردازش درخواست پرداخت',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Payment listener - receives POST from gateway
     * POST /api/payment/listener
     * 
     * Gateway POSTs here with payment data, we return HTML page with JavaScript that verifies payment
     * Matches Node.js implementation flow
     */
    public function listener(Request $request)
    {
        try {
            // Get all POST data from gateway (ResNum, RefNum, State, etc.)
            $body = $request->all();
            $resNum = $body['ResNum'] ?? null;

            Log::info('[PaymentController] Gateway POST received', [
                'res_num' => $resNum,
                'body_keys' => array_keys($body),
            ]);

            // Validate ResNum
            if (!$resNum) {
                Log::error('[PaymentController] Missing ResNum in POST data');
                
                return response($this->generateErrorHTML('اطلاعات پرداخت ناقص است'), 400)
                    ->header('Content-Type', 'text/html; charset=utf-8');
            }

            // Get listener HTML from PaymentService (matches Node.js flow)
            $html = $this->paymentService->getListenerHTML($resNum, $body);

            Log::info('[PaymentController] Listener HTML generated, sending to user');

            return response($html, 200)
                ->header('Content-Type', 'text/html; charset=utf-8')
                ->header('Content-Security-Policy',
                    "default-src 'self'; " .
                    "script-src 'self' 'unsafe-inline' https://static.cloudflareinsights.com; " .
                    "script-src-elem 'self' 'unsafe-inline' https://static.cloudflareinsights.com; " .
                    "style-src 'self' 'unsafe-inline'; " .
                    "img-src 'self' data: https:; " .
                    "connect-src 'self' " . config('app.url') . " https://cloudflareinsights.com;"
                );

        } catch (\Exception $e) {
            Log::error('[PaymentController] Listener error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response($this->generateErrorHTML('خطا در پردازش پرداخت'), 500)
                ->header('Content-Type', 'text/html; charset=utf-8');
        }
    }

    /**
     * Generate error HTML page
     */
    private function generateErrorHTML(string $message): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>خطا</title>
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
      color: #333;
      padding: 40px;
      border-radius: 20px;
      max-width: 500px;
      margin: 0 auto;
      box-shadow: 0 20px 60px rgba(0,0,0,0.3);
      text-align: center;
    }
    h1 { color: #e74c3c; margin-bottom: 20px; }
    p { color: #666; line-height: 1.6; }
  </style>
</head>
<body>
  <div class="container">
    <h1>❌ خطا در پردازش پرداخت</h1>
    <p>{$message}</p>
    <p style="margin-top: 20px; font-size: 14px; color: #999;">لطفاً با پشتیبانی تماس بگیرید</p>
  </div>
</body>
</html>
HTML;
    }

    /**
     * Gateway redirect - Generates payment form HTML from token
     * GET /api/payment/gateway-redirect?token=...
     * 
     * Matches Node.js /api/payment/gateway-redirect endpoint
     * Returns HTML form that auto-submits to gateway
     */
    public function gatewayRedirect(Request $request)
    {
        try {
            $token = $request->query('token');

            Log::info('[PaymentController] Gateway redirect requested', [
                'token' => $token ? substr($token, 0, 20) . '...' : null,
            ]);

            if (!$token) {
                Log::error('[PaymentController] No token provided');
                
                return response($this->generateErrorHTML('توکن پرداخت یافت نشد'), 400)
                    ->header('Content-Type', 'text/html; charset=utf-8');
            }

            // Get gateway instance to generate payment form
            $gatewayId = 'saman_bank'; // Default to Saman
            $gateways = $this->paymentService->getGateways();
            $gatewayConfig = null;
            foreach ($gateways as $gateway) {
                if (($gateway['gateway_id'] ?? $gateway['_id'] ?? '') === $gatewayId) {
                    $gatewayConfig = $gateway;
                    break;
                }
            }

            if (!$gatewayConfig) {
                return response($this->generateErrorHTML('پیکربندی درگاه پرداخت یافت نشد'), 500)
                    ->header('Content-Type', 'text/html; charset=utf-8');
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

            $gateway = \App\Services\PaymentGateway\PaymentGatewayFactory::create($gatewayId, $config);

            // Generate payment form HTML
            if (method_exists($gateway, 'generatePaymentForm')) {
                $html = $gateway->generatePaymentForm($token);
            } else {
                // Fallback: Generate basic form
                $html = $this->generatePaymentFormHTML($token);
            }

            Log::info('[PaymentController] Payment form HTML generated');

            return response($html, 200)
                ->header('Content-Type', 'text/html; charset=utf-8')
                ->header('Content-Security-Policy',
                    "default-src 'self'; " .
                    "script-src 'self' 'unsafe-inline'; " .
                    "script-src-elem 'self' 'unsafe-inline'; " .
                    "style-src 'self' 'unsafe-inline'; " .
                    "form-action https://sep.shaparak.ir;"
                );

        } catch (\Exception $e) {
            Log::error('[PaymentController] Gateway redirect error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response($this->generateErrorHTML('خطا در بارگذاری صفحه پرداخت'), 500)
                ->header('Content-Type', 'text/html; charset=utf-8');
        }
    }

    /**
     * Generate payment form HTML (fallback)
     */
    private function generatePaymentFormHTML(string $token): string
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
     * Verify callback - Called by listener HTML via JavaScript
     * POST /api/payment/verify-callback
     * 
     * Matches Node.js /api/payment/verify-callback endpoint
     * Request body contains ResNum, RefNum, State, platform, etc. from gateway
     */
    public function verifyCallback(Request $request): JsonResponse
    {
        try {
            $resNum = $request->input('ResNum');
            $refNum = $request->input('RefNum');
            $state = $request->input('State');
            $platform = $request->input('platform', 'unknown');

            Log::info('[PaymentController] Verify callback received', [
                'res_num' => $resNum,
                'ref_num' => $refNum,
                'state' => $state,
                'platform' => $platform,
            ]);

            // Validate verification data
            if (!$resNum || !$refNum) {
                Log::error('[PaymentController] Missing required verification data');
                return response()->json([
                    'success' => false,
                    'verified' => false,
                    'error' => 'MISSING_DATA',
                    'message' => 'اطلاعات تایید ناقص است'
                ], 400);
            }

            // Use PaymentService to verify payment
            // Pass all gateway data (matches Node.js flow)
            $result = $this->paymentService->verifyPayment([
                'ResNum' => $resNum,
                'RefNum' => $refNum,
                'State' => $state,
                'platform' => $platform,
                ...$request->all() // Include all other fields from Saman
            ]);

            Log::info('[PaymentController] Verification result', [
                'verified' => $result['verified'] ?? false,
                'transaction_id' => $result['transaction_id'] ?? null,
            ]);

            // Return result matching Node.js format
            return response()->json($result);

        } catch (\Exception $e) {
            Log::error('[PaymentController] Verify callback error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'verified' => false,
                'error' => 'VERIFICATION_ERROR',
                'message' => 'خطا در تایید پرداخت',
            ], 500);
        }
    }

    /**
     * Verify payment (legacy endpoint - kept for compatibility)
     * POST /api/payment/verify
     * 
     * Request body:
     * {
     *   "link": "https://www.weekilaw.com/payment-listener?transaction=...",
     *   "body": { ...gateway response data... }
     * }
     */
    public function verify(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'link' => 'required|string',
                'body' => 'required|array',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'اطلاعات ناقص است',
                    'link' => config('app.frontend_url', 'http://localhost:3000') . '/account',
                ], 422);
            }

            // Extract gateway ID - try to detect from payment record or default to saman_bank
            $gatewayId = $request->input('body.gateway_id') ?? 'saman_bank';

            // Verify payment (this will update coins in MongoDB)
            $result = $this->paymentService->verifyPayment([
                'link' => $request->input('link'),
                'body' => $request->input('body'),
                'gateway_id' => $gatewayId,
            ]);

            // Return only message and link as specified
            return response()->json([
                'message' => $result['message'],
                'link' => $result['link'],
            ]);

        } catch (\Exception $e) {
            Log::error('[PaymentController] Payment verification error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'خطا در تایید پرداخت',
                'link' => config('app.frontend_url', 'http://localhost:3000') . '/account',
            ], 500);
        }
    }
}
