<?php

namespace app\service;

/**
 * 统一缓存控制服务
 * 管理所有类型的缓存策略，提供一致的缓存头生成方法
 */
class CacheControl
{
    /**
     * 预定义的缓存策略
     */
    public const STRATEGY = [
        // 骨架页：绝对不缓存
        'skeleton' => [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0, s-maxage=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'CDN-Cache-Control' => 'no-store',
            'Cloudflare-CDN-Cache-Control' => 'no-store',
        ],

        // 骨架页静态文件：CDN和浏览器缓存1年
        'skeleton_page' => [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'CDN-Cache-Control' => 'public, max-age=31536000, immutable',
        ],

        // 完整HTML页面：浏览器缓存5分钟，CDN缓存1小时
        'full_page' => [
            'Cache-Control' => 'public, max-age=300, s-maxage=3600, stale-while-revalidate=300',
            'Vary' => 'Accept-Encoding',
        ],

        // 静态缓存文件：默认1小时
        'static_cache' => [
            'Cache-Control' => 'public, max-age=3600, stale-while-revalidate=300',
        ],

        // 静态资源：根据文件类型不同有不同缓存时间
        'static_assets' => [
            // 图片和字体：30天 + immutable
            'images' => 'public, max-age=2592000, immutable, stale-while-revalidate=86400',
            // CSS和JS：7天 + immutable
            'scripts' => 'public, max-age=604800, immutable, stale-while-revalidate=86400',
            // 其他静态资源：1天
            'default' => 'public, max-age=86400, stale-while-revalidate=3600',
        ],

        // 不缓存：用于动态内容
        'no_cache' => [
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Expires' => '0',
            'Pragma' => 'no-cache',
        ],
    ];

    /**
     * 获取骨架页的缓存头
     *
     * @return array
     */
    public static function getSkeletonHeaders(): array
    {
        return self::STRATEGY['skeleton'];
    }

    /**
     * 获取骨架页静态文件的缓存头
     *
     * @return array
     */
    public static function getSkeletonPageHeaders(): array
    {
        return self::STRATEGY['skeleton_page'];
    }

    /**
     * 获取完整页面的缓存头
     *
     * @return array
     */
    public static function getFullPageHeaders(): array
    {
        return self::STRATEGY['full_page'];
    }

    /**
     * 获取静态缓存的缓存头
     *
     * @param int $ttl 缓存时间（秒）
     *
     * @return array
     */
    public static function getStaticCacheHeaders(int $ttl = 3600): array
    {
        $headers = self::STRATEGY['static_cache'];
        $headers['Cache-Control'] = str_replace('3600', (string) $ttl, $headers['Cache-Control']);

        return $headers;
    }

    /**
     * 获取静态资源的缓存头
     *
     * @param string $ext         文件扩展名
     * @param bool   $isImmutable 是否为不可变文件（带哈希）
     *
     * @return string
     */
    public static function getStaticAssetCacheControl(string $ext, bool $isImmutable): string
    {
        $ext = strtolower($ext);
        $strategy = self::STRATEGY['static_assets']['default'];

        // 根据文件类型选择策略
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'ico', 'svg', 'woff', 'woff2', 'ttf', 'eot'])) {
            $strategy = self::STRATEGY['static_assets']['images'];
        } elseif (in_array($ext, ['css', 'js'])) {
            $strategy = self::STRATEGY['static_assets']['scripts'];
        }

        // 移除 immutable 如果文件不是不可变的
        if (!$isImmutable) {
            $strategy = str_replace(', immutable', '', $strategy);
        }

        return $strategy;
    }

    /**
     * 获取不缓存的缓存头
     *
     * @return array
     */
    public static function getNoCacheHeaders(): array
    {
        return self::STRATEGY['no_cache'];
    }

    /**
     * 生成 ETag
     *
     * @param string $content 内容或标识
     *
     * @return string
     */
    public static function generateETag(string $content): string
    {
        return md5($content);
    }

    /**
     * 生成 Last-Modified 头值
     *
     * @param int $timestamp 时间戳
     *
     * @return string
     */
    public static function generateLastModified(int $timestamp): string
    {
        return gmdate('D, d M Y H:i:s', $timestamp) . ' GMT';
    }
}
