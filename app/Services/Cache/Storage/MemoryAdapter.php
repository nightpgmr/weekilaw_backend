<?php

namespace App\Services\Cache\Storage;

/**
 * In-Memory Cache Storage Adapter
 * 
 * Fast, volatile storage that doesn't survive app restarts
 */
class MemoryAdapter implements StorageAdapterInterface
{
    protected array $cache = [];
    protected array $expiry = [];
    protected int $maxSize;
    protected int $currentSize = 0;

    public function __construct(array $config = [])
    {
        $this->maxSize = $config['max_size'] ?? 100 * 1024 * 1024; // 100MB default
    }

    public function get(string $key)
    {
        // Check if expired
        if (isset($this->expiry[$key]) && $this->expiry[$key] < time()) {
            $this->delete($key);
            return null;
        }

        return $this->cache[$key] ?? null;
    }

    public function set(string $key, $value, int $ttl): bool
    {
        $serialized = serialize($value);
        $size = strlen($serialized);

        // Check memory pressure
        if ($this->currentSize + $size > $this->maxSize) {
            $this->cleanup();
        }

        $this->cache[$key] = $value;
        $this->expiry[$key] = time() + $ttl;
        $this->currentSize += $size;

        return true;
    }

    public function delete(string $key): bool
    {
        if (isset($this->cache[$key])) {
            $serialized = serialize($this->cache[$key]);
            $this->currentSize -= strlen($serialized);
            unset($this->cache[$key]);
            unset($this->expiry[$key]);
            return true;
        }

        return false;
    }

    public function clearPattern(string $pattern): int
    {
        $count = 0;
        $regex = $this->patternToRegex($pattern);

        foreach (array_keys($this->cache) as $key) {
            if (preg_match($regex, $key)) {
                if ($this->delete($key)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    public function clear(): bool
    {
        $this->cache = [];
        $this->expiry = [];
        $this->currentSize = 0;
        return true;
    }

    public function getStats(): array
    {
        return [
            'type' => 'memory',
            'keys' => count($this->cache),
            'size' => $this->currentSize,
            'max_size' => $this->maxSize,
            'usage_percent' => ($this->currentSize / $this->maxSize) * 100,
        ];
    }

    /**
     * Cleanup expired entries and free memory
     */
    protected function cleanup(): void
    {
        $now = time();
        $keysToDelete = [];

        foreach ($this->expiry as $key => $expiry) {
            if ($expiry < $now) {
                $keysToDelete[] = $key;
            }
        }

        foreach ($keysToDelete as $key) {
            $this->delete($key);
        }

        // If still over limit, remove oldest entries
        if ($this->currentSize > $this->maxSize) {
            asort($this->expiry);
            $keysToDelete = array_slice(array_keys($this->expiry), 0, count($this->expiry) / 4);
            foreach ($keysToDelete as $key) {
                $this->delete($key);
            }
        }
    }

    /**
     * Convert pattern to regex
     */
    protected function patternToRegex(string $pattern): string
    {
        $pattern = preg_quote($pattern, '/');
        $pattern = str_replace('\*', '.*', $pattern);
        $pattern = str_replace('\?', '.', $pattern);
        return '/^' . $pattern . '$/';
    }
}
