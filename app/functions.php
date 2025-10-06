<?php

/**
 * Here is your custom functions.
 */

use support\Log;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Translation\Loader\ArrayLoader;
use app\service\CacheService;

/**
 * 获取缓存处理器实例
 * 根据环境变量配置动态选择缓存器类型
 *
 * @return object|null 缓存处理器实例
 * @throws Exception
 */
function get_cache_handler(): ?object
{
    return CacheService::getCacheHandler();
}

/**
 * get cache or set cache(and return set value)
 * 获取或设置缓存（并返回设置的值），不存在时返回默认值或false。
 * 输入原始值，输出原始值，内部序列化存储，外部反序列化返回
 * 支持多种缓存器类型：Redis、APCU、Memcached、无缓存模式
 *
 * @param string|null $key   cache key 缓存键
 * @param mixed|null  $value cache value|default 缓存值|默认返回值
 * @param bool        $set   set cache 是否设置缓存
 * @param int|null    $ttl   过期时间（秒），0表示永不过期，null使用默认配置
 *
 * @return mixed
 */
function cache(?string $key = null, mixed $value = null, bool $set = false, ?int $ttl = null): mixed
{
    if (is_null($key)) {
        return new CacheService();
    }
    return CacheService::cache($key, $value, $set, $ttl);
}

/**
 * 获取博客配置，获取所有配置项不需要传参，并且不使用缓存
 * 输入原始值，输出原始值。内部序列化存储，外部反序列化返回
 *
 * @param string     $key       key in database
 * @param mixed|null $default   default value
 * @param bool       $set       set default value to database
 * @param bool       $use_cache use cache
 * @param bool       $init
 *
 * @return mixed
 * @throws Throwable
 */
function blog_config(string $key, mixed $default = null, bool $init = false, bool $use_cache = true, bool $set = false): mixed
{
    $key = trim($key);
    $fullCacheKey = 'blog_config_' . $key;

    // 优先处理写操作
    if ($set) {
        return blog_config_write($key, $fullCacheKey, $default, $use_cache);
    }

    // 读操作主流程
    return blog_config_read($key, $fullCacheKey, $default, $init, $use_cache);
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

        // 更新缓存（不缓存 null，避免后续读到 null）
        if ($use_cache && $value !== null) {
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
        // 将 null 和空字符串视为未命中，避免把 json:null 或 "" 当作有效值返回
        if ($cachedValue !== false) {
            if ($cachedValue === null || (is_string($cachedValue) && trim($cachedValue) === '')) {
                // 命中到空值则清理该键，避免后续误命中
                try {
                    $handler = get_cache_handler();
                    if ($handler) {
                        $handler->del(\app\service\CacheService::prefixKey($fullCacheKey));
                    }
                } catch (\Throwable $e) {
                    Log::warning("[blog_config] cleanup empty cache warn: {$fullCacheKey} - " . $e->getMessage());
                }
            } else {
                Log::debug("[blog_config] cache hit: {$fullCacheKey}");
                return $cachedValue;
            }
        }
        Log::debug("[blog_config] cache miss: {$fullCacheKey}");
    }

    // 2. 从数据库读取
    $dbValue = blog_config_get_from_db($cache_key);
    if ($dbValue !== null) {
        // 缓存数据库结果
        if ($use_cache) {
            cache($fullCacheKey, $dbValue, true);
        }
        Log::debug("[blog_config] db hit: {$cache_key}=" . var_export($dbValue, true));
        return $dbValue;
    }

    // 3. 数据库无记录，处理初始化
    Log::debug("[blog_config] db miss: {$cache_key}, init=" . ($init ? 'true' : 'false') . ", default=" . var_export($default, true));
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
    $val = blog_config_convert_from_storage($setting->value);

    // 全局：存储为 json:null 或空字符串，都视为未配置
    if ($val === null) {
        return null;
    }
    if (is_string($val) && trim($val) === '') {
        return null;
    }

    // RabbitMQ 端口必须为正整数（其余键使用全局规则）
    if ($cache_key === 'rabbitmq_port') {
        if (!is_numeric($val)) {
            return null;
        }
        $port = (int)$val;
        if ($port <= 0) {
            return null;
        }
        return $port;
    }

    return $val;
}

/**
 * 处理初始化逻辑（单一职责）
 */
function blog_config_handle_init(string $cache_key, string $fullCacheKey, mixed $default, bool $init, bool $use_cache): mixed
{
    // 为URL模式设置默认值
    if ($cache_key === 'url_mode' && $default === null) {
        $default = 'slug'; // 默认使用slug模式
    }
    
    if (!$init) {
        return blog_config_normalize_default($default); // 不初始化，直接返回默认值
    }

    $default = blog_config_normalize_default($default);

    try {
        // 写入默认值到数据库（幂等：若已存在则更新）
        $setting = app\model\Setting::firstOrNew(['key' => $cache_key]);
        $setting->value = blog_config_convert_to_storage($default);
        $setting->save();

        // 写入缓存（不缓存 null）
        if ($use_cache && $default !== null) {
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

/**
 * 归一化默认值：全局不返回 null
 * 当前策略：当 default 为 null 时回退为空字符串 ''
 */
function blog_config_normalize_default(mixed $value): mixed
{
    return $value === null ? '' : $value;
}

/**
 * 翻译函数，用于多语言支持
 *
 * @param string      $id         翻译键名
 * @param array       $parameters 替换参数
 * @param string|null $domain     翻译域
 * @param string|null $locale     语言代码
 *
 * @return string
 */
function __($id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
{
    static $translator = null;
    if ($translator === null) {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', [], 'en');
    }
    return $translator->trans($id, $parameters, $domain, $locale);
}

/**
 * 格式化日期时间
 *
 * @param string $time 时间字符串
 * @param string $format 格式化模板
 *
 * @return string
 */
function format_time(string $time, string $format = 'Y-m-d H:i:s'): string
{
    return date($format, strtotime($time));
}

/**
 * 格式化文件大小
 *
 * @param int $bytes 文件大小（字节）
 *
 * @return string
 */
function format_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    } elseif ($bytes < 1048576) {
        return round($bytes / 1024, 2) . ' KB';
    } elseif ($bytes < 1073741824) {
        return round($bytes / 1048576, 2) . ' MB';
    } else {
        return round($bytes / 1073741824, 2) . ' GB';
    }
}

/**
 * 生成随机字符串
 *
 * @param int $length 字符串长度
 * @param string $type 字符串类型：'number', 'letter', 'mix'
 *
 * @return string
 */
function random_string(int $length = 10, string $type = 'mix'): string
{
    $numberChars = '0123456789';
    $letterChars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $mixChars = $numberChars . $letterChars;
    
    switch ($type) {
        case 'number':
            $chars = $numberChars;
            break;
        case 'letter':
            $chars = $letterChars;
            break;
        case 'mix':
        default:
            $chars = $mixChars;
            break;
    }
    
    $result = '';
    $charsLength = strlen($chars);
    for ($i = 0; $i < $length; $i++) {
        $result .= $chars[rand(0, $charsLength - 1)];
    }
    return $result;
}
