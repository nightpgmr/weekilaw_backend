<?php

namespace App\Services\Cache\Storage;

use Illuminate\Support\Facades\Log;

/**
 * Hive DB Storage Adapter
 * 
 * Persistent storage using Hive (for Flutter mobile/web)
 * Note: This is a placeholder for Flutter implementation
 * In Laravel backend, we'll use Redis/Database as persistent storage
 */
class HiveAdapter implements StorageAdapterInterface
{
    protected $connection;
    protected string $boxName;
    protected array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'box_name' => 'app_cache',
            'encryption_key' => null,
        ], $config);

        $this->boxName = $this->config['box_name'];

        // For Laravel backend, use Redis or Database as persistent storage
        // Flutter will use actual Hive implementation
        $this->connection = cache()->store('redis'); // Use Redis as persistent storage
    }

    public function get(string $key)
    {
        try {
            $value = $this->connection->get($this->getPrefixedKey($key));
            
            if ($value === null) {
                return null;
            }

            // Decrypt if encryption is enabled
            if ($this->config['encryption_key']) {
                $value = $this->decrypt($value);
            }

            return unserialize($value);
        } catch (\Exception $e) {
            Log::error('[HiveAdapter] Error getting key', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function set(string $key, $value, int $ttl): bool
    {
        try {
            $serialized = serialize($value);

            // Encrypt if encryption is enabled
            if ($this->config['encryption_key']) {
                $serialized = $this->encrypt($serialized);
            }

            return $this->connection->put($this->getPrefixedKey($key), $serialized, $ttl);
        } catch (\Exception $e) {
            Log::error('[HiveAdapter] Error setting key', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function delete(string $key): bool
    {
        try {
            return $this->connection->forget($this->getPrefixedKey($key));
        } catch (\Exception $e) {
            Log::error('[HiveAdapter] Error deleting key', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function clearPattern(string $pattern): int
    {
        // Redis doesn't support pattern deletion directly in Laravel cache
        // This would need Redis-specific implementation
        // For now, return 0
        return 0;
    }

    public function clear(): bool
    {
        try {
            // Clear all keys with prefix
            return $this->connection->flush();
        } catch (\Exception $e) {
            Log::error('[HiveAdapter] Error clearing cache', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function getStats(): array
    {
        return [
            'type' => 'hive',
            'box_name' => $this->boxName,
        ];
    }

    /**
     * Get prefixed key
     */
    protected function getPrefixedKey(string $key): string
    {
        return "hive:{$this->boxName}:{$key}";
    }

    /**
     * Encrypt value
     */
    protected function encrypt(string $value): string
    {
        // Use Laravel encryption
        return encrypt($value);
    }

    /**
     * Decrypt value
     */
    protected function decrypt(string $value): string
    {
        try {
            return decrypt($value);
        } catch (\Exception $e) {
            Log::error('[HiveAdapter] Decryption failed', [
                'error' => $e->getMessage(),
            ]);
            return $value;
        }
    }
}
