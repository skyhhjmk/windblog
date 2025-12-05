<?php

namespace app\service;

use support\Log;

/**
 * 增强的静态缓存配置服务
 *
 * 功能特性:
 * - 支持完全关闭静态缓存
 * - 多级缓存策略(公共、登录用户、个性化)
 * - 智能缓存压缩与版本控制
 * - 条件缓存(基于时间、用户组、设备类型等)
 * - 缓存预热与智能失效
 * - 与首屏功能完全兼容
 */
class EnhancedStaticCacheConfig
{
    /**
     * 检查静态缓存是否全局启用
     */
    public static function isEnabled(): bool
    {
        return (bool) blog_config('static_cache_enabled', true, true);
    }

    /**
     * 检查是否启用缓存压缩
     */
    public static function isCompressionEnabled(): bool
    {
        return (bool) blog_config('static_cache_compression', true, true);
    }

    /**
     * 获取缓存策略配置
     *
     * 策略类型:
     * - public: 公共缓存(所有用户共享)
     * - authenticated: 已登录用户缓存(所有登录用户共享)
     * - user_group: 用户组缓存(按用户组分组)
     * - personalized: 个性化缓存(每个用户独立)
     * - conditional: 条件缓存(基于多种条件)
     */
    public static function getCacheStrategy(string $path): array
    {
        $strategies = (array) (blog_config('static_cache_strategies', [], true) ?: []);

        // 默认策略
        $defaultStrategy = [
            'type' => 'public',
            'ttl' => 3600,
            'compression' => true,
            'minify' => true,
            'conditions' => [],
            'enabled' => true,
        ];

        // 查找匹配的策略
        foreach ($strategies as $strategy) {
            $pattern = $strategy['pattern'] ?? '';
            if (self::matchPath($path, $pattern)) {
                return array_merge($defaultStrategy, $strategy);
            }
        }

        return $defaultStrategy;
    }

    /**
     * 路径匹配(支持通配符)
     */
    private static function matchPath(string $path, string $pattern): bool
    {
        if ($pattern === $path) {
            return true;
        }

        // 支持通配符 *
        // 先转义所有特殊字符，再将 \\* 替换为 .* 以支持通配
        $regex = '#^' . str_replace('\\*', '.*', preg_quote($pattern, '#')) . '$#';

        return preg_match($regex, $path) === 1;
    }

    /**
     * 获取默认的缓存策略列表
     */
    public static function getDefaultStrategies(): array
    {
        return [
            // 首页 - 公共缓存,1小时
            [
                'pattern' => '/',
                'type' => 'public',
                'ttl' => 3600,
                'compression' => true,
                'minify' => true,
                'enabled' => true,
            ],
            // 文章页 - 公共缓存,较长时间
            [
                'pattern' => '/post/*',
                'type' => 'public',
                'ttl' => 7200,
                'compression' => true,
                'minify' => true,
                'enabled' => true,
            ],
            // 分类/标签页 - 公共缓存,中等时间
            [
                'pattern' => '/category/*',
                'type' => 'public',
                'ttl' => 1800,
                'compression' => true,
                'minify' => true,
                'enabled' => true,
            ],
            [
                'pattern' => '/tag/*',
                'type' => 'public',
                'ttl' => 1800,
                'compression' => true,
                'minify' => true,
                'enabled' => true,
            ],
            // 友链页 - 已登录用户缓存(可能显示个人相关信息)
            [
                'pattern' => '/link*',
                'type' => 'authenticated',
                'ttl' => 3600,
                'compression' => true,
                'minify' => true,
                'enabled' => true,
            ],
            // 搜索页 - 个性化缓存
            [
                'pattern' => '/search*',
                'type' => 'personalized',
                'ttl' => 600,
                'compression' => false,
                'minify' => false,
                'enabled' => false, // 默认关闭搜索页缓存
            ],
        ];
    }

    /**
     * 获取缓存键
     */
    public static function getCacheKey(string $path, array $strategy, $request): string
    {
        $version = self::getCacheVersion();
        $type = $strategy['type'] ?? 'public';

        switch ($type) {
            case 'public':
                // 公共缓存,所有用户共享
                $theme = blog_config('theme', 'default');

                return "v{$version}_{$theme}_public_" . md5($path);

            case 'authenticated':
                // 已登录用户缓存
                $group = self::getUserCacheGroup($request);
                $theme = blog_config('theme', 'default');

                return "v{$version}_{$theme}_auth_{$group}_" . md5($path);

            case 'user_group':
                // 用户组缓存
                $group = self::getUserCacheGroup($request);
                $theme = blog_config('theme', 'default');

                return "v{$version}_{$theme}_group_{$group}_" . md5($path);

            case 'personalized':
                // 个性化缓存(每个用户独立)
                $userId = $request->session()?->get('user_id') ?? 'guest';
                $theme = blog_config('theme', 'default');

                return "v{$version}_{$theme}_user_{$userId}_" . md5($path);

            case 'conditional':
                // 条件缓存(基于设备类型、时间等)
                $device = self::getDeviceType($request);
                $theme = blog_config('theme', 'default');

                return "v{$version}_{$theme}_cond_{$device}_" . md5($path);

            default:
                $theme = blog_config('theme', 'default');

                return "v{$version}_{$theme}_default_" . md5($path);
        }
    }

    /**
     * 获取缓存版本(用于批量失效)
     */
    public static function getCacheVersion(): string
    {
        $version = blog_config('static_cache_version', null, true);
        if (!$version) {
            $version = date('YmdHis');
            blog_config('static_cache_version', $version, true, true, true);
        }

        return (string) $version;
    }

    /**
     * 获取用户缓存组
     *
     * 用于区分不同类型的用户缓存
     */
    public static function getUserCacheGroup($request): string
    {
        // 检查是否已登录后台
        $adminSession = $request->session()?->get('admin');
        $adminToken = $request->cookie('admin_token');
        if ($adminSession || $adminToken) {
            return 'admin';
        }

        // 可以根据其他条件分组,如会员等级等
        // $userId = $request->session()?->get('user_id');
        // if ($userId) {
        //     return 'user_' . $userId;
        // }

        return 'guest';
    }

    /**
     * 获取设备类型
     */
    private static function getDeviceType($request): string
    {
        $ua = strtolower((string) $request->header('User-Agent'));

        if (str_contains($ua, 'mobile') || str_contains($ua, 'android') || str_contains($ua, 'iphone')) {
            return 'mobile';
        }

        if (str_contains($ua, 'tablet') || str_contains($ua, 'ipad')) {
            return 'tablet';
        }

        return 'desktop';
    }

    /**
     * 检查缓存条件是否满足
     */
    public static function checkCacheConditions(array $conditions, $request): bool
    {
        if (empty($conditions)) {
            return true;
        }

        foreach ($conditions as $condition) {
            $type = $condition['type'] ?? '';

            switch ($type) {
                case 'time_range':
                    // 时间范围条件
                    $start = $condition['start'] ?? '00:00';
                    $end = $condition['end'] ?? '23:59';
                    $now = date('H:i');
                    if ($now < $start || $now > $end) {
                        return false;
                    }
                    break;

                case 'device':
                    // 设备类型条件
                    $allowedDevices = $condition['devices'] ?? [];
                    if (!empty($allowedDevices)) {
                        $device = self::getDeviceType($request);
                        if (!in_array($device, $allowedDevices)) {
                            return false;
                        }
                    }
                    break;

                case 'user_agent':
                    // UA条件
                    $pattern = $condition['pattern'] ?? '';
                    if ($pattern) {
                        $ua = (string) $request->header('User-Agent');
                        if (!preg_match($pattern, $ua)) {
                            return false;
                        }
                    }
                    break;

                case 'header':
                    // 请求头条件
                    $headerName = $condition['name'] ?? '';
                    $headerValue = $condition['value'] ?? '';
                    if ($headerName && $request->header($headerName) !== $headerValue) {
                        return false;
                    }
                    break;
            }
        }

        return true;
    }

    /**
     * 获取缓存统计信息
     */
    public static function getCacheStats(): array
    {
        $stats = cache('static_cache_stats') ?: [
            'hits' => 0,
            'misses' => 0,
            'generated' => 0,
            'last_reset' => time(),
        ];

        $hitRate = $stats['hits'] + $stats['misses'] > 0
            ? round($stats['hits'] / ($stats['hits'] + $stats['misses']) * 100, 2)
            : 0;

        return array_merge($stats, ['hit_rate' => $hitRate]);
    }

    /**
     * 记录缓存命中
     */
    public static function recordCacheHit(): void
    {
        $stats = cache('static_cache_stats') ?: ['hits' => 0, 'misses' => 0, 'generated' => 0, 'last_reset' => time()];
        $stats['hits']++;
        cache('static_cache_stats', $stats, true, 86400);
    }

    /**
     * 记录缓存未命中
     */
    public static function recordCacheMiss(): void
    {
        $stats = cache('static_cache_stats') ?: ['hits' => 0, 'misses' => 0, 'generated' => 0, 'last_reset' => time()];
        $stats['misses']++;
        cache('static_cache_stats', $stats, true, 86400);
    }

    /**
     * 记录缓存生成
     */
    public static function recordCacheGenerated(): void
    {
        $stats = cache('static_cache_stats') ?: ['hits' => 0, 'misses' => 0, 'generated' => 0, 'last_reset' => time()];
        $stats['generated']++;
        cache('static_cache_stats', $stats, true, 86400);
    }

    /**
     * 重置统计信息
     */
    public static function resetStats(): void
    {
        cache('static_cache_stats', [
            'hits' => 0,
            'misses' => 0,
            'generated' => 0,
            'last_reset' => time(),
        ], true, 86400);
    }

    /**
     * 获取缓存预热URL列表
     */
    public static function getWarmupUrls(): array
    {
        $urls = blog_config('static_cache_warmup_urls', [], true);

        if (empty($urls)) {
            // 默认预热URL
            $urls = [
                '/',
                '/link',
            ];
        }

        return $urls;
    }

    /**
     * 清除所有静态缓存
     */
    public static function clearAllCache(): bool
    {
        try {
            $public = public_path() . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'static';

            if (is_dir($public)) {
                self::deleteDirectory($public);
                Log::info('[EnhancedStaticCache] All static cache cleared');
            }

            // 更新版本号使内存缓存失效
            self::updateCacheVersion();

            return true;
        } catch (\Throwable $e) {
            Log::error('[EnhancedStaticCache] Failed to clear cache: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * 递归删除目录
     */
    private static function deleteDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                self::deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }

        return @rmdir($dir);
    }

    /**
     * 更新缓存版本(使所有旧缓存失效)
     */
    public static function updateCacheVersion(): string
    {
        $version = date('YmdHis') . '_' . substr(md5((string) microtime(true)), 0, 8);
        blog_config('static_cache_version', $version, true, true, true);
        Log::info('[EnhancedStaticCache] Cache version updated: ' . $version);

        return $version;
    }
}
