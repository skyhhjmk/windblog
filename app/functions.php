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

    // 空key返回全量配置（建议后续优化为分页或缓存）
    if ($key === '') {
        return app\model\Setting::all();
    }

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
function translate_message(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
{
    static $translators = [];
    
    // 使用默认语言域
    if ($domain === null) {
        $domain = 'messages';
    }
    
    // 使用默认语言
    if ($locale === null) {
        $locale = session('lang', 'zh_CN');
    }
    
    // 缓存键
    $cacheKey = "translator_$locale";
    
    // 获取或创建翻译器实例
    if (!isset($translators[$cacheKey])) {
        // 创建翻译器
        $translator = new Translator($locale);
        $translator->addLoader('array', new ArrayLoader());
        
        // 加载翻译文件
        $translationDir = base_path() . '/resource/translations';
        $languageFiles = [
            $translationDir . "/$locale/messages.php",
            $translationDir . "/$locale/validation.php"
        ];
        
        foreach ($languageFiles as $file) {
            if (file_exists($file)) {
                $translations = require $file;
                $translator->addResource('array', $translations, $locale, $domain);
            }
        }
        
        // 添加回退语言
        $fallbackLocales = config('translation.fallback_locales', ['zh_CN', 'en']);
        foreach ($fallbackLocales as $fallbackLocale) {
            if ($fallbackLocale !== $locale) {
                $fallbackFile = $translationDir . "/$fallbackLocale/messages.php";
                if (file_exists($fallbackFile)) {
                    $fallbackTranslations = require $fallbackFile;
                    $translator->addResource('array', $fallbackTranslations, $fallbackLocale, $domain);
                }
            }
        }
        
        $translators[$cacheKey] = $translator;
    }
    
    return $translators[$cacheKey]->trans($id, $parameters, $domain, $locale);
}

/**
 * 快速清除缓存
 * 根据缓存键或模式清除缓存内容
 *
 * @param string $pattern 缓存键或模式，支持通配符*
 * @return bool 是否成功清除
 * @throws Exception
 */
function clear_cache(string $pattern = '*'): bool
{
    return CacheService::clearCache($pattern);
}
