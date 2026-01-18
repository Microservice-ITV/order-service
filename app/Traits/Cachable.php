<?php

/**
 * Planning
 *
 * - Reusable trait for caching common logic across services/repositories.
 * - Provides:
 *   - `cacheRemember` → Store and retrieve cache with TTL (default 1 hour). TTL -> time to live
 *   - `cacheForget` → Remove cache entries by key.
 * - Promotes DRY principle by centralizing cache operations.
 * - Abstracts away direct calls to Laravel `Cache` facade.
 * - Future Planning:
 *   - Extend with `cacheForever` method for permanent caching.
 *   - Consider Redis-specific optimizations for production scaling.
 */

namespace App\Traits;

use Illuminate\Support\Facades\Cache;

trait Cachable
{
    public function cacheRemember(string $key, callable $callback, int $ttl = 3600)
    {
        return Cache::remember($key, $ttl, $callback);
    }

    public function cacheRememberWithTags(string $key, callable $callback, string|array $tags, int $ttl = 3600)
    {
        return Cache::tags((array) $tags)->remember($key, $ttl, $callback);
    }

    public function cacheForget(string $key)
    {
        Cache::forget($key);
    }
}