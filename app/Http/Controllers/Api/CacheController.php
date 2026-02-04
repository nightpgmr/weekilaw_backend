<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Cache\CacheManager;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class CacheController extends Controller
{
    protected CacheManager $cacheManager;

    public function __construct(CacheManager $cacheManager)
    {
        $this->cacheManager = $cacheManager;
    }

    /**
     * Get cache statistics
     * GET /api/cache/stats
     */
    public function stats(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'stats' => $this->cacheManager->getStats(),
        ]);
    }

    /**
     * Invalidate cache
     * POST /api/cache/invalidate
     * 
     * Body: {
     *   "key": "user:123", // Optional: specific key
     *   "pattern": "user:*", // Optional: pattern
     *   "event": "user.login", // Optional: event name
     *   "context": {} // Optional: event context
     * }
     */
    public function invalidate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'key' => 'nullable|string',
            'pattern' => 'nullable|string',
            'event' => 'nullable|string',
            'context' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid request',
                'errors' => $validator->errors(),
            ], 422);
        }

        $count = 0;

        if ($request->has('key')) {
            // Delete specific key
            if ($this->cacheManager->delete($request->input('key'))) {
                $count = 1;
            }
        } elseif ($request->has('pattern')) {
            // Clear by pattern
            $count = $this->cacheManager->clearPattern($request->input('pattern'));
        } elseif ($request->has('event')) {
            // Invalidate by event
            $count = $this->cacheManager->invalidate(
                $request->input('event'),
                $request->input('context', [])
            );
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Either key, pattern, or event must be provided',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => "Invalidated {$count} cache entries",
            'count' => $count,
        ]);
    }

    /**
     * Clear all cache
     * POST /api/cache/clear
     */
    public function clear(): JsonResponse
    {
        $success = $this->cacheManager->clear();

        return response()->json([
            'success' => $success,
            'message' => $success ? 'Cache cleared successfully' : 'Failed to clear cache',
        ]);
    }
}
