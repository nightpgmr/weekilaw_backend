<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\User;
use App\Services\ExternalAuthService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\StreamedResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;

class ChatController extends Controller
{
    private const EXTERNAL_API_URL = 'http://178.239.151.53:5010/chat';
    private const EXTERNAL_API_TIMEOUT = 300; // seconds (5 minutes)
    private const EXTERNAL_API_CONNECT_TIMEOUT = 300; // seconds (connection timeout)
    
    private ExternalAuthService $authService;

    public function __construct(ExternalAuthService $authService)
    {
        $this->authService = $authService;
    }
    
    // External chat API base URL - use api.weekilaw.com/api if not configured (matches Postman collection)
    private function getExternalChatApiBaseUrl(): string
    {
        // Default to api.weekilaw.com/api (matching Postman collection baseUrl)
        // Can be overridden via config('services.chat_api.base_url')
        $baseUrl = config('services.chat_api.base_url', 'https://api.weekilaw.com/api');
        
        // If base_url doesn't include /api, add it
        $baseUrl = rtrim($baseUrl, '/');
        if (!str_ends_with($baseUrl, '/api')) {
            // Check if it's a domain without /api
            if (str_contains($baseUrl, 'weekilaw.com') && !str_contains($baseUrl, '/api')) {
                $baseUrl .= '/api';
            }
        }
        
        return $baseUrl;
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
                Log::warning('Invalid token in chat request', [
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
                Log::info('Created local user for chat', ['user_id' => $user->id, 'phone' => $user->phone]);
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

            // Get chat history if chat_id exists
            $chatHistory = [];
            if ($chatId && $user) {
                $chatHistory = $this->getChatHistory($chatId, $user->id);
                Log::info('Chat history loaded', [
                    'chat_id' => $chatId,
                    'history_count' => count($chatHistory),
                    'user_id' => $user->id
                ]);
            }

            // Prepare request data - send both 'message' (for compatibility) and 'question' + 'context'
            $requestData = [
                'message' => $message, // Keep for backward compatibility
                'question' => $message, // New format
            ];

            // Format context from chat history if available
            if (!empty($chatHistory)) {
                // Send chat history as structured array for better API understanding
                $requestData['history'] = $chatHistory;
                $requestData['messages'] = $chatHistory; // Alternative field name for compatibility
                
                // Also send as context string for backward compatibility
                $contextParts = [];
                foreach ($chatHistory as $item) {
                    if (isset($item['role']) && isset($item['content'])) {
                        $roleLabel = $item['role'] === 'user' ? 'کاربر' : 'دستیار';
                        $contextParts[] = "{$roleLabel}: {$item['content']}";
                    }
                }
                $context = implode("\n\n", $contextParts);
                $requestData['context'] = $context;
                
                Log::info('Sending chat history to external API', [
                    'history_count' => count($chatHistory),
                    'context_length' => strlen($context),
                    'first_message' => $chatHistory[0]['content'] ?? 'N/A',
                    'last_message' => end($chatHistory)['content'] ?? 'N/A',
                    'history_format' => 'structured_array'
                ]);
            } else {
                // Send empty context and history if no history
                $requestData['context'] = '';
                $requestData['history'] = [];
                $requestData['messages'] = [];
                Log::info('No chat history to send', [
                    'chat_id' => $chatId,
                    'has_user' => $user ? true : false
                ]);
            }

            // Get user phone and session for new API
            $phone = $user->phone ?? null;
            $sessionId = null;
            
            if ($phone) {
                $sessionId = $this->getOrCreateSession($user, $phone);
            }

            // Use new API endpoint if we have phone and session
            $baseUrl = $this->getExternalChatApiBaseUrl();
            $useNewApi = $phone && $sessionId;
            
            if ($useNewApi) {
                // Use new /chat/ai-message endpoint
                Log::info('Using new API endpoint /chat/ai-message', [
                    'session_id' => $sessionId,
                    'phone' => $phone,
                    'message_length' => strlen($message),
                ]);

                $response = Http::timeout(self::EXTERNAL_API_TIMEOUT)
                    ->connectTimeout(self::EXTERNAL_API_CONNECT_TIMEOUT)
                    ->post("{$baseUrl}/chat/ai-message", [
                        'message' => $message,
                        'sessionId' => $sessionId,
                        'phone' => $phone,
                    ]);
            } else {
                // Fallback to old API if no phone/session
                Log::info('Using legacy API endpoint', [
                    'url' => self::EXTERNAL_API_URL,
                    'message_length' => strlen($message),
                    'has_history' => !empty($chatHistory),
                    'history_count' => count($chatHistory),
                    'request_keys' => array_keys($requestData)
                ]);

                $response = Http::timeout(self::EXTERNAL_API_TIMEOUT)
                    ->connectTimeout(self::EXTERNAL_API_CONNECT_TIMEOUT)
                    ->post(self::EXTERNAL_API_URL, $requestData);
            }

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
                if ($useNewApi && $data) {
                    // New API format: check for ai_response
                    if (isset($data['ai_response'])) {
                        $answer = $data['ai_response'];
                    } elseif (isset($data['answer'])) {
                        $answer = $data['answer'];
                    } elseif (isset($data['response'])) {
                        $answer = $data['response'];
                    }
                }
                
                // Fallback to other formats
                if (!$answer) {
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
                            'using_new_api' => $useNewApi,
                        ]);
                        $answer = 'پاسخ در دسترس نیست.';
                    }
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
     * Get or create a session for the user
     * Returns session_id from external API
     */
    private function getOrCreateSession(?User $user, ?string $phone = null): ?string
    {
        if (!$user && !$phone) {
            return null;
        }

        $phoneNumber = $phone ?? $user->phone ?? null;
        if (!$phoneNumber) {
            Log::warning('Cannot create session: no phone number available');
            return null;
        }

        try {
            $baseUrl = $this->getExternalChatApiBaseUrl();
            
            // Try to get existing sessions first
            $sessionsResponse = Http::timeout(30)
                ->connectTimeout(10)
                ->get("{$baseUrl}/chat/sessions/{$phoneNumber}");

            if ($sessionsResponse->successful()) {
                $sessionsData = $sessionsResponse->json();
                if (isset($sessionsData['success']) && $sessionsData['success'] && !empty($sessionsData['sessions'])) {
                    // Use the most recent session
                    $sessions = $sessionsData['sessions'];
                    $latestSession = $sessions[0]; // Sessions are usually ordered by date
                    $sessionId = $latestSession['id'] ?? $latestSession['_id'] ?? null;
                    if ($sessionId) {
                        Log::info('Using existing session', ['session_id' => $sessionId, 'phone' => $phoneNumber]);
                        return $sessionId;
                    }
                }
            }

            // Create new session if none exists
            $createResponse = Http::timeout(30)
                ->connectTimeout(10)
                ->post("{$baseUrl}/chat/sessions", [
                    'phone' => $phoneNumber,
                    'sessionType' => 'general',
                ]);

            if ($createResponse->successful()) {
                $createData = $createResponse->json();
                $sessionId = $createData['session_id'] ?? null;
                if ($sessionId) {
                    Log::info('Created new session', ['session_id' => $sessionId, 'phone' => $phoneNumber]);
                    return $sessionId;
                }
            }

            Log::warning('Failed to get or create session', [
                'phone' => $phoneNumber,
                'sessions_status' => $sessionsResponse->status(),
                'create_status' => $createResponse->status(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('Error getting or creating session', [
                'error' => $e->getMessage(),
                'phone' => $phoneNumber,
            ]);
            return null;
        }
    }

    /**
     * Get chat history formatted for API
     */
    private function getChatHistory(?int $chatId, ?int $userId): array
    {
        if (!$chatId || !$userId) {
            return [];
        }

        try {
            $chat = Chat::where('id', $chatId)
                ->where('user_id', $userId)
                ->first();

            if (!$chat) {
                Log::warning('Chat not found or access denied', [
                    'chat_id' => $chatId,
                    'user_id' => $userId
                ]);
                return [];
            }

            if (!$chat->messages || empty($chat->messages)) {
                Log::info('Chat found but has no messages', [
                    'chat_id' => $chatId,
                    'user_id' => $userId
                ]);
                return [];
            }

            // Format messages for API (convert to conversation format)
            $history = [];
            foreach ($chat->messages as $msg) {
                if (isset($msg['question']) && isset($msg['answer'])) {
                    $history[] = [
                        'role' => 'user',
                        'content' => $msg['question']
                    ];
                    $history[] = [
                        'role' => 'assistant',
                        'content' => $msg['answer']
                    ];
                }
            }

            Log::info('Chat history formatted successfully', [
                'chat_id' => $chatId,
                'message_pairs' => count($chat->messages),
                'history_items' => count($history)
            ]);

            return $history;
        } catch (\Exception $e) {
            Log::warning('Failed to get chat history', [
                'error' => $e->getMessage(),
                'chat_id' => $chatId,
                'user_id' => $userId
            ]);
            return [];
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
            // Get user from JWT token (external authentication)
            $user = $this->getUserFromToken($request);
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Authentication required. Please log in first.',
                    'error' => 'Unauthenticated'
                ], 401);
            }
            
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
            // Get user from JWT token (external authentication)
            $user = $this->getUserFromToken($request);
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Authentication required. Please log in first.',
                    'error' => 'Unauthenticated'
                ], 401);
            }

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
            // Get user from JWT token (external authentication)
            $user = $this->getUserFromToken($request);
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Authentication required. Please log in first.',
                    'error' => 'Unauthenticated'
                ], 401);
            }

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

            // Get user from JWT token (external authentication)
            $user = $this->getUserFromToken($request);
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Authentication required. Please log in first.',
                    'error' => 'Unauthenticated'
                ], 401);
            }

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
     * Stream chat response (Server-Sent Events)
     */
    public function askStream(Request $request)
    {
        // Validate request first before creating stream
        try {
            $request->validate([
                'message' => 'required|string|max:2000',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.',
                'errors' => $e->errors(),
            ], 422);
        }

        try {
            return response()->stream(function () use ($request) {
                // Log that stream callback started
                Log::info('Stream callback started');
                
                // Disable all output buffering for streaming
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                
                try {
                    // Manually check for authentication
                    $user = null;
                    $token = $request->bearerToken();
                    if ($token) {
                        try {
                            $accessToken = PersonalAccessToken::findToken($token);
                            if ($accessToken && !$accessToken->cant('*')) {
                                $user = $accessToken->tokenable;
                            }
                        } catch (\Exception $e) {
                            Log::warning('Token validation error in stream', [
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }

                    // Validate chat_id if provided
                    if ($request->has('chat_id')) {
                        try {
                            $request->validate([
                                'chat_id' => $user ? 'nullable|integer|exists:chats,id' : 'nullable|integer'
                            ]);
                        } catch (\Illuminate\Validation\ValidationException $e) {
                            $this->sendSSE('error', [
                                'message' => 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.',
                            ]);
                            return;
                        }
                    }

                    $message = $request->input('message');
                    $chatId = $request->input('chat_id');

                    // If user is not authenticated but chat_id is provided, ignore chat_id
                    if (!$user && $chatId) {
                        $chatId = null;
                    }

                    Log::info('Processing streaming legal question', [
                        'message' => substr($message, 0, 100) . '...',
                        'user_id' => $user ? $user->id : null,
                        'chat_id' => $chatId,
                        'authenticated' => $user ? true : false
                    ]);

                    // Get user phone and session for new API
                    $phone = $user->phone ?? null;
                    $sessionId = null;
                    
                    if ($phone) {
                        $sessionId = $this->getOrCreateSession($user, $phone);
                    }

                    // Use new API endpoint if we have phone and session
                    $baseUrl = $this->getExternalChatApiBaseUrl();
                    $useNewApi = $phone && $sessionId;

                    if ($useNewApi) {
                        // Use new /chat/ai-chat streaming endpoint
                        Log::info('Using new streaming API endpoint /chat/ai-chat', [
                            'session_id' => $sessionId,
                            'phone' => $phone,
                            'message_length' => strlen($message),
                        ]);

                        try {
                            // Get user token if available
                            $token = $request->bearerToken();
                            $headers = [
                                'Content-Type' => 'application/json',
                                'Accept' => 'text/event-stream',
                            ];
                            if ($token) {
                                $headers['Authorization'] = "Bearer {$token}";
                            }

                            // Use Guzzle client directly for streaming
                            $client = new GuzzleClient([
                                'timeout' => self::EXTERNAL_API_TIMEOUT,
                                'connect_timeout' => self::EXTERNAL_API_CONNECT_TIMEOUT,
                                'stream' => true,
                            ]);

                            $response = $client->post("{$baseUrl}/chat/ai-chat", [
                                'headers' => $headers,
                                'body' => json_encode([
                                    'question' => $message,
                                    'session_id' => $sessionId,
                                    'phone' => $phone,
                                    'phonenumber' => $phone,
                                ]),
                            ]);

                            // Stream the response body directly (proxy SSE)
                            $body = $response->getBody();
                            $accumulatedText = '';
                            $messageId = null;
                            $responseId = null;

                            while (!$body->eof()) {
                                $chunk = $body->read(1024);
                                if ($chunk !== '') {
                                    // Forward the chunk directly
                                    echo $chunk;
                                    if (ob_get_level() > 0) {
                                        ob_flush();
                                    }
                                    flush();

                                    // Parse SSE events to extract text and metadata
                                    $lines = explode("\n", $chunk);
                                    foreach ($lines as $line) {
                                        $line = trim($line);
                                        if (str_starts_with($line, 'data: ')) {
                                            $dataStr = substr($line, 6);
                                            try {
                                                $data = json_decode($dataStr, true);
                                                if ($data) {
                                                    // Extract delta (streaming text)
                                                    if (isset($data['delta'])) {
                                                        $accumulatedText .= $data['delta'];
                                                        // Send as chunk event for frontend compatibility
                                                        $this->sendSSE('chunk', ['text' => $data['delta']]);
                                                    }
                                                    // Extract session_id and messageId
                                                    if (isset($data['type']) && $data['type'] === 'session_id') {
                                                        $messageId = $data['messageId'] ?? null;
                                                    }
                                                    // Extract response_id
                                                    if (isset($data['type']) && $data['type'] === 'response_id') {
                                                        $responseId = $data['responseId'] ?? null;
                                                    }
                                                    // Handle done event
                                                    if (isset($data['type']) && $data['type'] === 'done') {
                                                        // Save chat if user is authenticated
                                                        if ($user && $accumulatedText) {
                                                            try {
                                                                $chat = $this->saveChatMessage($user->id, $chatId, $message, $accumulatedText);
                                                                $this->sendSSE('metadata', [
                                                                    'chat_id' => $chat->id,
                                                                    'saved' => true,
                                                                ]);
                                                            } catch (\Exception $e) {
                                                                Log::warning('Failed to save chat in new stream', [
                                                                    'error' => $e->getMessage(),
                                                                ]);
                                                            }
                                                        }
                                                        $this->sendSSE('done', [
                                                            'chat_id' => $chatId,
                                                            'saved' => $user ? true : false,
                                                        ]);
                                                        return;
                                                    }
                                                    // Handle errors
                                                    if (isset($data['type']) && $data['type'] === 'ai_error') {
                                                        $this->sendSSE('error', [
                                                            'message' => $data['error'] ?? 'خطا در دریافت پاسخ از هوش مصنوعی',
                                                        ]);
                                                        return;
                                                    }
                                                }
                                            } catch (\Exception $e) {
                                                // Continue parsing other lines
                                            }
                                        }
                                    }
                                }
                            }
                        } catch (RequestException $e) {
                            Log::error('New streaming API error', [
                                'error' => $e->getMessage(),
                            ]);
                            $this->sendSSE('error', [
                                'message' => 'خطا در ارتباط با سرویس AI',
                            ]);
                            return;
                        } catch (\Exception $e) {
                            Log::error('New streaming API error', [
                                'error' => $e->getMessage(),
                            ]);
                            $this->sendSSE('error', [
                                'message' => 'خطا در دریافت پاسخ از هوش مصنوعی',
                            ]);
                            return;
                        }
                    } else {
                        // Fallback to old API
                        // Get chat history if chat_id exists
                        $chatHistory = [];
                        if ($chatId && $user) {
                            $chatHistory = $this->getChatHistory($chatId, $user->id);
                            Log::info('Chat history loaded for stream', [
                                'chat_id' => $chatId,
                                'history_count' => count($chatHistory),
                                'user_id' => $user->id
                            ]);
                        }

                        // Prepare request data - send both 'message' (for compatibility) and 'question' + 'context'
                        $requestData = [
                            'message' => $message, // Keep for backward compatibility
                            'question' => $message, // New format
                        ];

                        // Format context from chat history if available
                        if (!empty($chatHistory)) {
                            // Send chat history as structured array for better API understanding
                            $requestData['history'] = $chatHistory;
                            $requestData['messages'] = $chatHistory; // Alternative field name for compatibility
                            
                            // Also send as context string for backward compatibility
                            $contextParts = [];
                            foreach ($chatHistory as $item) {
                                if (isset($item['role']) && isset($item['content'])) {
                                    $roleLabel = $item['role'] === 'user' ? 'کاربر' : 'دستیار';
                                    $contextParts[] = "{$roleLabel}: {$item['content']}";
                                }
                            }
                            $context = implode("\n\n", $contextParts);
                            $requestData['context'] = $context;
                            
                            Log::info('Sending chat history to external API (stream)', [
                                'history_count' => count($chatHistory),
                                'context_length' => strlen($context),
                                'first_message' => $chatHistory[0]['content'] ?? 'N/A',
                                'last_message' => end($chatHistory)['content'] ?? 'N/A',
                                'history_format' => 'structured_array'
                            ]);
                        } else {
                            // Send empty context and history if no history
                            $requestData['context'] = '';
                            $requestData['history'] = [];
                            $requestData['messages'] = [];
                            Log::info('No chat history to send (stream)', [
                                'chat_id' => $chatId,
                                'has_user' => $user ? true : false
                            ]);
                        }

                        // Send request to external chat API with timeout
                        try {
                            Log::info('Sending request to legacy API', [
                                'url' => self::EXTERNAL_API_URL,
                                'message_length' => strlen($message),
                                'message_preview' => substr($message, 0, 100),
                                'has_history' => !empty($chatHistory),
                                'history_count' => count($chatHistory),
                                'request_keys' => array_keys($requestData)
                            ]);
                            
                            $response = Http::timeout(self::EXTERNAL_API_TIMEOUT)
                                ->connectTimeout(self::EXTERNAL_API_CONNECT_TIMEOUT)
                                ->post(self::EXTERNAL_API_URL, $requestData);
                            
                            Log::info('External API response received', [
                                'status' => $response->status(),
                                'successful' => $response->successful(),
                            ]);
                        } catch (\Illuminate\Http\Client\ConnectionException $e) {
                            Log::error('External chat API connection error in stream', [
                                'error' => $e->getMessage(),
                                'message' => substr($message, 0, 100),
                            ]);

                            $this->sendSSE('error', [
                                'message' => 'متأسفانه، ارتباط با سرور برقرار نشد. لطفاً دوباره تلاش کنید.',
                            ]);
                            return;
                        } catch (\Exception $e) {
                            Log::error('External chat API error in stream', [
                                'error' => $e->getMessage(),
                                'type' => get_class($e),
                                'message' => substr($message, 0, 100),
                            ]);

                            $this->sendSSE('error', [
                                'message' => 'متأسفانه، مشکلی در ارتباط با سرور پیش آمد. لطفاً دوباره تلاش کنید.',
                            ]);
                            return;
                        }

                        // Process old API response (only if we're using fallback)
                        if ($response->successful()) {
                            $responseBody = $response->body();
                        
                        Log::info('External API response body', [
                            'body_length' => strlen($responseBody),
                            'body_preview' => substr($responseBody, 0, 200),
                        ]);
                        
                        // Try to parse JSON response
                        $data = null;
                        try {
                            $data = $response->json();
                            Log::info('Successfully parsed JSON response', [
                                'has_answer' => isset($data['answer']),
                                'has_response' => isset($data['response']),
                                'has_text' => isset($data['text']),
                            ]);
                        } catch (\Exception $e) {
                            Log::warning('Failed to parse JSON response in stream', [
                                'error' => $e->getMessage(),
                                'body_preview' => substr($responseBody, 0, 200),
                            ]);
                        }

                        // Extract answer from response
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
                            $answer = $responseBody;
                        } else {
                            Log::warning('Could not extract answer from response', [
                                'data' => $data,
                                'response_body' => substr($responseBody, 0, 200),
                            ]);
                            $answer = 'پاسخ در دسترس نیست.';
                        }

                        // Ensure answer is not empty
                        if (empty($answer) || trim($answer) === '') {
                            Log::warning('Answer is empty after extraction', [
                                'data' => $data,
                                'response_body' => substr($responseBody, 0, 200),
                            ]);
                            $answer = 'پاسخ در دسترس نیست.';
                        }
                        
                        Log::info('Answer extracted successfully', [
                            'answer_length' => strlen($answer),
                            'answer_preview' => substr($answer, 0, 100),
                        ]);

                        // Stream the answer in chunks
                        $chunkSize = 10; // Characters per chunk
                        $fullAnswer = $answer;
                        $returnedChatId = null;

                        // Send initial metadata
                        $this->sendSSE('metadata', [
                            'chat_id' => null,
                            'saved' => false,
                        ]);

                        // Stream answer in chunks
                        $answerLength = mb_strlen($fullAnswer, 'UTF-8');
                        Log::info('Starting to stream answer', [
                            'answer_length' => $answerLength,
                            'chunk_size' => $chunkSize,
                        ]);
                        
                        for ($i = 0; $i < $answerLength; $i += $chunkSize) {
                            $chunk = mb_substr($fullAnswer, $i, $chunkSize, 'UTF-8');
                            $this->sendSSE('chunk', ['text' => $chunk]);
                            
                            // Small delay to simulate streaming (optional, can be removed)
                            usleep(50000); // 50ms delay
                        }
                        
                        Log::info('Finished streaming answer');

                        // Save chat data if user is authenticated
                        if ($user) {
                            try {
                                $chat = $this->saveChatMessage($user->id, $chatId, $message, $fullAnswer);
                                $returnedChatId = $chat->id;
                                
                                // Send updated metadata with chat_id
                                $this->sendSSE('metadata', [
                                    'chat_id' => $returnedChatId,
                                    'saved' => true,
                                ]);
                            } catch (\Exception $e) {
                                Log::warning('Failed to save chat in stream', [
                                    'error' => $e->getMessage(),
                                ]);
                                try {
                                    $chat = $this->saveChatMessage($user->id, null, $message, $fullAnswer);
                                    $returnedChatId = $chat->id;
                                    $this->sendSSE('metadata', [
                                        'chat_id' => $returnedChatId,
                                        'saved' => true,
                                    ]);
                                } catch (\Exception $e2) {
                                    Log::error('Failed to create new chat in stream', [
                                        'error' => $e2->getMessage(),
                                    ]);
                                }
                            }
                        }

                            // Send completion signal
                            $this->sendSSE('done', [
                                'chat_id' => $returnedChatId,
                                'saved' => $user ? true : false,
                            ]);

                        } else {
                            // Response was not successful
                            $statusCode = $response->status();
                            $responseBody = $response->body();
                            
                            Log::error('External chat API returned error in stream', [
                                'status' => $statusCode,
                                'body' => substr($responseBody, 0, 500),
                                'message' => substr($message, 0, 100),
                                'request_data_keys' => array_keys($requestData),
                                'has_context' => isset($requestData['context']),
                                'context_length' => isset($requestData['context']) ? strlen($requestData['context']) : 0,
                            ]);

                            // Try to extract error message from response
                            $errorMessage = 'متأسفانه مشکلی پیش آمده، لطفاً دوباره تلاش کنید.';
                            try {
                                $errorData = json_decode($responseBody, true);
                                if (isset($errorData['message']) || isset($errorData['error'])) {
                                    $errorMessage = $errorData['message'] ?? $errorData['error'] ?? $errorMessage;
                                } elseif (!empty($responseBody) && is_string($responseBody) && strlen($responseBody) < 500) {
                                    $errorMessage = $responseBody;
                                }
                            } catch (\Exception $e) {
                                // Use default error message
                            }

                            $this->sendSSE('error', [
                                'message' => $errorMessage,
                            ]);
                        }
                    }
                } catch (\Exception $e) {
                    Log::error('Chat streaming API error', [
                        'error' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    try {
                        $this->sendSSE('error', [
                            'message' => 'متأسفانه مشکلی پیش آمده، لطفاً دوباره تلاش کنید.',
                        ]);
                    } catch (\Exception $sendError) {
                        // If we can't send SSE, at least log it
                        Log::error('Failed to send error SSE', [
                            'error' => $sendError->getMessage(),
                            'original_error' => $e->getMessage(),
                        ]);
                    }
                }
            }, 200, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'Connection' => 'keep-alive',
                'X-Accel-Buffering' => 'no', // Disable buffering in nginx
            ]);
        } catch (\Exception $e) {
            // If we can't even create the stream response, log and return error
            Log::error('Failed to create stream response', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // Return a regular JSON error response
            return response()->json([
                'success' => false,
                'message' => 'متأسفانه مشکلی پیش آمده، لطفاً دوباره تلاش کنید.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Send Server-Sent Event
     */
    private function sendSSE(string $event, array $data): void
    {
        try {
            $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($jsonData === false) {
                Log::warning('Failed to encode SSE data', [
                    'event' => $event,
                    'data' => $data,
                    'json_error' => json_last_error_msg(),
                ]);
                $jsonData = json_encode(['message' => 'خطا در پردازش داده'], JSON_UNESCAPED_UNICODE);
            }
            
            echo "event: {$event}\n";
            echo "data: {$jsonData}\n\n";
            
            // Flush output buffers
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        } catch (\Exception $e) {
            Log::error('Failed to send SSE event', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
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

    /**
     * Test streaming endpoint (for debugging)
     */
    public function testStream(): StreamedResponse
    {
        return response()->stream(function () {
            // Disable output buffering
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            
            $this->sendSSE('test', ['message' => 'Stream test successful']);
            $this->sendSSE('done', ['status' => 'ok']);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Create Chat Session - POST /chat/sessions
     */
    public function createSession(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'phone' => 'required|string',
                'sessionType' => 'nullable|string|in:general,legal,other',
            ]);

            $phone = $request->input('phone');
            $sessionType = $request->input('sessionType', 'general');
            $baseUrl = $this->getExternalChatApiBaseUrl();

            $response = Http::timeout(self::EXTERNAL_API_TIMEOUT)
                ->connectTimeout(self::EXTERNAL_API_CONNECT_TIMEOUT)
                ->post("{$baseUrl}/chat/sessions", [
                    'phone' => $phone,
                    'sessionType' => $sessionType,
                ]);

            if ($response->successful()) {
                return response()->json($response->json());
            }

            return response()->json([
                'success' => false,
                'message' => 'خطا در ایجاد جلسه چت',
            ], $response->status());

        } catch (\Exception $e) {
            Log::error('Create session error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطا در ایجاد جلسه چت',
            ], 500);
        }
    }

    /**
     * Send AI Message - POST /chat/ai-message
     */
    public function sendAiMessage(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'message' => 'required|string',
                'sessionId' => 'required|string',
                'phone' => 'required|string',
            ]);

            $message = $request->input('message');
            $sessionId = $request->input('sessionId');
            $phone = $request->input('phone');
            $baseUrl = $this->getExternalChatApiBaseUrl();

            $response = Http::timeout(self::EXTERNAL_API_TIMEOUT)
                ->connectTimeout(self::EXTERNAL_API_CONNECT_TIMEOUT)
                ->post("{$baseUrl}/chat/ai-message", [
                    'message' => $message,
                    'sessionId' => $sessionId,
                    'phone' => $phone,
                ]);

            if ($response->successful()) {
                return response()->json($response->json());
            }

            return response()->json([
                'success' => false,
                'message' => 'خطا در ارسال پیام',
            ], $response->status());

        } catch (\Exception $e) {
            Log::error('Send AI message error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطا در ارسال پیام',
            ], 500);
        }
    }

    /**
     * Get Sessions by Phone - GET /chat/sessions/:phonenumber
     */
    public function getSessionsByPhone(Request $request, string $phonenumber): JsonResponse
    {
        try {
            $baseUrl = $this->getExternalChatApiBaseUrl();

            $response = Http::timeout(self::EXTERNAL_API_TIMEOUT)
                ->connectTimeout(self::EXTERNAL_API_CONNECT_TIMEOUT)
                ->get("{$baseUrl}/chat/sessions/{$phonenumber}");

            if ($response->successful()) {
                return response()->json($response->json());
            }

            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت جلسات',
            ], $response->status());

        } catch (\Exception $e) {
            Log::error('Get sessions by phone error', [
                'error' => $e->getMessage(),
                'phone' => $phonenumber,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت جلسات',
            ], 500);
        }
    }

    /**
     * Get Session Messages - GET /chat/sessions/:sessionId/messages
     */
    public function getSessionMessages(Request $request, string $sessionId): JsonResponse
    {
        try {
            $baseUrl = $this->getExternalChatApiBaseUrl();

            $response = Http::timeout(self::EXTERNAL_API_TIMEOUT)
                ->connectTimeout(self::EXTERNAL_API_CONNECT_TIMEOUT)
                ->get("{$baseUrl}/chat/sessions/{$sessionId}/messages");

            if ($response->successful()) {
                return response()->json($response->json());
            }

            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت پیام‌ها',
            ], $response->status());

        } catch (\Exception $e) {
            Log::error('Get session messages error', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت پیام‌ها',
            ], 500);
        }
    }

    /**
     * AI Chat (Streaming) - POST /chat/ai-chat
     * This uses a different SSE format than askStream
     */
    public function aiChat(Request $request)
    {
        try {
            $request->validate([
                'question' => 'required|string',
                'session_id' => 'required|string',
                'phone' => 'nullable|string',
                'phonenumber' => 'nullable|string',
            ]);

            $baseUrl = $this->getExternalChatApiBaseUrl();
            $phone = $request->input('phone') ?: $request->input('phonenumber');

            if (!$phone) {
                return response()->json([
                    'error' => 'شماره تلفن برای کاربران ثبت‌نام شده الزامی است',
                ], 400);
            }

            return response()->stream(function () use ($request, $baseUrl, $phone) {
                // Disable all output buffering for streaming
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }

                try {
                    // Get user token if available
                    $token = $request->bearerToken();
                    $headers = [
                        'Content-Type' => 'application/json',
                        'Accept' => 'text/event-stream',
                    ];
                    if ($token) {
                        $headers['Authorization'] = "Bearer {$token}";
                    }

                    // Use Guzzle client directly for streaming
                    $client = new GuzzleClient([
                        'timeout' => self::EXTERNAL_API_TIMEOUT,
                        'connect_timeout' => self::EXTERNAL_API_CONNECT_TIMEOUT,
                        'stream' => true,
                    ]);

                    $response = $client->post("{$baseUrl}/chat/ai-chat", [
                        'headers' => $headers,
                        'body' => json_encode([
                            'question' => $request->input('question'),
                            'session_id' => $request->input('session_id'),
                            'phone' => $phone,
                            'phonenumber' => $phone,
                        ]),
                    ]);

                    // Stream the response body directly
                    $body = $response->getBody();
                    while (!$body->eof()) {
                        $chunk = $body->read(1024);
                        if ($chunk !== '') {
                            echo $chunk;
                            if (ob_get_level() > 0) {
                                ob_flush();
                            }
                            flush();
                        }
                    }
                } catch (RequestException $e) {
                    Log::error('AI Chat stream error', [
                        'error' => $e->getMessage(),
                    ]);

                    $this->sendSSEData([
                        'error' => 'خطا در ارتباط با سرویس AI: ' . $e->getMessage(),
                        'type' => 'ai_error',
                    ]);
                    $this->sendSSEData([
                        'type' => 'done',
                        'error' => true,
                    ]);
                } catch (\Exception $e) {
                    Log::error('AI Chat stream error', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    $this->sendSSEData([
                        'error' => 'دریافت پاسخ از هوش مصنوعی با مشکل مواجه شد',
                        'type' => 'ai_error',
                    ]);
                    $this->sendSSEData([
                        'type' => 'done',
                    ]);
                }
            }, 200, [
                'Content-Type' => 'text/event-stream; charset=utf-8',
                'Cache-Control' => 'no-cache',
                'Connection' => 'keep-alive',
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Headers' => 'Cache-Control',
                'X-Accel-Buffering' => 'no',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'error' => 'سؤال یا فایل نامعتبر است',
                'details' => 'لطفاً سوال خود را وارد کنید یا فایل بارگذاری کنید',
            ], 400);
        } catch (\Exception $e) {
            Log::error('AI Chat endpoint error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'خطا در پردازش درخواست',
            ], 500);
        }
    }

    /**
     * Send SSE data in the format expected by /chat/ai-chat
     */
    private function sendSSEData(array $data): void
    {
        try {
            $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($jsonData === false) {
                $jsonData = json_encode(['error' => 'خطا در پردازش داده'], JSON_UNESCAPED_UNICODE);
            }
            echo "data: {$jsonData}\n\n";
            
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        } catch (\Exception $e) {
            Log::error('Failed to send SSE data', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get AI Models - GET /chat/models
     */
    public function getModels(Request $request): JsonResponse
    {
        try {
            $baseUrl = $this->getExternalChatApiBaseUrl();

            // Get user token if available
            $token = $request->bearerToken();
            $headers = [];
            if ($token) {
                $headers['Authorization'] = "Bearer {$token}";
            }

            $response = Http::timeout(self::EXTERNAL_API_TIMEOUT)
                ->connectTimeout(self::EXTERNAL_API_CONNECT_TIMEOUT)
                ->withHeaders($headers)
                ->get("{$baseUrl}/chat/models");

            if ($response->successful()) {
                return response()->json($response->json());
            }

            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت مدل‌های AI',
            ], $response->status());

        } catch (\Exception $e) {
            Log::error('Get AI models error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت مدل‌های AI',
            ], 500);
        }
    }
}
