<?php

namespace App\Services\Cache\Storage;

/**
 * Storage Adapter Interface
 * 
 * All cache storage adapters must implement this interface
 */
interface StorageAdapterInterface
{
    /**
     * Get cached value
     * 
     * @param string $key Cache key
     * @return mixed|null
     */
    public function get(string $key);

    /**
     * Set cached value
     * 
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int $ttl Time to live in seconds
     * @return bool
     */
    public function set(string $key, $value, int $ttl): bool;

    /**
     * Delete cached value
     * 
     * @param string $key Cache key
     * @return bool
     */
    public function delete(string $key): bool;

    /**
     * Clear cache by pattern
     * 
     * @param string $pattern Pattern to match
     * @return int Number of keys deleted
     */
    public function clearPattern(string $pattern): int;

    /**
     * Clear all cache
     * 
     * @return bool
     */
    public function clear(): bool;

    /**
     * Get storage statistics
     * 
     * @return array
     */
    public function getStats(): array;
}
