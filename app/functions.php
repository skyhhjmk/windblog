<?php
/**
 * Here is your custom functions.
 */

use support\Log;

/**
 * get cache or set cache(and return set value)
 * 获取或设置缓存（并返回设置的值），不存在时返回默认值或false。
 * 输入原始值，输出原始值，内部序列化存储，外部反序列化返回
 *
 * @param string     $key   cache key 缓存键
 * @param mixed|null $value cache value|default 缓存值|默认返回值
 * @param bool       $set   set cache 是否设置缓存
 *
 * @return mixed
 * @throws Throwable
 */
function cache(string $key, mixed $value = null, bool $set = false): mixed
{
    try {
        // 检查参数
        if (empty($key)) {
            Log::warning('[cache] empty key provided');
            return false;
        }

        $redis = \support\Redis::connection('cache');

        // 确定使用的序列化方法
        $use_igbinary = extension_loaded('igbinary');

        if ($set) {
            // 设置缓存
            $serialized_value = $use_igbinary ? igbinary_serialize($value) : serialize($value);
            if ($serialized_value === false) {
                Log::error('[cache] failed to serialize value with ' . ($use_igbinary ? 'igbinary' : 'serialize'));
                return false;
            }

            $expire_time = blog_config('cache_expire', 86400/* 24小时 */);
            if (!is_numeric($expire_time) || $expire_time <= 0) {
                $expire_time = 86400; // 默认24小时
            }

            $result = $redis->setex($key, (int)$expire_time, $serialized_value);
            if ($result === false) {
                Log::error('[cache] failed to set cache key: ' . $key);
                return false;
            }

            return $value;
        } else {
            // 获取缓存
            $cached = $redis->get($key);
            if ($cached === false || $cached === null) {
                // 缓存不存在或已过期
                return false;
            }

            // 反序列化
            if ($use_igbinary) {
                $unserialized = igbinary_unserialize($cached);
                // igbinary_unserialize失败时返回NULL
                if ($unserialized === null && $cached !== igbinary_serialize(null)) {
                    // 可能是从不支持igbinary的环境切换过来的数据，尝试使用PHP原生unserialize
                    Log::debug('[cache] igbinary_unserialize failed, trying unserialize for key: ' . $key);
                    $unserialized = @unserialize($cached);
                    if ($unserialized !== false) {
                        Log::info('[cache] successfully unserialized with unserialize for key: ' . $key);
                        return $unserialized;
                    }
                    Log::warning('[cache] both igbinary_unserialize and unserialize failed for key: ' . $key);
                    return $cached; // 返回原始值
                }
                $return = $unserialized;
            } else {
                // 检查是否是从支持igbinary的环境切换过来的数据
                // igbinary序列化的数据以\x00\x00\x00\x02开头
                if (str_starts_with($cached, "\x00\x00\x00\x02")) {
                    // 尝试使用igbinary反序列化
                    if (function_exists('igbinary_unserialize')) {
                        $unserialized = @igbinary_unserialize($cached);
                        if ($unserialized !== null) {
                            Log::info('[cache] successfully unserialized with igbinary_unserialize for key: ' . $key);
                            return $unserialized;
                        }
                    }
                    Log::warning('[cache] data appears to be igbinary serialized but igbinary extension not available for key: ' . $key);
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
 * 获取博客配置，获取所有配置项不需要传参，并且不使用缓存
 * 输入原始值，输出原始值。内部序列化存储，外部反序列化返回
 *
 * @param string     $cache_key key in database
 * @param mixed|null $default   default value
 * @param bool       $set       set default value to database
 * @param bool       $use_cache use cache
 * @param bool       $init
 *
 * @return mixed
 * @throws Throwable
 */
function blog_config(string $cache_key, mixed $default = null, bool $init = false, bool $use_cache = true, bool $set = false): mixed
{
    $cache_key = trim($cache_key);
    $fullCacheKey = 'blog_config_' . $cache_key;

    // 空key返回全量配置（建议后续优化为分页或缓存）
    if ($cache_key === '') {
        return app\model\Setting::all();
    }

    // 优先处理写操作
    if ($set) {
        return blog_config_write($cache_key, $fullCacheKey, $default, $use_cache);
    }

    // 读操作主流程
    return blog_config_read($cache_key, $fullCacheKey, $default, $init, $use_cache);
}

/**
 * 处理配置写入操作（单一职责）
 */
function blog_config_write(string $cache_key, string $fullCacheKey, mixed $value, bool $use_cache): mixed
{
    try {
        // 原子操作：查找或创建记录
        $setting = app\model\Setting::firstOrNew(['key' => $cache_key]);
        $setting->value = blog_config_convert_to_storage($value);
        $setting->save();

        // 更新缓存
        if ($use_cache) {
            cache($fullCacheKey, $value, true);
        }
        return $value;
    } catch (Exception|Error $e) {
        Log::error("[blog_config] 写入失败 (key: {$cache_key}): " . $e->getMessage());
        return $value;
    }
}

/**
 * 处理配置读取操作（单一职责）
 */
function blog_config_read(string $cache_key, string $fullCacheKey, mixed $default, bool $init, bool $use_cache): mixed
{
    // 1. 尝试从缓存读取
    if ($use_cache) {
        $cachedValue = cache($fullCacheKey);
        if ($cachedValue !== false) {
            return $cachedValue;
        }
    }

    // 2. 从数据库读取
    $dbValue = blog_config_get_from_db($cache_key);
    if ($dbValue !== null) {
        // 缓存数据库结果
        if ($use_cache) {
            cache($fullCacheKey, $dbValue, true);
        }
        return $dbValue;
    }

    // 3. 数据库无记录，处理初始化
    return blog_config_handle_init($cache_key, $fullCacheKey, $default, $init, $use_cache);
}

/**
 * 从数据库获取配置并转换（单一职责）
 */
function blog_config_get_from_db(string $cache_key): mixed
{
    $setting = app\model\Setting::where('key', $cache_key)->first();
    if (!$setting) {
        return null;
    }
    return blog_config_convert_from_storage($setting->value);
}

/**
 * 处理初始化逻辑（单一职责）
 */
function blog_config_handle_init(string $cache_key, string $fullCacheKey, mixed $default, bool $init, bool $use_cache): mixed
{
    if (!$init) {
        return $default; // 不初始化，直接返回默认值
    }

    try {
        // 写入默认值到数据库
        $setting = new app\model\Setting();
        $setting->key = $cache_key;
        $setting->value = blog_config_convert_to_storage($default);
        $setting->save();

        // 写入缓存
        if ($use_cache) {
            cache($fullCacheKey, $default, true);
        }
        return $default;
    } catch (Exception|Error $e) {
        Log::error("[blog_config] 初始化失败 (key: {$cache_key}): " . $e->getMessage());
        return $default;
    }
}

/**
 * 转换值为存储格式（复用逻辑）
 */
function blog_config_convert_to_storage(mixed $value): string
{
    if ($value === null) {
        return json_encode(null, JSON_UNESCAPED_UNICODE);
    }
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $value;
        }
    }
    return json_encode($value, JSON_UNESCAPED_UNICODE);
}

/**
 * 从存储格式转换值（复用逻辑）
 */
function blog_config_convert_from_storage(mixed $value): mixed
{
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
    }
    return $value;
}
