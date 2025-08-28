<?php
/**
 * Here is your custom functions.
 */

/**
 * get cache or set cache(and return set value)
 * @param string $key cache key
 * @param mixed|null $value cache value|default
 * @param bool $set set cache
 * @return mixed
 * @throws Throwable
 */
function cache(string $key, mixed $value = null, bool $set = false): mixed
{
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
        return $unserialized !== false ? $unserialized : $cached;
    }
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
        return app\model\Settings::all();
    }

    // 如果需要设置值，则直接设置并返回default
    if ($set) {
        try {
            // 查找或创建设置项
            $setting = app\model\Settings::where('key', $key)->first();

            if (!$setting) {
                // 如果不存在则创建新记录
                $setting = new app\model\Settings();
                $setting->key = $key;
            }
            $setting->value = serialize($default);
            $setting->save();

            // 同时更新缓存
            if ($use_cache) {
                cache('blog_config_' . $key, $default, true);
            }
        } catch (\Exception $e) {
            \support\Log::channel('system_function')->error('[blog_config] error: ' . $e->getMessage());
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

    $cfg = app\model\Settings::where('key', $key)->value('value');
    if ($cfg !== null && $cfg !== false) {
        $unserialized = unserialize($cfg);
        // 如果反序列化失败，返回原始值
        return $unserialized !== false ? $unserialized : $cfg;
    } else {
        return $default;
    }
}
