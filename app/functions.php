<?php
/**
 * Here is your custom functions.
 */

use support\Log;

/**
 * get cache or set cache(and return set value)
 * 获取或设置缓存（并返回设置的值）
 * @param string $key cache key 缓存键
 * @param mixed|null $value cache value|default 缓存值|默认返回值
 * @param bool $set set cache 是否设置缓存
 * @return mixed
 * @throws Throwable
 */
function cache(string $key, mixed $value = null, bool $set = false): mixed
{
    try {
        $redis = \support\Redis::connection('cache');

        if ($set) {
            $redis->setex($key,blog_config('cache_expire', 86400/* 24小时 */), serialize($value));
            return $value;
        } else {
            $cached = $redis->get($key);
            if ($cached === false || $cached === null) {
                return false;
            }
            $unserialized = unserialize($cached);
            $return = $unserialized !== false ? $unserialized : $cached;
        }
    } catch (Exception $e) {
        Log::error('[cache] error: ' . $e->getMessage());
        $return = null;
    }
    return $return;
}

/**
 * 获取博客配置，获取所有配置项不需要传参，并且不使用缓存
 * @param string $key key in database
 * @param mixed|null $default default value
 * @param bool $set set default value to database
 * @param bool $use_cache use cache
 * @return mixed
 * @throws Throwable
 */
function blog_config(string $key, mixed $default = null, bool $set = false, bool $use_cache = true): mixed
{
    $key = trim($key);
    if ($key === '') {
        return app\model\Setting::all();
    }

    // 如果需要设置值，则直接设置并返回default
    if ($set) {
        try {
            // 查找或创建设置项
            $setting = app\model\Setting::where('key', $key)->first();

            if (!$setting) {
                // 如果不存在则创建新记录
                $setting = new app\model\Setting();
                $setting->key = $key;
            }
            $setting->value = serialize($default);
            $setting->save();

            // 同时更新缓存
            if ($use_cache) {
                cache('blog_config_' . $key, $default, true);
            }
        } catch (Exception $e) {
            Log::error('[blog_config] error: ' . $e->getMessage());
            return false;
        }
        return $default;
    }

    if ($use_cache) {
        $cfg = cache('blog_config_' . $key);
        if ($cfg !== false) {
            return $cfg;
        }
    }

    $cfg = app\model\Setting::where('key', $key)->value('value');
    if ($cfg !== null && $cfg !== false) {
        $unserialized = unserialize($cfg);
        // 如果反序列化失败，返回原始值
        return $unserialized !== false ? $unserialized : $cfg;
    } else {
        return $default;
    }
}

function is_admin()
{
    
}