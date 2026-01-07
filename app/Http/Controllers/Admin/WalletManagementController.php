<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class WalletManagementController extends Controller
{
    /**
     * Get all wallet transactions
     */
    public function getTransactions(Request $request)
    {
        $perPage = $request->query('per_page', 50);
        $userId = $request->query('user_id');
        $type = $request->query('type');
        $status = $request->query('status');

        $query = WalletTransaction::with('user:id,name,email,phone')
            ->orderBy('created_at', 'desc');

        if ($userId) {
            $query->where('user_id', $userId);
        }

        if ($type) {
            $query->where('type', $type);
        }

        if ($status) {
            $query->where('status', $status);
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
    }

    /**
     * Get wallet statistics
     */
    public function getStatistics()
    {
        $totalBalance = User::sum('wallet_balance');
        $totalDeposits = WalletTransaction::where('type', 'deposit')
            ->where('status', 'completed')
            ->sum('amount');
        $totalCharges = WalletTransaction::where('type', 'charge')
            ->where('status', 'completed')
            ->sum('amount');
        $totalTransactions = WalletTransaction::count();
        $pendingTransactions = WalletTransaction::where('status', 'pending')->count();

        return response()->json([
            'success' => true,
            'statistics' => [
                'total_balance' => (float) $totalBalance,
                'total_deposits' => (float) $totalDeposits,
                'total_charges' => (float) $totalCharges,
                'total_transactions' => $totalTransactions,
                'pending_transactions' => $pendingTransactions,
            ],
        ]);
    }

    /**
     * Manually adjust user wallet balance (admin only)
     */
    public function adjustBalance(Request $request, User $user)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric',
            'type' => 'required|in:add,subtract',
            'description' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $amount = abs((float) $request->amount) * 10; // Convert Toman to Rial
            $type = $request->type;
            $description = $request->description ?? ($type === 'add' ? 'افزایش دستی موجودی توسط ادمین' : 'کاهش دستی موجودی توسط ادمین');

            DB::transaction(function () use ($user, $amount, $type, $description) {
                $balanceBefore = $user->wallet_balance;
                
                if ($type === 'add') {
                    $balanceAfter = $balanceBefore + $amount;
                    $transactionType = 'deposit';
                } else {
                    if ($balanceBefore < $amount) {
                        throw new \Exception('موجودی کافی نیست');
                    }
                    $balanceAfter = $balanceBefore - $amount;
                    $transactionType = 'charge';
                }

                $user->update(['wallet_balance' => $balanceAfter]);

                WalletTransaction::create([
                    'user_id' => $user->id,
                    'type' => $transactionType,
                    'amount' => $amount,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'status' => 'completed',
                    'description' => $description,
                    'metadata' => [
                        'admin_adjustment' => true,
                        'adjusted_by' => auth()->id(),
                    ],
                    'completed_at' => now(),
                ]);
            });

            $user->refresh();

            return response()->json([
                'success' => true,
                'message' => 'موجودی با موفقیت تغییر کرد',
                'user' => [
                    'id' => $user->id,
                    'wallet_balance' => (float) $user->wallet_balance,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
