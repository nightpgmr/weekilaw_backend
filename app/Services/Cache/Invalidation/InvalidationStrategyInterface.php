<?php

namespace App\Services\Cache\Invalidation;

/**
 * Invalidation Strategy Interface
 * 
 * All invalidation strategies must implement this interface
 */
interface InvalidationStrategyInterface
{
    /**
     * Check if strategy should invalidate for given event
     * 
     * @param string $event Event name
     * @param array $context Event context
     * @return bool
     */
    public function shouldInvalidate(string $event, array $context): bool;

    /**
     * Get cache keys to invalidate for given event
     * 
     * @param string $event Event name
     * @param array $context Event context
     * @return array Array of cache keys
     */
    public function getKeysToInvalidate(string $event, array $context): array;
}
