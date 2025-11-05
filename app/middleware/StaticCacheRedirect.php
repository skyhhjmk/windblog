<?php

namespace app\middleware;

use app\service\EnhancedStaticCacheConfig;

use function public_path;

use support\Log;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 增强的静态缓存重定向中间件
 *
 * 功能特性:
 * - 支持完全关闭静态缓存
 * - 多级缓存策略(公共、登录用户、个性化)
 * - 支持 ETag 和 Last-Modified
 * - 用户分组缓存
 * - 条件缓存检查
 * - 缓存统计
 * - Gzip 压缩支持
 */
class StaticCacheRedirect implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        // 检查静态缓存是否全局启用
        if (!EnhancedStaticCacheConfig::isEnabled()) {
            Log::debug('[StaticCache] Disabled globally');

            return $handler($request);
        }

        // 仅处理 GET，排除后台/接口与显式禁用缓存情况
        if (strtoupper($request->method()) !== 'GET') {
            return $handler($request);
        }

        $path = $request->path();
        // 后台与插件、接口路径直接放行
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        if (
            str_starts_with($path, '/app/admin') ||
            str_starts_with($path, '/plugin') ||
            str_starts_with($path, '/api')
        ) {
            return $handler($request);
        }

        // 允许通过 query 显式绕过缓存
        if ($request->get('no_cache') || $request->get('preview')) {
            return $handler($request);
        }

        // 获取缓存策略
        $strategy = EnhancedStaticCacheConfig::getCacheStrategy($path);

        // 策略明确设置为false时跳过（默认策略启用）
        if (isset($strategy['enabled']) && $strategy['enabled'] === false) {
            return $handler($request);
        }

        // 检查缓存条件
        $conditions = $strategy['conditions'] ?? [];
        if (!EnhancedStaticCacheConfig::checkCacheConditions($conditions, $request)) {
            return $handler($request);
        }

        // 根据策略类型决定是否使用缓存
        $strategyType = $strategy['type'] ?? 'public';

        // 对于 authenticated 和更高级别的策略，检查登录状态
        if (in_array($strategyType, ['authenticated', 'user_group', 'personalized'])) {
            $adminSession = $request->session()?->get('admin');
            $adminToken = $request->cookie('admin_token');

            // 管理员始终走动态渲染
            if ($adminSession || $adminToken) {
                return $handler($request);
            }
        }

        // 公共缓存：管理员也绕过（但仍统计）
        if ($strategyType === 'public') {
            $adminSession = $request->session()?->get('admin');
            $adminToken = $request->cookie('admin_token');
            if ($adminSession || $adminToken) {
                // 管理员访问视为缓存未命中（因为需要动态内容）
                EnhancedStaticCacheConfig::recordCacheMiss();
                Log::debug('[StaticCache] Admin bypassed: ' . $path);

                return $handler($request);
            }
        }

        // 生成缓存键
        $cacheKey = EnhancedStaticCacheConfig::getCacheKey($path, $strategy, $request);

        // 将URL路径映射到 public/cache/static 下的文件
        $rel = ltrim($path, '/');
        if ($rel === '') {
            $rel = 'index.html';
        } else {
            // 去除已有的 .html 后缀，再统一加 .html
            $rel = preg_replace('#\.html$#', '', $rel) . '.html';
        }

        // 使用缓存键作为子目录，实现多版本缓存
        $cacheDir = public_path() . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'static' . DIRECTORY_SEPARATOR . substr($cacheKey, 0, 8);
        $full = $cacheDir . DIRECTORY_SEPARATOR . $rel;

        if (is_file($full)) {
            // 命中静态文件
            Log::debug('[StaticCache] HIT: ' . $path . ' -> ' . $full);
            $generatedAt = @filemtime($full) ?: time();
            $etag = md5($cacheKey . $generatedAt);

            // 检查 ETag
            $clientEtag = $request->header('If-None-Match');
            if ($clientEtag === $etag) {
                EnhancedStaticCacheConfig::recordCacheHit();

                return new Response(304, [
                    'ETag' => $etag,
                    'Cache-Control' => 'public, max-age=' . ($strategy['ttl'] ?? 3600),
                    'X-Static-Cache' => 'hit',
                ]);
            }

            // 检查 Last-Modified
            $clientModified = $request->header('If-Modified-Since');
            if ($clientModified && strtotime($clientModified) >= $generatedAt) {
                EnhancedStaticCacheConfig::recordCacheHit();

                return new Response(304, [
                    'Last-Modified' => gmdate('D, d M Y H:i:s', $generatedAt) . ' GMT',
                    'Cache-Control' => 'public, max-age=' . ($strategy['ttl'] ?? 3600),
                    'X-Static-Cache' => 'hit',
                ]);
            }

            // 读取缓存内容
            $body = file_get_contents($full);

            // 检查是否支持 Gzip 压缩
            $acceptEncoding = $request->header('Accept-Encoding') ?? '';
            $useGzip = str_contains($acceptEncoding, 'gzip') && function_exists('gzencode');

            $headers = [
                'Content-Type' => 'text/html; charset=utf-8',
                'Cache-Control' => 'public, max-age=' . ($strategy['ttl'] ?? 3600),
                'ETag' => $etag,
                'Last-Modified' => gmdate('D, d M Y H:i:s', $generatedAt) . ' GMT',
                'X-Static-Cache' => 'hit',
                'X-Cache-Strategy' => $strategyType,
            ];

            if ($useGzip) {
                $body = gzencode($body, 6);
                $headers['Content-Encoding'] = 'gzip';
                $headers['Vary'] = 'Accept-Encoding';
            }

            // 记录缓存命中
            EnhancedStaticCacheConfig::recordCacheHit();

            return new Response(200, $headers, $body);
        }

        // 未命中，记录并走后续业务
        Log::debug('[StaticCache] MISS: ' . $path . ' -> ' . $full);
        EnhancedStaticCacheConfig::recordCacheMiss();

        return $handler($request);
    }
}
