<?php

namespace App\Services\Cache;

use App\Services\Cache\Storage\StorageAdapterInterface;
use App\Services\Cache\Storage\MemoryAdapter;
use App\Services\Cache\Storage\HiveAdapter;
use App\Services\Cache\Invalidation\InvalidationStrategyInterface;
use Illuminate\Support\Facades\Log;

/**
 * Comprehensive Cache Manager
 * 
 * Handles caching with multiple storage adapters and invalidation strategies
 */
class CacheManager
{
    protected StorageAdapterInterface $primaryStorage;
    protected StorageAdapterInterface $persistentStorage;
    protected array $invalidationStrategies = [];
    protected array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'primary' => 'memory',
            'persistent' => 'hive',
            'default_ttl' => 3600, // 1 hour
            'max_memory_size' => 100 * 1024 * 1024, // 100MB
        ], $config);

        // Initialize storage adapters
        $this->primaryStorage = $this->createStorageAdapter($this->config['primary']);
        $this->persistentStorage = $this->createStorageAdapter($this->config['persistent']);
    }

    /**
     * Create storage adapter instance
     */
    protected function createStorageAdapter(string $type): StorageAdapterInterface
    {
        return match ($type) {
            'memory' => new MemoryAdapter(),
            'hive' => new HiveAdapter($this->config['hive'] ?? []),
            default => throw new \InvalidArgumentException("Unknown storage adapter: {$type}"),
        };
    }

    /**
     * Get cached value
     * 
     * @param string $key Cache key
     * @param mixed $default Default value if not found
     * @param string $priority 'critical'|'standard'|'low'
     * @return mixed
     */
    public function get(string $key, $default = null, string $priority = 'standard')
    {
        // Try primary storage first (faster)
        $value = $this->primaryStorage->get($key);
        if ($value !== null) {
            return $value;
        }

        // Try persistent storage
        $value = $this->persistentStorage->get($key);
        if ($value !== null) {
            // Store in primary storage for faster access
            $this->primaryStorage->set($key, $value, $this->config['default_ttl']);
            return $value;
        }

        return $default;
    }

    /**
     * Set cached value
     * 
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int|null $ttl Time to live in seconds
     * @param string $priority 'critical'|'standard'|'low'
     * @return bool
     */
    public function set(string $key, $value, ?int $ttl = null, string $priority = 'standard'): bool
    {
        $ttl = $ttl ?? $this->config['default_ttl'];

        // Store in both storages
        $primarySuccess = $this->primaryStorage->set($key, $value, $ttl);
        $persistentSuccess = $this->persistentStorage->set($key, $value, $ttl);

        return $primarySuccess && $persistentSuccess;
    }

    /**
     * Delete cached value
     * 
     * @param string $key Cache key
     * @return bool
     */
    public function delete(string $key): bool
    {
        $primaryDeleted = $this->primaryStorage->delete($key);
        $persistentDeleted = $this->persistentStorage->delete($key);

        return $primaryDeleted || $persistentDeleted;
    }

    /**
     * Clear cache by pattern
     * 
     * @param string $pattern Pattern to match (supports wildcards)
     * @return int Number of keys deleted
     */
    public function clearPattern(string $pattern): int
    {
        $primaryCount = $this->primaryStorage->clearPattern($pattern);
        $persistentCount = $this->persistentStorage->clearPattern($pattern);

        return max($primaryCount, $persistentCount);
    }

    /**
     * Clear all cache
     * 
     * @return bool
     */
    public function clear(): bool
    {
        $primaryCleared = $this->primaryStorage->clear();
        $persistentCleared = $this->persistentStorage->clear();

        return $primaryCleared && $persistentCleared;
    }

    /**
     * Register invalidation strategy
     * 
     * @param InvalidationStrategyInterface $strategy
     * @return void
     */
    public function registerInvalidationStrategy(InvalidationStrategyInterface $strategy): void
    {
        $this->invalidationStrategies[] = $strategy;
    }

    /**
     * Invalidate cache based on event
     * 
     * @param string $event Event name (e.g., 'user.login', 'api.call', 'button.click')
     * @param array $context Event context
     * @return int Number of keys invalidated
     */
    public function invalidate(string $event, array $context = []): int
    {
        $totalInvalidated = 0;

        foreach ($this->invalidationStrategies as $strategy) {
            if ($strategy->shouldInvalidate($event, $context)) {
                $keys = $strategy->getKeysToInvalidate($event, $context);
                foreach ($keys as $key) {
                    if ($this->delete($key)) {
                        $totalInvalidated++;
                    }
                }
            }
        }

        return $totalInvalidated;
    }

    /**
     * Get cache statistics
     * 
     * @return array
     */
    public function getStats(): array
    {
        return [
            'primary' => $this->primaryStorage->getStats(),
            'persistent' => $this->persistentStorage->getStats(),
        ];
    }
}
