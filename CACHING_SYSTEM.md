# Comprehensive Caching System Documentation

## Overview

This caching system provides a modular, comprehensive solution for caching data across the application with support for:
- In-memory caching (fast, volatile)
- Persistent storage caching (survives app restarts)
- Multiple invalidation strategies
- Backend control and monitoring

## Architecture

### Core Components

1. **CacheManager** (`app/Services/Cache/CacheManager.php`)
   - Main entry point for caching operations
   - Manages multiple storage adapters
   - Handles invalidation strategies

2. **Storage Adapters** (`app/Services/Cache/Storage/`)
   - `MemoryAdapter`: Fast in-memory storage
   - `HiveAdapter`: Persistent storage (uses Redis in Laravel, Hive in Flutter)

3. **Invalidation Strategies** (`app/Services/Cache/Invalidation/`)
   - Time-based expiration (TTL)
   - Event-driven invalidation
   - Pattern-based invalidation
   - Cascade invalidation

## Usage

### Basic Usage

```php
use App\Services\Cache\CacheManager;

$cache = app(CacheManager::class);

// Get cached value
$value = $cache->get('user:123', null, 'critical');

// Set cached value
$cache->set('user:123', $userData, 3600, 'critical');

// Delete cached value
$cache->delete('user:123');

// Clear by pattern
$cache->clearPattern('user:*');
```

### Invalidation

```php
// Invalidate on event
$cache->invalidate('user.login', ['user_id' => 123]);

// Invalidate on API call
$cache->invalidate('api.call', ['endpoint' => '/api/users', 'method' => 'POST']);
```

## Flutter Implementation

For Flutter mobile/web apps, implement Hive storage:

```dart
import 'package:hive_flutter/hive_flutter.dart';

class HiveCacheAdapter {
  late Box _box;
  
  Future<void> init() async {
    await Hive.initFlutter();
    _box = await Hive.openBox('app_cache');
  }
  
  Future<void> set(String key, dynamic value, int ttl) async {
    await _box.put(key, {
      'value': value,
      'expires_at': DateTime.now().add(Duration(seconds: ttl)).millisecondsSinceEpoch,
    });
  }
  
  dynamic get(String key) {
    final data = _box.get(key);
    if (data == null) return null;
    
    final expiresAt = data['expires_at'] as int;
    if (DateTime.now().millisecondsSinceEpoch > expiresAt) {
      _box.delete(key);
      return null;
    }
    
    return data['value'];
  }
}
```

## Backend Control Endpoints

### GET /api/cache/stats
Get cache statistics

### POST /api/cache/invalidate
Invalidate cache by pattern or key

### POST /api/cache/clear
Clear all cache

### POST /api/cache/configure
Update cache configuration

## Invalidation Strategies

1. **Time-based**: Automatic expiration based on TTL
2. **Event-based**: Invalidate on specific events (login, logout, API calls)
3. **Pattern-based**: Invalidate by key patterns (e.g., `user:*`)
4. **Cascade**: Invalidate related cached items
5. **Version-based**: Invalidate when cache version changes

## Priority Levels

- **critical**: Important data, longer TTL, higher priority
- **standard**: Normal data, default TTL
- **low**: Less important data, shorter TTL, can be evicted first

## Next Steps

1. Implement remaining invalidation strategies
2. Create Flutter Hive adapter
3. Add cache warming on app start
4. Implement stale-while-revalidate pattern
5. Add cache monitoring and analytics
