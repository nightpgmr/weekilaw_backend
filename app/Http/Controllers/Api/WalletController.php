<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WalletTransaction;
use App\Models\User;
use App\Services\SEPPaymentService;
use App\Services\ExternalAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class WalletController extends Controller
{
    private SEPPaymentService $sepPaymentService;
    private ExternalAuthService $authService;

    public function __construct(SEPPaymentService $sepPaymentService, ExternalAuthService $authService)
    {
        $this->sepPaymentService = $sepPaymentService;
        $this->authService = $authService;
    }

    /**
     * Get local user from JWT token
     * This method validates the JWT token with external API and returns the local user
     */
    private function getUserFromToken(Request $request): ?User
    {
        $token = $request->bearerToken();
        
        if (!$token) {
            return null;
        }

        try {
            // Get user profile from external API to validate token
            $result = $this->authService->getProfile($token);
            
            if (!$result['success'] || !isset($result['data']['success']) || !$result['data']['success']) {
                Log::warning('Invalid token in wallet request', [
                    'status' => $result['status'] ?? 401,
                ]);
                return null;
            }

            $externalUser = $result['data']['user'] ?? null;
            if (!$externalUser || !isset($externalUser['phone'])) {
                Log::warning('No phone number in external user data');
                return null;
            }

            // Find or create local user by phone number
            $user = User::where('phone', $externalUser['phone'])->first();
            
            if (!$user) {
                // Create local user if doesn't exist
                $user = User::create([
                    'phone' => $externalUser['phone'],
                    'name' => $externalUser['name'] ?? $externalUser['phone'],
                    'email' => $externalUser['email'] ?? null,
                    'wallet_balance' => 0,
                ]);
                Log::info('Created local user for wallet', ['user_id' => $user->id, 'phone' => $user->phone]);
            }

            return $user;
        } catch (\Exception $e) {
            Log::error('Error getting user from token', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Get wallet balance
     */
    public function getBalance(Request $request)
    {
        try {
            $user = $this->getUserFromToken($request);
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'توکن احراز هویت مورد نیاز است',
                    'code' => 'TOKEN_REQUIRED',
                ], 401);
            }

            return response()->json([
                'success' => true,
                'balance' => (float) $user->wallet_balance,
                'formatted_balance' => number_format($user->wallet_balance, 0) . ' ریال',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get balance',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Initiate government payment with IBAN settlement via SEP gateway
     */
    public function addMoneyGovernment(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'amount' => 'required|numeric|min:1000|max:100000000', // Min 1000 Toman, Max 10M Toman
                'iban_info' => 'required|array|min:1',
                'iban_info.*.iban' => 'required|string|regex:/^IR\d{24}$/',
                'iban_info.*.amount' => 'required|numeric|min:1000',
                'iban_info.*.purchase_id' => 'required|string|max:29',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = $this->getUserFromToken($request);
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'توکن احراز هویت مورد نیاز است',
                    'code' => 'TOKEN_REQUIRED',
                ], 401);
            }
            
            $amount = (int) $request->amount; // Amount in Toman
            $amountInRial = $amount * 10; // Convert to Rial for SEP
            $ibanInfo = $request->iban_info;

            // Validate total IBAN amounts match the payment amount
            $totalIbanAmount = array_sum(array_column($ibanInfo, 'amount'));
            if ($totalIbanAmount !== $amount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Total IBAN amounts must equal the payment amount',
                ], 422);
            }

            // Format IBAN info for SEP
            $settlementIBANs = array_map(function ($iban) {
                return [
                    'IBAN' => $iban['iban'],
                    'Amount' => (int) $iban['amount'] * 10, // Convert to Rial
                    'PurchaseID' => $iban['purchase_id'],
                ];
            }, $ibanInfo);

            // Create pending transaction
            $transaction = WalletTransaction::create([
                'user_id' => $user->id,
                'type' => 'deposit',
                'amount' => $amountInRial,
                'balance_before' => $user->wallet_balance,
                'balance_after' => $user->wallet_balance,
                'status' => 'pending',
                'payment_gateway' => 'sep',
                'description' => 'افزایش موجودی کیف پول (دولتی)',
                'metadata' => [
                    'payment_type' => 'government',
                    'iban_info' => $ibanInfo,
                ],
            ]);

            // Build callback URL
            $callbackBaseUrl = config('services.sep.callback_url') 
                ? rtrim(config('services.sep.callback_url'), '/')
                : rtrim(config('app.url'), '/');
            
            // Use configured callback path or default based on whether using proxy or direct backend
            if (config('services.sep.callback_url')) {
                $callbackPath = config('services.sep.callback_path');
                if ($callbackPath) {
                    $callbackPath = '/' . ltrim($callbackPath, '/');
                    $callbackUrl = $callbackBaseUrl . $callbackPath;
                } else {
                    // No path configured, use direct backend callback
                    $callbackUrl = $callbackBaseUrl . '/api/wallet/callback';
                }
            } else {
                $callbackUrl = $callbackBaseUrl . '/api/wallet/callback';
            }
            
            $callbackUrl = rtrim($callbackUrl, '/');
            if (!str_starts_with($callbackUrl, 'http')) {
                $callbackUrl = 'https://' . ltrim($callbackUrl, '/');
            }
            
            $resNum = 'GOV-TXN-' . $transaction->id . '-' . time();

            // Request government payment from SEP
            $paymentRequest = $this->sepPaymentService->requestPayment(
                $amountInRial,
                $resNum,
                $callbackUrl,
                $user->phone ?? '',
                $settlementIBANs // Government payment with IBAN settlement
            );

            if (!$paymentRequest['success']) {
                $transaction->update(['status' => 'failed']);
                return response()->json([
                    'success' => false,
                    'message' => $paymentRequest['message'] ?? 'Payment request failed',
                ], 400);
            }

            // Update transaction with token and res_num
            $transaction->update([
                'payment_reference' => $paymentRequest['token'],
                'metadata' => array_merge($transaction->metadata ?? [], [
                    'res_num' => $resNum,
                    'token' => $paymentRequest['token'],
                ]),
            ]);

            return response()->json([
                'success' => true,
                'payment_url' => $paymentRequest['payment_url'],
                'token' => $paymentRequest['token'],
                'res_num' => $resNum,
                'transaction_id' => $transaction->id,
            ]);

        } catch (\Exception $e) {
            \Log::error('Government payment initiation failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to initiate government payment',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Initiate payment to add money to wallet via SEP gateway
     */
    public function addMoney(Request $request)
    {
        \Log::info('addMoney called with request:', $request->all());

        try {
            $validator = Validator::make($request->all(), [
                'amount' => 'required|numeric|min:1000|max:100000000', // Min 1000 Toman, Max 10M Toman
                'coins' => 'nullable|integer|min:1', // Optional coins parameter
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = $this->getUserFromToken($request);
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'توکن احراز هویت مورد نیاز است',
                    'code' => 'TOKEN_REQUIRED',
                ], 401);
            }
            
            $amount = (int) $request->amount; // Amount in Toman (IRR / 10)
            $amountInRial = $amount * 10; // Convert to Rial for SEP

            // Create pending transaction
            $transaction = WalletTransaction::create([
                'user_id' => $user->id,
                'type' => 'deposit',
                'amount' => $amountInRial,
                'balance_before' => $user->wallet_balance,
                'balance_after' => $user->wallet_balance,
                'status' => 'pending',
                'payment_gateway' => 'sep',
                'description' => 'افزایش موجودی کیف پول',
            ]);

            // Build callback URL - use configured callback URL or default
            $callbackBaseUrl = config('services.sep.callback_url') 
                ? rtrim(config('services.sep.callback_url'), '/')
                : rtrim(config('app.url'), '/');
            
            // Use configured callback path or default based on whether using proxy or direct backend
            if (config('services.sep.callback_url')) {
                // If callback path is configured, use it; otherwise use direct backend callback
                $callbackPath = config('services.sep.callback_path');
                if ($callbackPath) {
                    $callbackPath = '/' . ltrim($callbackPath, '/');
                    $callbackUrl = $callbackBaseUrl . $callbackPath;
                } else {
                    // No path configured, use direct backend callback
                    $callbackUrl = $callbackBaseUrl . '/api/wallet/callback';
                }
            } else {
                // Direct backend callback (no SEP_CALLBACK_URL set)
                $callbackUrl = $callbackBaseUrl . '/api/wallet/callback';
            }
            
            // Ensure URL is properly formatted (no trailing slash, HTTPS)
            $callbackUrl = rtrim($callbackUrl, '/');
            if (!str_starts_with($callbackUrl, 'http')) {
                $callbackUrl = 'https://' . ltrim($callbackUrl, '/');
            }
            
            $resNum = 'TXN-' . $transaction->id . '-' . time();

            \Log::info('SEP Payment Request:', [
                'amount_rial' => $amountInRial,
                'amount_toman' => $amount,
                'callback_url' => $callbackUrl,
                'res_num' => $resNum,
                'transaction_id' => $transaction->id,
            ]);

            // Request payment from SEP
            $paymentRequest = $this->sepPaymentService->requestPayment(
                $amountInRial, // SEP works with Rials
                $resNum,
                $callbackUrl,
                $user->phone ?? '', // Optional cell number
                [] // No IBAN settlement for regular payments
            );

            if (!$paymentRequest['success']) {
                $transaction->update(['status' => 'failed']);
                
                \Log::error('SEP Payment Request Failed:', [
                    'error_message' => $paymentRequest['message'] ?? 'Unknown error',
                    'error_code' => $paymentRequest['error_code'] ?? null,
                    'callback_url' => $callbackUrl,
                    'res_num' => $resNum,
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => $paymentRequest['message'] ?? 'خطا در ایجاد درخواست پرداخت',
                    'error_code' => $paymentRequest['error_code'] ?? null,
                ], 400);
            }

            // Update transaction with token and res_num
            $transaction->update([
                'payment_reference' => $paymentRequest['token'],
                'metadata' => array_merge($transaction->metadata ?? [], [
                    'res_num' => $resNum,
                    'token' => $paymentRequest['token'],
                    'amount_toman' => $amount,
                ]),
            ]);

            return response()->json([
                'success' => true,
                'payment_url' => $paymentRequest['payment_url'],
                'token' => $paymentRequest['token'],
                'res_num' => $resNum,
                'transaction_id' => $transaction->id,
            ]);

        } catch (\Exception $e) {
            \Log::error('Payment initiation failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'خطا در ایجاد درخواست پرداخت',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Payment callback from SEP
     */
    public function callback(Request $request)
    {
        $frontendUrl = config('app.frontend_url', 'https://weekilaw.com');
        
        try {
            // SEP may send data via GET (query) or POST, so check both
            $token = $request->input('Token') ?? $request->query('Token');
            $resNum = $request->input('ResNum') ?? $request->query('ResNum');
            $state = $request->input('State') ?? $request->query('State');
            $refNum = $request->input('RefNum') ?? $request->query('RefNum');
            $traceNo = $request->input('TraceNo') ?? $request->query('TraceNo');

            Log::info('SEP Callback received:', [
                'method' => $request->method(),
                'token' => $token,
                'res_num' => $resNum,
                'state' => $state,
                'ref_num' => $refNum,
                'trace_no' => $traceNo,
                'all_input' => $request->all(),
                'query_params' => $request->query->all(),
            ]);

            if (!$token || !$resNum) {
                return redirect($frontendUrl . '/account?payment=failed&message=' . urlencode('Invalid parameters'));
            }

            // Find transaction by token
            $transaction = WalletTransaction::where('payment_reference', $token)
                ->where('status', 'pending')
                ->first();

            if (!$transaction) {
                \Log::warning('Transaction not found for callback', [
                    'token' => $token,
                    'res_num' => $resNum,
                    'state' => $state,
                ]);
                return redirect($frontendUrl . '/account?payment=failed&message=' . urlencode('Transaction not found'));
            }

            // Check payment state - SEP returns different state codes
            // State = 0 or 'OK' means successful payment
            // State can be: '0' (success), 'OK' (success), or other values (cancelled/failed)
            $stateNormalized = $state ? strtoupper(trim((string)$state)) : '';
            if ($stateNormalized !== '0' && $stateNormalized !== 'OK') {
                $transaction->update(['status' => 'cancelled']);
                return redirect($frontendUrl . '/account?payment=cancelled&state=' . urlencode($state ?? 'unknown'));
            }

            // Verify payment with SEP
            $amountInRial = $transaction->amount; // Already stored in Rials
            
            // Validate amount
            if (!$amountInRial || $amountInRial <= 0) {
                \Log::error('Invalid transaction amount', [
                    'transaction_id' => $transaction->id,
                    'amount' => $amountInRial,
                ]);
                $transaction->update(['status' => 'failed']);
                return redirect($frontendUrl . '/account?payment=failed&message=' . urlencode('Invalid transaction amount'));
            }
            
            if (!$this->sepPaymentService) {
                \Log::error('SEP Payment Service not initialized');
                $transaction->update(['status' => 'failed']);
                return redirect($frontendUrl . '/account?payment=failed&message=' . urlencode('Payment service error'));
            }
            
            // Verify payment with SEP
            try {
                $verification = $this->sepPaymentService->verifyPayment($resNum, $amountInRial);
            } catch (\Exception $verifyException) {
                \Log::error('SEP verification exception: ' . $verifyException->getMessage(), [
                    'exception' => get_class($verifyException),
                    'res_num' => $resNum,
                    'amount' => $amountInRial,
                ]);
                $transaction->update(['status' => 'failed']);
                return redirect($frontendUrl . '/account?payment=failed&message=' . urlencode('Payment verification failed: ' . $verifyException->getMessage()));
            }

            if (!$verification['success']) {
                $transaction->update(['status' => 'failed']);
                $errorMessage = $verification['message'] ?? 'Payment verification failed';
                \Log::warning('SEP verification failed', [
                    'res_num' => $resNum,
                    'error' => $errorMessage,
                ]);
                return redirect($frontendUrl . '/account?payment=failed&message=' . urlencode($errorMessage));
            }

            // Complete transaction
            try {
                // Reload transaction with user relationship to ensure we have fresh data
                $transaction->load('user');
                
                DB::transaction(function () use ($transaction, $verification, $refNum, $traceNo) {
                    $user = $transaction->user;
                    if (!$user) {
                        \Log::error('User not found for transaction', [
                            'transaction_id' => $transaction->id,
                            'user_id' => $transaction->user_id,
                        ]);
                        throw new \Exception('User not found for transaction ID: ' . $transaction->id);
                    }
                    
                    $balanceBefore = $user->wallet_balance ?? 0;
                    $balanceAfter = $balanceBefore + $transaction->amount;

                    $user->update(['wallet_balance' => $balanceAfter]);

                    $existingMetadata = is_array($transaction->metadata) ? $transaction->metadata : [];
                    $newMetadata = array_merge($existingMetadata, [
                        'ref_num' => $refNum ?? $verification['ref_num'] ?? null,
                        'trace_no' => $traceNo ?? $verification['trace_no'] ?? null,
                        'verified_at' => now()->toISOString(),
                    ]);
                    
                    $transaction->update([
                        'status' => 'completed',
                        'balance_after' => $balanceAfter,
                        'transaction_id' => $refNum ?? $verification['ref_num'] ?? null,
                        'metadata' => $newMetadata,
                        'completed_at' => now(),
                    ]);
                });
            } catch (\Exception $dbException) {
                \Log::error('Database transaction error in callback: ' . $dbException->getMessage(), [
                    'exception' => $dbException,
                    'transaction_id' => $transaction->id,
                ]);
                throw $dbException; // Re-throw to be caught by outer catch
            }

            // Calculate coins from amount (amount is in Rials, 1 coin = 200,000 rials)
            $coins = floor($transaction->amount / 200000);
            return redirect($frontendUrl . '/account?payment=success&coins=' . $coins);

        } catch (\Throwable $e) {
            // Log detailed error information
            $errorDetails = [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'token' => $token ?? null,
                'res_num' => $resNum ?? null,
                'state' => $state ?? null,
                'transaction_id' => isset($transaction) ? $transaction->id : null,
            ];
            
            \Log::error('Wallet callback error: ' . $e->getMessage(), $errorDetails);
            \Log::error('Wallet callback stack trace:', ['trace' => $e->getTraceAsString()]);
            
            // Create a more descriptive error message
            $errorCode = 'ERR_' . substr(md5(get_class($e) . $e->getFile() . $e->getLine()), 0, 8);
            $errorMessage = 'Payment processing error';
            
            // Provide more specific error messages for common issues
            if (strpos($e->getMessage(), 'User not found') !== false) {
                $errorMessage = 'User account not found';
            } elseif (strpos($e->getMessage(), 'Connection') !== false || strpos($e->getMessage(), 'database') !== false) {
                $errorMessage = 'Database connection error';
            } elseif (strpos($e->getMessage(), 'SEP') !== false || strpos($e->getMessage(), 'payment') !== false) {
                $errorMessage = 'Payment gateway error';
            }
            
            // In debug mode, include more details
            if (config('app.debug')) {
                $errorMessage .= ' (' . $e->getMessage() . ')';
            }
            
            return redirect($frontendUrl . '/account?payment=failed&message=' . urlencode($errorMessage) . '&error_code=' . $errorCode);
        }
    }

    /**
     * Process payment callback and return JSON (for proxy use)
     * This endpoint is used by the proxy on whitelisted domain
     */
    public function processCallback(Request $request)
    {
        try {
            $token = $request->query('Token');
            $resNum = $request->query('ResNum');
            $state = $request->query('State');
            $refNum = $request->query('RefNum');
            $traceNo = $request->query('TraceNo');

            Log::info('SEP Callback processed via proxy:', [
                'token' => $token,
                'res_num' => $resNum,
                'state' => $state,
                'ref_num' => $refNum,
                'trace_no' => $traceNo,
            ]);

            if (!$token || !$resNum) {
                return response()->json([
                    'success' => false,
                    'redirect_url' => config('app.frontend_url') . '/account?payment=failed&message=' . urlencode('Invalid parameters'),
                ]);
            }

            // Find transaction by token
            $transaction = WalletTransaction::where('payment_reference', $token)
                ->where('status', 'pending')
                ->first();

            if (!$transaction) {
                return response()->json([
                    'success' => false,
                    'redirect_url' => config('app.frontend_url') . '/account?payment=failed&message=' . urlencode('Transaction not found'),
                ]);
            }

            // Check payment state - SEP returns different state codes
            // State = 0 or 'OK' means successful payment
            // State can be: '0' (success), 'OK' (success), or other values (cancelled/failed)
            $stateNormalized = strtoupper(trim($state ?? ''));
            if ($stateNormalized !== '0' && $stateNormalized !== 'OK') {
                $transaction->update(['status' => 'cancelled']);
                return response()->json([
                    'success' => false,
                    'redirect_url' => config('app.frontend_url') . '/account?payment=cancelled&state=' . urlencode($state),
                ]);
            }

            // Verify payment with SEP
            $amountInRial = $transaction->amount; // Already stored in Rials
            $verification = $this->sepPaymentService->verifyPayment($resNum, $amountInRial);

            if (!$verification['success']) {
                $transaction->update(['status' => 'failed']);
                return response()->json([
                    'success' => false,
                    'redirect_url' => config('app.frontend_url') . '/account?payment=failed&message=' . urlencode($verification['message']),
                ]);
            }

            // Complete transaction
            DB::transaction(function () use ($transaction, $verification, $refNum, $traceNo) {
                $user = $transaction->user;
                $balanceBefore = $user->wallet_balance;
                $balanceAfter = $balanceBefore + $transaction->amount;

                $user->update(['wallet_balance' => $balanceAfter]);

                $transaction->update([
                    'status' => 'completed',
                    'balance_after' => $balanceAfter,
                    'transaction_id' => $refNum ?? $verification['ref_num'] ?? null,
                    'metadata' => array_merge($transaction->metadata ?? [], [
                        'ref_num' => $refNum ?? $verification['ref_num'] ?? null,
                        'trace_no' => $traceNo ?? $verification['trace_no'] ?? null,
                        'verified_at' => now()->toISOString(),
                    ]),
                    'completed_at' => now(),
                ]);
            });

            // Calculate coins from amount (amount is in Rials, 1 coin = 200,000 rials)
            $coins = floor($transaction->amount / 200000);
            
            return response()->json([
                'success' => true,
                'redirect_url' => config('app.frontend_url') . '/account?payment=success&coins=' . $coins,
            ]);

        } catch (\Exception $e) {
            \Log::error('Wallet callback processing error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'redirect_url' => config('app.frontend_url') . '/account?payment=failed&message=' . urlencode('Payment processing error'),
            ]);
        }
    }

    /**
     * Get transaction history
     */
    public function getTransactions(Request $request)
    {
        try {
            $user = $this->getUserFromToken($request);
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'توکن احراز هویت مورد نیاز است',
                    'code' => 'TOKEN_REQUIRED',
                ], 401);
            }
            $perPage = $request->query('per_page', 20);
            $type = $request->query('type'); // 'deposit', 'withdrawal', 'charge'

            $query = WalletTransaction::where('user_id', $user->id)
                ->orderBy('created_at', 'desc');

            if ($type) {
                $query->where('type', $type);
            }

            $transactions = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'transactions' => $transactions->items(),
                'pagination' => [
                    'current_page' => $transactions->currentPage(),
                    'last_page' => $transactions->lastPage(),
                    'per_page' => $transactions->perPage(),
                    'total' => $transactions->total(),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get transactions',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
