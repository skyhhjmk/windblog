<?php

namespace app\util;

use support\Log;
use Throwable;

/**
 * CacheFacade provides a thin wrapper around the global cache() helper.
 * It centralises error handling, TTL handling and optional tagging.
 */
class CacheFacade
{
    /**
     * Retrieve a cache entry.
     *
     * @param string $key Cache key.
     *
     * @return mixed|false Returns cached data or false on miss/error.
     */
    public static function get(string $key): mixed
    {
        try {
            return cache($key);
        } catch (Throwable $e) {
            Log::error('[CacheFacade] get error: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Store a value in cache.
     *
     * @param string     $key   Cache key.
     * @param mixed      $value Data to cache.
     * @param int|null   $ttl   Seconds to live. Null = default (no expiration).
     * @param array|null $tags  Optional tags for bulk invalidation (Redis only).
     */
    public static function set(string $key, mixed $value, ?int $ttl = null, ?array $tags = null): void
    {
        try {
            // The global cache helper accepts a third boolean for "forever".
            // We use the TTL parameter: if null, use default behaviour (no TTL).
            if ($ttl !== null) {
                // Use cache($key, $value, true, $ttl) if the helper supports TTL; otherwise fallback.
                cache($key, $value, true, $ttl);
            } else {
                cache($key, $value, true);
            }

            // Tag handling for Redis
            if (!empty($tags) && self::isRedisDriver()) {
                $redis = \support\Redis::connection('cache');
                foreach ($tags as $tag) {
                    $redis->sAdd(self::getTagKey($tag), $key);
                }
            }
        } catch (Throwable $e) {
            Log::error('[CacheFacade] set error: ' . $e->getMessage());
        }
    }

    /**
     * Check if the current cache driver is Redis.
     *
     * @return bool
     */
    private static function isRedisDriver(): bool
    {
        // Check environment variable as CacheService does
        $driver = getenv('CACHE_DRIVER');

        return $driver === false || $driver === 'redis'; // Default to redis if not set
    }

    /**
     * Get the formatted key for a tag.
     *
     * @param string $tag
     *
     * @return string
     */
    private static function getTagKey(string $tag): string
    {
        $prefix = getenv('CACHE_PREFIX') ?: 'blog_';

        return $prefix . 'tag:' . $tag;
    }

    /**
     * Invalidate cache items by tags.
     *
     * @param array $tags List of tags to invalidate.
     *
     * @return void
     */
    public static function invalidateTags(array $tags): void
    {
        if (empty($tags) || !self::isRedisDriver()) {
            return;
        }

        try {
            $redis = \support\Redis::connection('cache');
            $prefix = getenv('CACHE_PREFIX') ?: 'blog_';

            foreach ($tags as $tag) {
                $tagKey = self::getTagKey($tag);
                $keys = $redis->sMembers($tagKey);
                if (!empty($keys)) {
                    // Prefix keys before deletion as CacheService prefixes them
                    $prefixedKeys = array_map(fn ($k) => $prefix . $k, $keys);
                    $redis->del(...$prefixedKeys);
                }
                $redis->del($tagKey);
            }
        } catch (Throwable $e) {
            Log::error('[CacheFacade] invalidateTags error: ' . $e->getMessage());
        }
    }
}
