<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

class ChatController extends Controller
{
    private const EXTERNAL_API_URL = 'http://178.239.151.53:5010/chat';
    private const EXTERNAL_API_TIMEOUT = 120; // seconds

    public function ask(Request $request): JsonResponse
    {
        try {
            // Manually check for authentication since this route is not protected by auth:sanctum middleware
            $user = null;
            $token = $request->bearerToken();
            if ($token) {
                $accessToken = PersonalAccessToken::findToken($token);
                if ($accessToken && !$accessToken->cant('*')) {
                    $user = $accessToken->tokenable;
                }
            }

            // Validate the incoming request
            $request->validate([
                'message' => 'required|string|max:2000',
                'chat_id' => $user ? 'nullable|integer|exists:chats,id' : 'nullable|integer' // Don't validate existence for anonymous users
            ]);

            $message = $request->input('message');
            $chatId = $request->input('chat_id');

            // If user is not authenticated but chat_id is provided, ignore chat_id (anonymous chat)
            if (!$user && $chatId) {
                $chatId = null; // Treat as new anonymous chat
            }

            Log::info('Processing legal question', [
                'message' => substr($message, 0, 100) . '...',
                'user_id' => $user ? $user->id : null,
                'chat_id' => $chatId,
                'authenticated' => $user ? true : false
            ]);

            // Send request to external chat API with timeout
            $response = Http::timeout(self::EXTERNAL_API_TIMEOUT)
                ->post(self::EXTERNAL_API_URL, [
                    'message' => $message,
                ]);

            if ($response->successful()) {
                $responseBody = $response->body();
                
                // Log the raw response for debugging
                Log::info('External API response', [
                    'status' => $response->status(),
                    'body' => $responseBody,
                ]);

                // Try to parse JSON response
                $data = null;
                try {
                    $data = $response->json();
                } catch (\Exception $e) {
                    Log::warning('Failed to parse JSON response', [
                        'error' => $e->getMessage(),
                        'body' => $responseBody,
                    ]);
                }

                // Try different possible response formats
                $answer = null;
                if ($data && isset($data['answer'])) {
                    $answer = $data['answer'];
                } elseif ($data && isset($data['response'])) {
                    $answer = $data['response'];
                } elseif ($data && isset($data['text'])) {
                    $answer = $data['text'];
                } elseif (is_string($data)) {
                    $answer = $data;
                } elseif (!empty($responseBody) && is_string($responseBody)) {
                    // If body is a plain string, use it directly
                    $answer = $responseBody;
                } else {
                    // If response is not JSON or doesn't have expected format, log and use fallback
                    Log::warning('Unexpected external API response format', [
                        'data' => $data,
                        'body' => $responseBody,
                    ]);
                    $answer = 'پاسخ در دسترس نیست.';
                }

                // Ensure answer is not empty
                if (empty($answer) || trim($answer) === '') {
                    Log::warning('Empty answer from external API', [
                        'data' => $data,
                        'body' => $responseBody,
                    ]);
                    $answer = 'پاسخ در دسترس نیست.';
                }

                // Only save chat data if user is authenticated
                $chat = null;
                $returnedChatId = null;
                if ($user) {
                    try {
                        $chat = $this->saveChatMessage($user->id, $chatId, $message, $answer);
                        $returnedChatId = $chat->id;
                    } catch (\Exception $e) {
                        // If chat_id doesn't exist or user doesn't own it, create new chat
                        Log::warning('Failed to update existing chat, creating new one', [
                            'error' => $e->getMessage(),
                            'chat_id' => $chatId,
                            'user_id' => $user->id
                        ]);
                        $chat = $this->saveChatMessage($user->id, null, $message, $answer);
                        $returnedChatId = $chat->id;
                    }
                }

                return response()->json([
                    'answer' => $answer,
                    'chat_id' => $returnedChatId,
                    'saved' => $user ? true : false,
                    'success' => true,
                ]);
            } else {
                Log::warning('External chat API returned error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return response()->json([
                    'answer' => 'متأسفانه مشکلی پیش آمده، لطفاً دوباره تلاش کنید.',
                    'success' => false,
                ], 500);
            }

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('External chat API connection error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'answer' => 'متأسفانه، ارتباط با سرور برقرار نشد. لطفاً دوباره تلاش کنید.',
                'success' => false,
            ], 500);
        } catch (\Exception $e) {
            Log::error('Chat API error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'answer' => 'متأسفانه مشکلی پیش آمده، لطفاً دوباره تلاش کنید.',
                'success' => false,
            ], 500);
        }
    }

    /**
     * Save or update chat message
     */
    private function saveChatMessage(int $userId, ?int $chatId, string $question, string $answer): Chat
    {
        $now = now();

        if ($chatId) {
            // Update existing chat - verify it belongs to the user
            $chat = Chat::where('id', $chatId)
                ->where('user_id', $userId)
                ->first();
            
            if (!$chat) {
                // Chat doesn't exist or doesn't belong to user, create new one
                $chat = new Chat();
                $chat->user_id = $userId;
                $chat->title = mb_substr($question, 0, 50) . (mb_strlen($question) > 50 ? '...' : '');
                $messages = [];
            } else {
                // Use existing messages
                $messages = $chat->messages ?? [];
            }
        } else {
            // Create new chat
            $chat = new Chat();
            $chat->user_id = $userId;
            $chat->title = mb_substr($question, 0, 50) . (mb_strlen($question) > 50 ? '...' : '');
            $messages = [];
        }

        // Add new message
        $messages[] = [
            'question' => $question,
            'answer' => $answer,
            'timestamp' => $now->toISOString(),
        ];

        $chat->messages = $messages;
        $chat->last_message_at = $now;
        $chat->save();

        return $chat;
    }

    /**
     * Get recent chats for authenticated user
     */
    public function getRecentChats(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $limit = $request->input('limit', 10);

            $chats = Chat::where('user_id', $user->id)
                ->orderBy('last_message_at', 'desc')
                ->limit($limit)
                ->get()
                ->map(function ($chat) {
                    return [
                        'id' => $chat->id,
                        'title' => $chat->generateTitle(),
                        'last_message_at' => $chat->last_message_at,
                        'message_count' => $chat->messages ? count($chat->messages) : 0,
                        'preview' => $chat->messages && count($chat->messages) > 0
                            ? mb_substr($chat->messages[0]['question'], 0, 100) . '...'
                            : '',
                    ];
                });

            return response()->json([
                'success' => true,
                'chats' => $chats,
            ]);

        } catch (\Exception $e) {
            Log::error('Get recent chats error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت چت‌های اخیر',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get specific chat with messages
     */
    public function getChat(Request $request, int $chatId): JsonResponse
    {
        try {
            $user = Auth::user();

            $chat = Chat::where('id', $chatId)
                ->where('user_id', $user->id)
                ->firstOrFail();

            return response()->json([
                'success' => true,
                'chat' => [
                    'id' => $chat->id,
                    'title' => $chat->generateTitle(),
                    'messages' => $chat->messages ?? [],
                    'created_at' => $chat->created_at,
                    'last_message_at' => $chat->last_message_at,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Get chat error', [
                'error' => $e->getMessage(),
                'chat_id' => $chatId
            ]);

            return response()->json([
                'success' => false,
                'message' => 'چت یافت نشد',
            ], 404);
        }
    }

    /**
     * Delete a specific chat
     */
    public function deleteChat(Request $request, int $chatId): JsonResponse
    {
        try {
            $user = Auth::user();

            $chat = Chat::where('id', $chatId)
                ->where('user_id', $user->id)
                ->firstOrFail();

            $chat->delete();

            return response()->json([
                'success' => true,
                'message' => 'چت با موفقیت حذف شد',
            ]);

        } catch (\Exception $e) {
            Log::error('Delete chat error', [
                'error' => $e->getMessage(),
                'chat_id' => $chatId
            ]);

            return response()->json([
                'success' => false,
                'message' => 'حذف چت با خطا مواجه شد',
            ], 500);
        }
    }

    /**
     * Update chat title
     */
    public function updateChat(Request $request, int $chatId): JsonResponse
    {
        try {
            $request->validate([
                'title' => 'required|string|max:255'
            ]);

            $user = Auth::user();

            $chat = Chat::where('id', $chatId)
                ->where('user_id', $user->id)
                ->firstOrFail();

            $chat->title = $request->title;
            $chat->save();

            return response()->json([
                'success' => true,
                'message' => 'عنوان چت بروزرسانی شد',
                'chat' => [
                    'id' => $chat->id,
                    'title' => $chat->title,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Update chat error', [
                'error' => $e->getMessage(),
                'chat_id' => $chatId
            ]);

            return response()->json([
                'success' => false,
                'message' => 'بروزرسانی چت با خطا مواجه شد',
            ], 500);
        }
    }

    /**
     * Health check endpoint
     */
    public function health(): JsonResponse
    {
        return response()->json([
            'status' => 'healthy',
            'service' => 'legal-ai-chat'
        ]);
    }
}
