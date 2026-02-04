<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\MongoUserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{
    protected MongoUserService $mongoUserService;

    public function __construct(MongoUserService $mongoUserService)
    {
        $this->mongoUserService = $mongoUserService;
    }
    /**
     * Update user profile
     */
    public function updateProfile(Request $request)
    {
        try {
            $user = Auth::user();

            $validator = Validator::make($request->all(), [
                'first_name' => 'nullable|string|max:255',
                'last_name' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
            }

            $user->update([
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update profile',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete user account
     */
    public function deleteAccount(Request $request)
    {
        try {
            $user = Auth::user();

            // Validate password if provided (optional security measure)
            if ($request->has('password') && $request->password) {
                if (!Hash::check($request->password, $user->password)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid password'
                    ], 422);
                }
            }

            // Delete the user
            $user->delete();

            return response()->json([
                'success' => true,
                'message' => 'Account deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete account',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user profile
     */
    public function getProfile(Request $request)
    {
        try {
            $user = Auth::user();

            // Get user data from MongoDB (source of truth)
            $mongoUser = null;
            $coins = 0;
            if ($user->phone) {
                try {
                    $mongoUser = $this->mongoUserService->findUserByPhone($user->phone);
                    if ($mongoUser && isset($mongoUser['coins'])) {
                        $coins = (int) $mongoUser['coins'];
                    }
                } catch (\Exception $e) {
                    Log::warning('[UserController] Failed to fetch MongoDB user', [
                        'phone' => $user->phone,
                        'error' => $e->getMessage(),
                    ]);
                    // Fallback to local wallet_balance if MongoDB fails
                    $coins = (int) ($user->wallet_balance ?? 0);
                }
            }

            // Get name from MongoDB if available, otherwise use local
            $userName = $mongoUser['full_name'] ?? $mongoUser['name'] ?? $user->name;

            return response()->json([
                'success' => true,
                'user' => [
                    'id' => $mongoUser['_id'] ?? (string) $user->id,
                    'name' => $userName,
                    'full_name' => $mongoUser['full_name'] ?? null,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $mongoUser['email'] ?? $user->email,
                    'phone' => $mongoUser['phone'] ?? $user->phone,
                    'coins' => $coins,
                    'wallet_balance' => (float) ($user->wallet_balance ?? 0), // Legacy support
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get profile',
                'error' => $e->getMessage()
            ], 500);
        }
    }

}
