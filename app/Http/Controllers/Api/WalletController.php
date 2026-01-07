<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WalletTransaction;
use App\Services\ZarinpalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class WalletController extends Controller
{
    private ZarinpalService $zarinpalService;

    public function __construct(ZarinpalService $zarinpalService)
    {
        $this->zarinpalService = $zarinpalService;
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
     * Initiate payment to add money to wallet
     */
    public function addMoney(Request $request)
    {
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
                'payment_gateway' => 'zarinpal',
                'description' => 'افزایش موجودی کیف پول',
            ]);

            // Request payment from Zarinpal
            $callbackUrl = config('app.url') . '/api/wallet/callback';
            $paymentRequest = $this->zarinpalService->requestPayment(
                $amount,
                "افزایش موجودی کیف پول - مبلغ {$amount} تومان",
                $callbackUrl,
                ['transaction_id' => $transaction->id]
            );

            if (!$paymentRequest['success']) {
                $transaction->update(['status' => 'failed']);
                return response()->json([
                    'success' => false,
                    'message' => $paymentRequest['message'] ?? 'Payment request failed',
                ], 400);
            }

            // Update transaction with authority
            $transaction->update([
                'payment_reference' => $paymentRequest['authority'],
            ]);

            return response()->json([
                'success' => true,
                'payment_url' => $paymentRequest['payment_url'],
                'authority' => $paymentRequest['authority'],
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
     * Payment callback from Zarinpal
     */
    public function callback(Request $request)
    {
        try {
            $authority = $request->query('Authority');
            $status = $request->query('Status');

            if (!$authority) {
                return redirect(config('app.frontend_url', 'http://localhost:3000') . '/account?payment=failed&message=Invalid authority');
            }

            // Find transaction by authority
            $transaction = WalletTransaction::where('payment_reference', $authority)
                ->where('status', 'pending')
                ->first();

            if (!$transaction) {
                return redirect(config('app.frontend_url', 'http://localhost:3000') . '/account?payment=failed&message=Transaction not found');
            }

            // Check payment status
            if ($status !== 'OK') {
                $transaction->update(['status' => 'cancelled']);
                return redirect(config('app.frontend_url', 'http://localhost:3000') . '/account?payment=cancelled');
            }

            // Verify payment with Zarinpal
            $amountInToman = (int) ($transaction->amount / 10); // Convert Rial to Toman
            $verification = $this->zarinpalService->verifyPayment($authority, $amountInToman);

            if (!$verification['success']) {
                $transaction->update(['status' => 'failed']);
                return redirect(config('app.frontend_url', 'http://localhost:3000') . '/account?payment=failed&message=' . urlencode($verification['message']));
            }

            // Complete transaction
            DB::transaction(function () use ($transaction, $verification) {
                $user = $transaction->user;
                $balanceBefore = $user->wallet_balance;
                $balanceAfter = $balanceBefore + $transaction->amount;

                $user->update(['wallet_balance' => $balanceAfter]);

                $transaction->update([
                    'status' => 'completed',
                    'balance_after' => $balanceAfter,
                    'transaction_id' => $verification['ref_id'] ?? null,
                    'metadata' => array_merge($transaction->metadata ?? [], [
                        'ref_id' => $verification['ref_id'] ?? null,
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
