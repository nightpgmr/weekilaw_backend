<?php

namespace App\Services;

use MongoDB\Client;
use MongoDB\Collection;
use Illuminate\Support\Facades\Log;

class MongoUserService
{
    protected Client $client;
    protected Collection $collection;
    protected string $database;
    protected string $collectionName;

    /**
     * Get MongoDB client instance (for use by other services)
     */
    public function getClient(): Client
    {
        return $this->client;
    }

    public function __construct()
    {
        Log::debug('[MongoUserService] Constructor called');
        
        // Check if MongoDB extension is installed
        if (!extension_loaded('mongodb')) {
            $error = 'MongoDB PHP extension is not installed. Install it with: pecl install mongodb (or enable in php.ini)';
            Log::error('[MongoUserService] ' . $error, [
                'php_version' => PHP_VERSION,
                'loaded_extensions' => implode(', ', get_loaded_extensions()),
            ]);
            throw new \RuntimeException($error);
        }
        
        $uri = config('mongodb.uri');
        $this->database = config('mongodb.database');
        $this->collectionName = config('mongodb.collection');

        Log::debug('[MongoUserService] Config loaded', [
            'uri_set' => !empty($uri),
            'uri_length' => strlen($uri ?? ''),
            'database' => $this->database,
            'collection' => $this->collectionName,
            'mongodb_extension_loaded' => true,
        ]);

        try {
            Log::debug('[MongoUserService] Attempting MongoDB connection', [
                'uri_preview' => substr($uri, 0, 50) . '...',
                'database' => $this->database,
            ]);
            
            if (!class_exists('MongoDB\Client')) {
                throw new \RuntimeException('MongoDB\Client class not found. Run: composer install (mongodb/mongodb package)');
            }
            
            $this->client = new Client($uri, [], config('mongodb.options', []));
            Log::debug('[MongoUserService] MongoDB Client created');
            
            $this->collection = $this->client->selectCollection($this->database, $this->collectionName);
            Log::debug('[MongoUserService] Collection selected');
            
            // Test connection
            $this->client->selectDatabase($this->database)->command(['ping' => 1]);
            $isLocalhost = (strpos($uri, 'localhost') !== false || strpos($uri, '127.0.0.1') !== false);
            Log::info('[MongoUserService] MongoDB connection successful', [
                'database' => $this->database,
                'collection' => $this->collectionName,
                'is_localhost' => $isLocalhost,
            ]);
            if ($isLocalhost && config('app.env') === 'production') {
                $msg = 'PRODUCTION: MONGODB_URI is localhost. Set MONGODB_URI in .env to your MongoDB Cloud URI (e.g. mongodb://USER:PASS@HOST:27017/?authSource=admin). User lookup will fail until fixed.';
                Log::error('[MongoUserService] ' . $msg, ['database' => $this->database]);
            } elseif ($isLocalhost) {
                Log::warning('[MongoUserService] Using localhost MongoDB. For production, set MONGODB_URI in .env to your cloud URI.', ['database' => $this->database]);
            }
        } catch (\Exception $e) {
            Log::error('[MongoUserService] Failed to connect to MongoDB', [
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'uri_preview' => substr($uri, 0, 50) . '...',
                'mongodb_class_exists' => class_exists('MongoDB\Client'),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Find user by phone number
     * Tries exact match then alternate formats (0938..., 938..., +989...).
     * Returns user document or null
     */
    public function findUserByPhone(string $phone): ?array
    {
        $uri = config('mongodb.uri');
        Log::debug('[MongoUserService] findUserByPhone called', [
            'phone' => $phone,
            'uri_length' => strlen($uri ?? ''),
            'database' => $this->database,
            'collection' => $this->collectionName,
        ]);

        $variants = $this->phoneQueryVariants($phone);
        $user = null;

        try {
            foreach ($variants as $variant) {
                Log::debug('[MongoUserService] Executing findOne query', [
                    'phone_variant' => $variant,
                    'collection' => $this->collectionName,
                    'database' => $this->database,
                ]);

                $user = $this->collection->findOne([
                    'phone' => $variant,
                ]);

                if ($user) {
                    break;
                }
            }

            Log::debug('[MongoUserService] Query executed', [
                'user_found' => $user !== null,
                'user_type' => $user ? get_class($user) : null,
            ]);

            if ($user) {
                // Convert BSONDocument to array
                $userArray = iterator_to_array($user);
                Log::debug('[MongoUserService] User document converted to array', [
                    'keys' => array_keys($userArray),
                    'has_id' => isset($userArray['_id']),
                ]);
                
                // Convert MongoDB ObjectId to string for JSON serialization
                if (isset($userArray['_id'])) {
                    $userArray['_id'] = (string) $userArray['_id'];
                }
                
                Log::info('[MongoUserService] User found', [
                    'phone' => $phone,
                    'user_id' => $userArray['_id'] ?? null,
                    'full_name' => $userArray['full_name'] ?? null,
                    'name' => $userArray['name'] ?? null,
                    'has_full_name' => isset($userArray['full_name']),
                    'has_name' => isset($userArray['name']),
                ]);
                
                return $userArray;
            }

            Log::debug('[MongoUserService] User not found', [
                'phone' => $phone,
                'tried_variants' => $variants,
                'collection' => $this->collectionName,
                'uri_length' => strlen($uri ?? ''),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('[MongoUserService] Error finding user', [
                'phone' => $phone,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Return phone number variants for lookup (exact, 0-prefix, digits only, +98).
     */
    protected function phoneQueryVariants(string $phone): array
    {
        $digits = preg_replace('/\D/', '', $phone);
        $variants = [$phone];
        if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            $variants[] = '0' . $digits;      // 09380587367
            $variants[] = $digits;            // 9380587367
            $variants[] = '+98' . $digits;    // +989380587367
        } elseif (strlen($digits) === 12 && (str_starts_with($digits, '98') || str_starts_with($digits, '0098'))) {
            $nine = preg_replace('/^0?0?98/', '', $digits);
            $variants[] = '0' . $nine;
            $variants[] = $nine;
            $variants[] = '+98' . $nine;
        }
        return array_values(array_unique(array_filter($variants)));
    }

    /**
     * Check if user exists and has completed registration
     * (has a real name, not placeholder)
     * Checks both 'full_name' and 'name' fields for compatibility
     */
    public function findRegisteredUserByPhone(string $phone): ?array
    {
        $user = $this->findUserByPhone($phone);
        
        if (!$user) {
            return null;
        }

        // Check if user has completed registration (has a real name)
        // MongoDB uses 'full_name', but check both for compatibility
        $name = $user['full_name'] ?? $user['name'] ?? null;
        if (empty($name) || $name === 'کاربر جدید' || $name === null) {
            if (config('app.env') !== 'production') {
                Log::debug('[MongoUserService] User exists but not registered', [
                    'phone' => $phone,
                    'full_name' => $user['full_name'] ?? null,
                    'name' => $user['name'] ?? null,
                ]);
            }
            return null;
        }

        return $user;
    }

    /**
     * Create a dev test user (dev mode only)
     */
    public function createDevUser(string $phone, string $name = 'کاربر تست'): array
    {
        if (config('app.env') === 'production') {
            throw new \Exception('Cannot create dev users in production');
        }

        try {
            $user = [
                'phone' => $phone,
                'name' => $name,
                'email' => 'dev-' . $phone . '@local.dev',
                'auth_provider' => 'phone',
                'phone_verified_at' => null,
                'created_at' => new \MongoDB\BSON\UTCDateTime(),
                'updated_at' => new \MongoDB\BSON\UTCDateTime(),
            ];

            $result = $this->collection->insertOne($user);
            $user['_id'] = (string) $result->getInsertedId();

            Log::info('[MongoUserService] Dev user created', [
                'phone' => $phone,
                'user_id' => $user['_id'],
            ]);

            return $user;
        } catch (\Exception $e) {
            Log::error('[MongoUserService] Error creating dev user', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
