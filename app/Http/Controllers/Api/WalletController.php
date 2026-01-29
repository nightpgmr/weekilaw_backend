<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WalletTransaction;
use App\Services\SEPPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class WalletController extends Controller
{
    private SEPPaymentService $sepPaymentService;

    public function __construct(SEPPaymentService $sepPaymentService)
    {
        $this->sepPaymentService = $sepPaymentService;
    }

    /**
     * Get wallet balance
     */
    public function getBalance(Request $request)
    {
        try {
            $user = Auth::user();

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
     * Initiate government payment with IBAN settlement
     */
    public function addMoneyGovernment(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'amount' => 'required|numeric|min:1000|max:100000000', // Min 1000 Rial, Max 10M Rial
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

            $user = Auth::user();
            $amount = (int) $request->amount; // Amount in Rial
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
                    'Amount' => (int) $iban['amount'],
                    'PurchaseID' => $iban['purchase_id'],
                ];
            }, $ibanInfo);

            // Create pending transaction
            $transaction = WalletTransaction::create([
                'user_id' => $user->id,
                'type' => 'deposit',
                'amount' => $amount,
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

            // Request government payment from SEP
            // Use the same callback path as Node.js (registered in SEP panel)
            $baseUrl = rtrim(config('app.url'), '/');
            $callbackUrl = $baseUrl . '/api/payment/payment-listener';
            $resNum = 'GOV-TXN-' . $transaction->id . '-' . time();

            $paymentRequest = $this->sepPaymentService->requestPayment(
                $amount,
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
            return response()->json([
                'success' => false,
                'message' => 'Failed to initiate government payment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Initiate payment to add money to wallet
     */
    public function addMoney(Request $request)
    {
        \Log::info('addMoney called with request:', $request->all());

        try {
            $validator = Validator::make($request->all(), [
                'amount' => 'required|numeric|min:1000|max:100000000', // Min 1000 Toman, Max 10M Toman
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = Auth::user();
            $amount = (int) $request->amount; // Amount in Toman (IRR / 10)

            // Create pending transaction
            $transaction = WalletTransaction::create([
                'user_id' => $user->id,
                'type' => 'deposit',
                'amount' => $amount * 10, // Convert to Rial for storage
                'balance_before' => $user->wallet_balance,
                'balance_after' => $user->wallet_balance,
                'status' => 'pending',
                'payment_gateway' => 'sep',
                'description' => 'افزایش موجودی کیف پول',
            ]);

            // Request payment from SEP
            // Use the same callback path as Node.js (registered in SEP panel)
            $baseUrl = rtrim(config('app.url'), '/');
            $callbackUrl = $baseUrl . '/api/payment/payment-listener';
            $resNum = 'TXN-' . $transaction->id . '-' . time();

            // Debug: Log service state
            \Log::info('Wallet Controller Debug:', [
                'service_class' => get_class($this->sepPaymentService),
                'amount' => $amount * 10,
                'resNum' => $resNum,
                'callbackUrl' => $callbackUrl,
                'base_url' => $baseUrl,
                'app_url_config' => config('app.url'),
            ]);

            $paymentRequest = $this->sepPaymentService->requestPayment(
                $amount * 10, // SEP works with Rials
                $resNum,
                $callbackUrl,
                $user->phone ?? '', // Optional cell number
                [] // No IBAN settlement for regular payments
            );

            if (!$paymentRequest['success']) {
                $transaction->update(['status' => 'failed']);

                
                // Log detailed error information
                \Log::error('SEP Payment Request Failed:', [
                    'error_message' => $paymentRequest['message'] ?? 'Unknown error',
                    'error_code' => $paymentRequest['error_code'] ?? null,
                    'callback_url' => $callbackUrl,
                    'res_num' => $resNum,
                    'amount' => $amount * 10,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $paymentRequest['message'] ?? 'Payment request failed',
                    'error_code' => $paymentRequest['error_code'] ?? null,
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
            return response()->json([
                'success' => false,
                'message' => 'Failed to initiate payment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Payment callback from SEP
     * SEP sends data as POST form fields, not query parameters
     */
    public function callback(Request $request)
    {
        try {
            // SEP sends data as POST body (form fields), use input() to get from either POST or query
            $token = $request->input('Token');
            $resNum = $request->input('ResNum');
            $state = $request->input('State');
            $refNum = $request->input('RefNum');
            $traceNo = $request->input('TraceNo');
            $status = $request->input('Status');
            $mid = $request->input('MID');

            \Log::info('SEP Callback received:', [
                'token' => $token,
                'res_num' => $resNum,
                'state' => $state,
                'status' => $status,
                'ref_num' => $refNum,
                'trace_no' => $traceNo,
                'mid' => $mid,
                'all_input' => $request->all(),
            ]);

            if (!$resNum) {
                \Log::error('SEP Callback: Missing ResNum parameter');
                return redirect(config('app.frontend_url', 'http://localhost:3000') . '/account?payment=failed&message=Invalid parameters');
            }

            // Find transaction by ResNum stored in metadata
            // ResNum format: TXN-{transaction_id}-{timestamp} or GOV-TXN-{transaction_id}-{timestamp}
            $transaction = WalletTransaction::where('status', 'pending')
                ->where(function ($query) use ($resNum, $token) {
                    // Try to find by ResNum in metadata
                    $query->whereJsonContains('metadata->res_num', $resNum);
                    // Or by token as fallback
                    if ($token) {
                        $query->orWhere('payment_reference', $token);
                    }
                })
                ->first();

            if (!$transaction) {
                \Log::error('SEP Callback: Transaction not found', [
                    'res_num' => $resNum,
                    'token' => $token,
                ]);
                return redirect(config('app.frontend_url', 'http://localhost:3000') . '/account?payment=failed&message=Transaction not found');
            }

            \Log::info('Transaction found:', [
                'transaction_id' => $transaction->id,
                'user_id' => $transaction->user_id,
                'amount' => $transaction->amount,
            ]);

            // Check payment state - SEP returns State field
            // State = "OK" or Status = 1 means successful payment at gateway
            // State = "CanceledByUser" or Status = 3 means user cancelled
            $isSuccess = ($state === 'OK') || ($status == '1') || ($status == 1);
            $isCancelled = ($state === 'CanceledByUser') || ($status == '3') || ($status == 3);

            \Log::info('Payment state check:', [
                'state' => $state,
                'status' => $status,
                'is_success' => $isSuccess,
                'is_cancelled' => $isCancelled,
            ]);

            if ($isCancelled) {
                $transaction->update([
                    'status' => 'cancelled',
                    'metadata' => array_merge($transaction->metadata ?? [], [
                        'cancelled_at' => now()->toISOString(),
                        'state' => $state,
                        'status' => $status,
                    ]),
                ]);
                return redirect(config('app.frontend_url', 'http://localhost:3000') . '/account?payment=cancelled');
            }

            if (!$isSuccess) {
                $transaction->update([
                    'status' => 'failed',
                    'metadata' => array_merge($transaction->metadata ?? [], [
                        'failed_at' => now()->toISOString(),
                        'state' => $state,
                        'status' => $status,
                    ]),
                ]);
                return redirect(config('app.frontend_url', 'http://localhost:3000') . '/account?payment=failed&state=' . ($state ?? $status));
            }

            // Verify payment with SEP
            $amountInRial = $transaction->amount; // Already stored in Rials
            $verification = $this->sepPaymentService->verifyPayment($resNum, $amountInRial);

            if (!$verification['success']) {
                $transaction->update(['status' => 'failed']);
                return redirect(config('app.frontend_url', 'http://localhost:3000') . '/account?payment=failed&message=' . urlencode($verification['message']));
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

            return redirect(config('app.frontend_url', 'http://localhost:3000') . '/account?payment=success&amount=' . ($transaction->amount / 10));

        } catch (\Exception $e) {
            \Log::error('Wallet callback error: ' . $e->getMessage());
            return redirect(config('app.frontend_url', 'http://localhost:3000') . '/account?payment=failed&message=' . urlencode('Payment processing error'));
        }
    }

    /**
     * Get transaction history
     */
    public function getTransactions(Request $request)
    {
        try {
            $user = Auth::user();
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
