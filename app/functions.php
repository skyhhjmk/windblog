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
    if ($cache_key === '') {
        return app\model\Setting::all();
    }


    if ($init === false && $set === true) {
        // 如果需要设置值，则直接设置并返回default
        try {
            // 查找或创建设置项
            $setting_model = app\model\Setting::where('key', $cache_key)->first();

            if (!$setting_model) {
                // 如果不存在则创建新记录
                $setting_model = new app\model\Setting();
                $setting_model->key = $cache_key;
            }
            $setting_model->value = serialize($default);
            $setting_model->save();

            // 同时更新缓存
            if ($use_cache) {
                cache('blog_config_' . $cache_key, $default, true);
            }
        } catch (Exception $exception) {
            Log::error('[blog_config] error: ' . $exception->getMessage());
            return $default;
        } catch (Error $error) {
            Log::error('[blog_config] error: ' . $error->getMessage());
            return $default;
        }
        return $default;
    } elseif ($init === true) {
        // 如果是初始化配置，先查找是否存在
        $cached_value = false;
        if ($use_cache) {
            $cached_value = cache('blog_config_' . $cache_key);
        }
        
        if ($cached_value !== false) {
            // 如果缓存存在，直接返回缓存值
            return $cached_value;
        } else {
            // 如果缓存不存在
            // 从数据库中查找
            $setting_model = app\model\Setting::where('key', $cache_key)->first();
            if ($setting_model) {
                // 如果存在，放入缓存，反序列化值并返回
                $unserialized_value = @unserialize($setting_model->value);
                // 检查反序列化是否成功
                if ($unserialized_value !== false || $setting_model->value === serialize(false)) {
                    if ($use_cache) {
                        cache('blog_config_' . $cache_key, $unserialized_value, true);
                    }
                    return $unserialized_value;
                } else {
                    // 反序列化失败，记录日志并返回默认值
                    Log::warning('[blog_config] failed to unserialize value for key: ' . $cache_key);
                    return $default;
                }
            } else {
                // 如果不存在
                // 创建记录并且返回默认
                $setting_model = new app\model\Setting();
                $setting_model->key = $cache_key;
                $setting_model->value = serialize($default);
                $setting_model->save();

                $unserialized_value = $default;
                if ($use_cache) {
                    cache('blog_config_' . $cache_key, $unserialized_value, true);
                }
                return $unserialized_value;
            }
        }
    }

    $config_value = null;
    if ($use_cache) {
        $cached_value = cache('blog_config_' . $cache_key);
        if ($cached_value !== false) {
            return $cached_value;
        }
    }

    $db_value = app\model\Setting::where('key', $cache_key)->value('value');
    if ($db_value === null && $init === true) {
        try {
            app\model\Setting::insert(['key' => $cache_key, 'value' => serialize($default)]);
        } catch (Exception $exception) {
            Log::error('[blog_config] error inserting new setting: ' . $exception->getMessage());
            return $default;
        } catch (Error $error) {
            Log::error('[blog_config] error inserting new setting: ' . $error->getMessage());
            return $default;
        }
        return $default;
    } elseif ($db_value !== null && $db_value !== false) {
        $unserialized_value = @unserialize($db_value);
        // 如果反序列化成功，返回反序列化值，否则返回原始值
        if ($unserialized_value !== false || $db_value === serialize(false)) {
            // 更新缓存
            if ($use_cache) {
                cache('blog_config_' . $cache_key, $unserialized_value, true);
            }
            return $unserialized_value;
        } else {
            Log::warning('[blog_config] failed to unserialize db value for key: ' . $cache_key);
            return $default;
        }
    } else {
        return $default;
    }
}