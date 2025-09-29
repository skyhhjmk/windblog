<?php
namespace app\service;

use support\Log;
use Exception;
use Throwable;

class CacheService
{
    private static $handler = null;
    private static bool $fallbackMode = false;
    private static int $lastFallbackTime = 0;
    private static $failedDrivers = [];
    private static string $prefix = '';

    /**
     * 获取缓存处理器
     */
    public static function getHandler()
    {
        // 统一初始化前缀，确保fallback模式也生效
        self::$prefix = getenv('CACHE_PREFIX') ?: '';
        if (self::$handler !== null && !self::$fallbackMode) {
            return self::$handler;
        }

        if (self::$fallbackMode) {
            $currentTime = time();
            if ($currentTime - self::$lastFallbackTime > 300) {
                Log::info('[cache] fallback mode expired, trying to exit fallback mode...');
                self::$fallbackMode = false;
                self::$failedDrivers = [];
                self::$handler = null;
            } else {
                return self::createNoneHandler();
            }
        }

        $cacheDriver = getenv('CACHE_DRIVER') ?? 'redis';
        $strictMode = filter_var(getenv('CACHE_STRICT_MODE') ?: 'false', FILTER_VALIDATE_BOOLEAN);

        try {
            self::$handler = self::createHandler($cacheDriver);
            
            if (!self::testConnection(self::$handler)) {
                throw new Exception("Cache driver connection test failed: {$cacheDriver}");
            }

            self::$fallbackMode = false;
            self::$prefix = getenv('CACHE_PREFIX') ?: '';
            return self::$handler;

        } catch (Exception $e) {
            Log::error("[cache] exception: {$e->getMessage()}", ['driver' => $cacheDriver]);
            
            if ($strictMode) {
                throw $e;
            }

            self::$failedDrivers[$cacheDriver] = time();
            self::$fallbackMode = true;
            self::$lastFallbackTime = time();
            self::$handler = null;
            
            return self::createNoneHandler();
        }
    }

    /**
     * 创建缓存处理器
     */
    private static function createHandler($driver)
    {
        switch ($driver) {
            case 'redis':
                return new class {
                    private $redis;
                    
                    public function __construct()
                    {
                        $this->redis = \support\Redis::connection('cache');
                    }
                    
                    public function get(string $key)
                    {
                        try {
                            return $this->redis->get($key);
                        } catch (Exception $e) {
                            Log::error("[cache] Redis get error: {$e->getMessage()}");
                            return false;
                        }
                    }
                    
                    public function setex(string $key, int $ttl, string $value): bool
                    {
                        try {
                            return $this->redis->setex($key, $ttl, $value);
                        } catch (Exception $e) {
                            Log::error("[cache] Redis setex error: {$e->getMessage()}");
                            return false;
                        }
                    }

                    public function set(string $key, string $value): bool
                    {
                        try {
                            // 无过期时间的写入
                            return $this->redis->set($key, $value);
                        } catch (Exception $e) {
                            Log::error("[cache] Redis set error: {$e->getMessage()}");
                            return false;
                        }
                    }
                    
                    public function del(string $key): bool
                    {
                        try {
                            return $this->redis->del($key) > 0;
                        } catch (Exception $e) {
                            Log::error("[cache] Redis del error: {$e->getMessage()}");
                            return false;
                        }
                    }
                };

            case 'apcu':
                if (!extension_loaded('apcu') || !apcu_enabled()) {
                    throw new Exception('APCu extension is not loaded or not enabled');
                }
                
                return new class {
                    public function get(string $key)
                    {
                        $result = apcu_fetch($key, $success);
                        return $success ? $result : false;
                    }
                    
                    public function setex(string $key, int $ttl, string $value): bool
                    {
                        return apcu_store($key, $value, $ttl);
                    }

                    public function set(string $key, string $value): bool
                    {
                        // ttl=0 表示永久
                        return apcu_store($key, $value, 0);
                    }
                    
                    public function del(string $key): bool
                    {
                        return apcu_delete($key);
                    }
                };

            case 'memcached':
                if (!extension_loaded('memcached')) {
                    throw new Exception('Memcached extension is not loaded');
                }
                
                return new class {
                    private $memcached;
                    
                    public function __construct()
                    {
                        $this->memcached = new \Memcached();
                        $this->memcached->addServer(
                            getenv('MEMCACHED_HOST') ?: '127.0.0.1',
                            (int)(getenv('MEMCACHED_PORT') ?: 11211)
                        );
                    }
                    
                    public function get(string $key)
                    {
                        $result = $this->memcached->get($key);
                        return $this->memcached->getResultCode() === \Memcached::RES_SUCCESS ? $result : false;
                    }
                    
                    public function setex(string $key, int $ttl, string $value): bool
                    {
                        return $this->memcached->set($key, $value, $ttl);
                    }

                    public function set(string $key, string $value): bool
                    {
                        // Memcached 的 ttl=0 表示不过期
                        return $this->memcached->set($key, $value, 0);
                    }
                    
                    public function del(string $key): bool
                    {
                        return $this->memcached->delete($key);
                    }
                };

            case 'none':
                return self::createNoneHandler();

            default:
                throw new Exception("Unsupported cache driver: {$driver}");
        }
    }

    /**
     * 创建无缓存处理器
     */
    private static function createNoneHandler()
    {
        return new class {
            public function get(string $key)
            {
                return false;
            }
            
            public function setex(string $key, int $ttl, string $value): bool
            {
                return true;
            }

            public function set(string $key, string $value): bool
            {
                return true;
            }
            
            public function del(string $key): bool
            {
                return true;
            }
        };
    }

    /**
     * 测试连接
     */
    private static function testConnection($handler): bool
    {
        try {
            $testKey = self::prefixKey('__cache_connection_test__');
            $testValue = 'test';

            // 优先走 setex，失败则尝试 set
            $setOk = false;
            if (method_exists($handler, 'setex')) {
                $setOk = $handler->setex($testKey, 2, $testValue);
            }
            if (!$setOk && method_exists($handler, 'set')) {
                $setOk = $handler->set($testKey, $testValue);
            }
            if (!$setOk) {
                return false;
            }

            $getResult = $handler->get($testKey);
            return $getResult === $testValue;

        } catch (Exception $e) {
            Log::error("[cache] connection test failed: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * 获取或设置缓存
     */
    public static function cache(string $key, mixed $value = null, bool $set = false, ?int $ttl = null): mixed
    {
        try {
            if (empty($key)) {
                Log::warning('[cache] empty key provided');
                return false;
            }

            $cache_handler = self::getHandler();
            $use_igbinary = extension_loaded('igbinary');
            $cache_driver = getenv('CACHE_DRIVER') ?? 'redis';

            if ($set) {
                // 序列化策略：JSON优先，失败回退 igbinary/serialize，并打标前缀
                $serialized_value = null;
                $serializer = getenv('CACHE_SERIALIZER') ?: 'json';
                if ($serializer === 'json') {
                    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if ($json !== false && $json !== null) {
                        $serialized_value = 'json:' . $json;
                    }
                }
                if ($serialized_value === null && $use_igbinary) {
                    $bin = igbinary_serialize($value);
                    if ($bin !== false) {
                        $serialized_value = 'igb:' . $bin;
                    }
                }
                if ($serialized_value === null) {
                    $ser = serialize($value);
                    if ($ser !== false) {
                        $serialized_value = 'ser:' . $ser;
                    }
                }
                if ($serialized_value === null) {
                    Log::error('[cache] failed to serialize value');
                    return false;
                }

                // 默认TTL + 负缓存TTL + 随机抖动
                $default_ttl = (int)(getenv('CACHE_DEFAULT_TTL') ?: 86400);
                $expire_time = is_numeric($ttl) ? (int)$ttl : $default_ttl;
                if ($expire_time < 0) {
                    $expire_time = $default_ttl;
                }
                $is_negative = ($value === null) || ($value === '') || (is_array($value) && count($value) === 0);
                if ($is_negative) {
                    $neg_ttl = (int)(getenv('CACHE_NEGATIVE_TTL') ?: 30);
                    $expire_time = max(1, $neg_ttl);
                }
                // 抖动，避免同时过期雪崩
                $jitter_sec = (int)(getenv('CACHE_JITTER_SECONDS') ?: 0);
                if ($jitter_sec > 0 && $expire_time > 1) {
                    $expire_time += random_int(0, $jitter_sec);
                }
                // 设置存储键：负缓存追加 ::neg 后缀
                $storeKey = self::prefixKey($key . ($is_negative ? '::neg' : ''));

                // 统一前缀写入 + 锁释放
                if ($expire_time === 0 && method_exists($cache_handler, 'set')) {
                    $result = $cache_handler->set($storeKey, $serialized_value);
                } else {
                    $cache_ttl = (int)max(1, $expire_time);
                    $result = $cache_handler->setex($storeKey, $cache_ttl, $serialized_value);
                }
                if ($result === false) {
                    Log::error('[cache] failed to set cache key: ' . $storeKey);
                    return false;
                }
                // 结束计算，清理锁
                try {
                    $lockKey = self::prefixKey('__cache_lock:' . $key);
                    if ($cache_driver === 'redis') {
                        $redis = \support\Redis::connection('cache');
                        $redis->del($lockKey);
                    } elseif (function_exists('apcu_delete') && apcu_enabled()) {
                        apcu_delete($lockKey);
                    }
                } catch (Exception $e) {
                    Log::warning('[cache] unlock warn: ' . $e->getMessage());
                }

                return $value;
            } else {
                $prefKey = self::prefixKey($key);
                $cached = $cache_handler->get($prefKey);
                if ($cached === false) {
                    // 若存在负缓存键，直接快速返回 false
                    $negPrefKey = self::prefixKey($key . '::neg');
                    $negHit = $cache_handler->get($negPrefKey);
                    if ($negHit !== false) {
                        return false;
                    }

                    // 防缓存击穿：若有其他实例在计算，短暂等待后重试一次
                    $busyWaitMs = (int)(getenv('CACHE_BUSY_WAIT_MS') ?: 50);
                    $lockTtlMs = (int)(getenv('CACHE_LOCK_TTL_MS') ?: 3000);
                    $lockKey = self::prefixKey('__cache_lock:' . $key);
                    try {
                        if ($cache_driver === 'redis') {
                            $redis = \support\Redis::connection('cache');
                            // 尝试设置计算锁，若失败说明已有计算者
                            $setnx = $redis->set($lockKey, '1', ['nx', 'px' => $lockTtlMs]);
                            if ($setnx === false) {
                                usleep(max(0, $busyWaitMs) * 1000);
                                $cached = $cache_handler->get($prefKey);
                                if ($cached === false) {
                                    // 再次检查负缓存
                                    $negHit = $cache_handler->get($negPrefKey);
                                    if ($negHit !== false) {
                                        return false;
                                    }
                                }
                            }
                        } elseif (function_exists('apcu_add') && apcu_enabled()) {
                            if (!apcu_add($lockKey, 1, (int)ceil($lockTtlMs / 1000))) {
                                usleep(max(0, $busyWaitMs) * 1000);
                                $cached = $cache_handler->get($prefKey);
                                if ($cached === false) {
                                    $negHit = $cache_handler->get($negPrefKey);
                                    if ($negHit !== false) {
                                        return false;
                                    }
                                }
                            }
                        }
                    } catch (Exception $e) {
                        Log::warning('[cache] stampede warn: ' . $e->getMessage());
                    }
                    if ($cached === false) {
                        return false;
                    }
                }

                // 新版标记解码，兼容旧数据
                $raw = (string)$cached;
                if (str_starts_with($raw, 'json:')) {
                    $decoded = json_decode(substr($raw, 5), true);
                    $return = $decoded !== null ? $decoded : substr($raw, 5);
                } elseif (str_starts_with($raw, 'igb:')) {
                    $payload = substr($raw, 4);
                    if (function_exists('igbinary_unserialize')) {
                        $unserialized = @igbinary_unserialize($payload);
                        $return = $unserialized !== null ? $unserialized : $payload;
                    } else {
                        $unserialized = @unserialize($payload);
                        $return = $unserialized !== false ? $unserialized : $payload;
                    }
                } elseif (str_starts_with($raw, 'ser:')) {
                    $payload = substr($raw, 4);
                    $unserialized = @unserialize($payload);
                    $return = $unserialized !== false ? $unserialized : $payload;
                } else {
                    // 兼容旧逻辑
                    if ($use_igbinary) {
                        $unserialized = igbinary_unserialize($raw);
                        if ($unserialized === null && $raw !== igbinary_serialize(null)) {
                            $unserialized = @unserialize($raw);
                            $return = $unserialized !== false ? $unserialized : $raw;
                        } else {
                            $return = $unserialized;
                        }
                    } else {
                        if ($raw !== '' && str_starts_with($raw, "\x00\x00\x00\x02") && function_exists('igbinary_unserialize')) {
                            $unserialized = @igbinary_unserialize($raw);
                            if ($unserialized !== null) {
                                return $unserialized;
                            }
                        }
                        $unserialized = @unserialize($raw);
                        $return = $unserialized !== false ? $unserialized : $raw;
                    }
                }
            }
        } catch (Exception $e) {
            Log::error('[cache] exception: ' . $e->getMessage());
            $return = null;
        } catch (\Error $e) {
            Log::error('[cache] error: ' . $e->getMessage());
            $return = null;
        }

        return $return ?? false;
    }

    /**
     * 快速清除缓存
     */
    public static function clearCache(string $pattern = '*'): bool
    {
        try {
            $cache_handler = self::getHandler();
            
            if (str_contains($pattern, '*')) {
                $cache_driver = getenv('CACHE_DRIVER') ?? 'redis';
                $patternWithPrefix = self::$prefix . $pattern;

                // 安全保护：当前缀为空且请求清理 '*' 时，需要显式确认
                if (self::$prefix === '') {
                    $allowAll = filter_var(getenv('CACHE_ALLOW_CLEAR_ALL') ?: 'false', FILTER_VALIDATE_BOOLEAN);
                    if (!$allowAll) {
                        Log::warning('[clear_cache] prefix is empty and pattern contains *, refused without explicit confirmation (set CACHE_ALLOW_CLEAR_ALL=true to proceed)');
                        return false;
                    }
                }
                
                switch ($cache_driver) {
                    case 'redis':
                        $redis = \support\Redis::connection('cache');
                        // 使用非阻塞 SCAN 代替 KEYS，按前缀化正则匹配
                        $regex = '/^' . str_replace('*', '.*', preg_quote($patternWithPrefix, '/')) . '$/';
                        $cursor = 0;
                        $deleted = 0;
                        do {
                            [$cursor, $keys] = $redis->scan($cursor, ['match' => self::$prefix . '*', 'count' => 1000]);
                            if (is_array($keys) && !empty($keys)) {
                                $matchKeys = array_values(array_filter($keys, function ($k) use ($regex) {
                                    return preg_match($regex, $k) === 1;
                                }));
                                if (!empty($matchKeys)) {
                                    $deleted += $redis->del($matchKeys);
                                }
                            }
                        } while ($cursor !== 0);
                        return $deleted >= 0;
                        
                    case 'apcu':
                        $regex = '/^' . str_replace('*', '.*', preg_quote($patternWithPrefix, '/')) . '$/';
                        $iterator = new \APCUIterator($regex, APC_ITER_KEY);
                        $success = true;
                        foreach ($iterator as $key => $value) {
                            if (!apcu_delete($key)) {
                                $success = false;
                            }
                        }
                        return $success;
                        
                    case 'memcached':
                        Log::warning('[clear_cache] Memcached does not support pattern-based cache clearing');
                        return false;
                        
                    case 'none':
                        return true;
                        
                    default:
                        Log::warning("[clear_cache] Pattern-based clearing not supported for driver: {$cache_driver}");
                        return false;
                }
            } else {
                return $cache_handler->del(self::prefixKey($pattern));
            }
        } catch (Exception $e) {
            Log::error('[clear_cache] exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取缓存处理器实例（兼容旧版函数）
     */
    public static function getCacheHandler(): ?object
    {
        return self::getHandler();
    }

    /**
     * 统一前缀处理
     */
    public static function prefixKey(string $key): string
    {
        return self::$prefix . $key;
    }

    /**
     * 获取 PSR-16 轻量适配器（不依赖外部包）
     */
    public static function getPsr16Adapter(): object
    {
        return new class {
            public function get(string $key, mixed $default = null): mixed
            {
                $val = CacheService::cache($key);
                return $val !== false ? $val : $default;
            }

            public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
            {
                $seconds = null;
                if ($ttl instanceof \DateInterval) {
                    $seconds = (int)$this->intervalToSeconds($ttl);
                } elseif (is_int($ttl)) {
                    $seconds = $ttl;
                }
                return CacheService::cache($key, $value, true, $seconds) !== false;
            }

            public function delete(string $key): bool
            {
                $handler = CacheService::getCacheHandler();
                return $handler && $handler->del(CacheService::prefixKey($key));
            }

            public function clear(): bool
            {
                // 清理当前前缀下的所有键
                return CacheService::clearCache('*');
            }

            public function getMultiple(iterable $keys, mixed $default = null): iterable
            {
                $results = [];
                foreach ($keys as $key) {
                    $results[$key] = $this->get($key, $default);
                }
                return $results;
            }

            public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
            {
                $ok = true;
                foreach ($values as $key => $value) {
                    $ok = $ok && $this->set($key, $value, $ttl);
                }
                return $ok;
            }

            public function deleteMultiple(iterable $keys): bool
            {
                $ok = true;
                foreach ($keys as $key) {
                    $ok = $ok && $this->delete($key);
                }
                return $ok;
            }

            public function has(string $key): bool
            {
                $handler = CacheService::getCacheHandler();
                if (!$handler) {
                    return false;
                }
                $val = $handler->get(CacheService::prefixKey($key));
                return $val !== false;
            }

            private function intervalToSeconds(\DateInterval $interval): int
            {
                $ref = new \DateTimeImmutable();
                $end = $ref->add($interval);
                return max(0, (int)($end->getTimestamp() - $ref->getTimestamp()));
            }
        };
    }
}