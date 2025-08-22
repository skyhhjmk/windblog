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
        $redis->set($key, $value);
        return $value;
    } else {
        return $redis->get($key);
    }
}

/**
 * 获取博客配置，获取所有配置项不需要传参，并且不使用缓存
 * @param string|null $key
 * @param mixed|null $default
 * @param bool $set
 * @param bool $use_cache
 * @return mixed
 * @throws Throwable
 */
function blog_config(string $key, mixed $default = null, bool $set = false, bool $use_cache = true): mixed
{
    $key = trim($key);
    if ($key === ''){
        return app\model\Settings::all();
    }
    if ($use_cache) {
        $cfg = cache('blog_config_' . $key);
        if ($cfg) {
            return $cfg;
        } else {
            if ($set) {
                try {
                    cache('blog_config_' . $key, $default, true);
                } catch (\Exception $e) {
                    \support\Log::channel('system_function')->error('[blog_config] error: ' . $e->getMessage());
                    return false;
                }
            }
        }
    }

    $cfg = app\model\Settings::where('key', $key)->value('value');
    if ($cfg) {
        return $cfg;
    } else {
        if ($set) {
            try {
                app\model\Settings::updateOrInsert(
                    ['key' => $key],
                    ['value' => $default]
                );
            } catch (\Exception $e) {
                \support\Log::channel('system_function')->error('[blog_config] error: ' . $e->getMessage());
                return false;
            }
        }
        return $default;
    }
}

/**
 * @param string $key
 * @param mixed $value
 * @return bool
 */
function set_blog_config(string $key, mixed $value): bool
{
    try {
        app\model\Settings::updateOrInsert(
            ['key' => $key],
            ['value' => $value]
        );
    } catch (\Exception $e) {
        \support\Log::channel('system_function')->error('[set_blog_config] error: ' . $e->getMessage());
        return false;
    }
    return true;
}

/**
 * 将序列化的数据转为数组,并根据需要返回指定键的值[可选]
 * @param string $data_str
 * @param string|null $need
 * @return mixed
 */
function decodeData(string $data_str = '', ?string $need = null): mixed
{
    $decode_data = unserialize($data_str);
    if ($need !== null) {
        return $decode_data[$need] ?? null;
    } else {
        return $decode_data;
    }
}

/**
 * 将数组转为序列化数据
 * @param array $data_arr
 * @return string
 */
function encodeData(array $data_arr = []): string
{
    return serialize($data_arr);
}
