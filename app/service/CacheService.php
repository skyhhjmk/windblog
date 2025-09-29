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

    /**
     * 获取缓存处理器
     */
    public static function getHandler()
    {
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
        $strictMode = getenv('CACHE_STRICT_MODE') ?? false;

        try {
            self::$handler = self::createHandler($cacheDriver);
            
            if (!self::testConnection(self::$handler)) {
                throw new Exception("Cache driver connection test failed: {$cacheDriver}");
            }

            self::$fallbackMode = false;
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
                            env('MEMCACHED_HOST', '127.0.0.1'),
                            env('MEMCACHED_PORT', 11211)
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
            if (method_exists($handler, 'get') && !method_exists($handler, 'setex')) {
                return true;
            }
            
            $testKey = '__cache_connection_test__';
            $testValue = 'test';
            
            $setResult = $handler->setex($testKey, 1, $testValue);
            if (!$setResult) {
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

            if ($set) {
                $serialized_value = $use_igbinary ? igbinary_serialize($value) : serialize($value);
                if ($serialized_value === false) {
                    Log::error('[cache] failed to serialize value');
                    return false;
                }

                $expire_time = $ttl ?? 86400;
                if (!is_numeric($expire_time) || $expire_time < 0) {
                    $expire_time = 86400;
                }

                $cache_ttl = $expire_time === 0 ? 0 : (int)$expire_time;
                
                $result = $cache_handler->setex($key, $cache_ttl, $serialized_value);
                if ($result === false) {
                    Log::error('[cache] failed to set cache key: ' . $key);
                    return false;
                }

                return $value;
            } else {
                $cached = $cache_handler->get($key);
                if ($cached === false) {
                    return false;
                }

                if ($use_igbinary) {
                    $unserialized = igbinary_unserialize($cached);
                    if ($unserialized === null && $cached !== igbinary_serialize(null)) {
                        $unserialized = @unserialize($cached);
                        if ($unserialized !== false) {
                            return $unserialized;
                        }
                        return $cached;
                    }
                    $return = $unserialized;
                } else {
                    if ($cached !== false && str_starts_with((string)$cached, "\x00\x00\x00\x02")) {
                        if (function_exists('igbinary_unserialize')) {
                            $unserialized = @igbinary_unserialize($cached);
                            if ($unserialized !== null) {
                                return $unserialized;
                            }
                        }
                    }

                    $unserialized = @unserialize($cached);
                    $return = $unserialized !== false ? $unserialized : $cached;
                }
            }
        } catch (Exception $e) {
            Log::error('[cache] exception: ' . $e->getMessage());
            $return = null;
        } catch (Error $e) {
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
                
                switch ($cache_driver) {
                    case 'redis':
                        $redis = \support\Redis::connection('cache');
                        $keys = $redis->keys($pattern);
                        if (!empty($keys)) {
                            return $redis->del($keys) > 0;
                        }
                        return true;
                        
                    case 'apcu':
                        $iterator = new \APCUIterator('/^' . str_replace('*', '.*', $pattern) . '$/', APC_ITER_KEY);
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
                return $cache_handler->del($pattern);
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
}