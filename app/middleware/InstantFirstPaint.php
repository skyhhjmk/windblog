<?php

namespace app\middleware;

use app\annotation\EnableInstantFirstPaint;
use app\service\CacheControl;
use ReflectionClass;
use ReflectionMethod;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 极快首屏返回中间件
 *
 * 思路：
 * - 对于首屏 HTML GET 请求，重定向到独立的骨架页 URL，骨架页可被 CDN 和浏览器缓存
 * - 骨架页会显示详细的加载进度信息（当前加载的文件、进度、速度等）
 * - 骨架页会自动拉取完整页面并替换
 * - 对 API、后台、插件、静态资源、PJAX/AJAX、机器人 UA 等请求一律放行，避免副作用与 SEO 影响
 * - 只有添加了 EnableInstantFirstPaint 注解的控制器方法才会触发骨架页逻辑
 */
class InstantFirstPaint implements MiddlewareInterface
{
    // 定义已知的合法搜索引擎爬虫 User-Agent 关键词
    protected const KNOWN_SEARCH_ENGINES = [
        'googlebot',
        'bingbot',
        'baiduspider',
        'yandexbot',
        'yahoo! slurp',
        'sogou',
        'yisouspider',
        'bytespider',
        'petalbot',
        'duckduckbot',
        'facebookexternalhit',
        'twitterbot',
        'linkedinbot',
        'applebot',
        '360spider',
        'semrushbot',
        'ahrefsbot',
        'amazonbot',
        'archive.org_bot',
        'ia_archiver',
        'heritrix',
        'wget',
        'curl',
        'feedly',
        'feedburner',
        'mediapartners-google',
        'adsbot-google',
    ];

    // 定义需要拦截的其他爬虫关键词
    protected const OTHER_CRAWLERS = [
        'bot',
        'spider',
        'crawler',
        'scraper',
        'scrapy',
        'httrack',
        'java/',
        'python-requests',
        'go-http-client',
        'axios',
        'seo',
    ];

    public function process(Request $request, callable $handler): Response
    {
        // 检查是否有控制器方法标记了 EnableInstantFirstPaint 注解
        if (!$this->hasInstantFirstPaintAnnotation($request)) {
            return $handler($request);
        }

        // 仅处理 GET
        if (strtoupper($request->method()) !== 'GET') {
            return $handler($request);
        }

        $path = $request->path();
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }

        // 允许通过 Header/Query 显式绕过（防递归/调试）
        // Query 参数会被 CDN 转发，更可靠
        // 添加对静态化生成请求的检测，自动绕过骨架屏逻辑
        if (
            $request->header('X-INSTANT-BYPASS') === '1' ||
            $request->get('no_instant') == '1' ||
            $request->get('_instant_bypass') === '1' ||
            $request->header('User-Agent') === 'StaticGenerator/1.0'
        ) {
            // 绕过骨架页，返回完整页面
            // 完整页面应该被 CDN 缓存，添加缓存头
            $response = $handler($request);

            // 仅对 HTML 响应添加缓存头
            $contentType = $response->getHeader('Content-Type');
            if ($contentType && str_contains($contentType[0], 'text/html')) {
                $fullPageHeaders = CacheControl::getFullPageHeaders();
                $fullPageHeaders['X-Content-Type'] = 'full-page';
                $response->withHeaders($fullPageHeaders);
            }

            return $response;
        }

        // 跳过后台、插件、API、静态资源、健康检查等
        if (
            str_starts_with($path, '/app/admin') ||
            str_starts_with($path, '/plugin') ||
            str_starts_with($path, '/api') ||
            preg_match('#\.(?:js|css|png|jpg|jpeg|gif|webp|svg|ico|woff2?|ttf|eot|map)$#i', $path) === 1 ||
            $path === '/favicon.ico' || $path === '/robots.txt'
        ) {
            return $handler($request);
        }

        // 跳过 PJAX/AJAX 请求（正常渲染内容片段）
        $isAjax = $request->isAjax();
        $isPjax = $request->isPjax() || $request->get('_pjax') !== null;
        if ($isAjax || $isPjax) {
            return $handler($request);
        }

        // 检查 User-Agent，确保合法搜索引擎爬虫能获取完整页面
        $ua = strtolower((string) $request->header('User-Agent'));
        if ($ua) {
            // 如果是已知的合法搜索引擎爬虫，直接返回完整页面
            if ($this->isKnownSearchEngine($ua)) {
                return $handler($request);
            }

            // 如果是其他可疑的爬虫，跳过首屏优化
            if ($this->isOtherCrawler($ua)) {
                return $handler($request);
            }
        }

        // 可选：仅对首页或慢页面启用（按需放开）
        // if ($path !== '/' && !preg_match('#^/(post|category|tag)/#', $path)) {
        //     return $next($request);
        // }

        // 重定向到独立的骨架页 URL
        $skeletonUrl = '/skeleton?target=' . urlencode($path) . '&t=' . time();

        return new Response(302, [
            'Location' => $skeletonUrl,
            'X-Instant-Redirect' => '1',
        ]);
    }

    /**
     * 检查是否为已知的合法搜索引擎
     *
     * @param string $userAgent
     *
     * @return bool
     */
    protected function isKnownSearchEngine(string $userAgent): bool
    {
        foreach (self::KNOWN_SEARCH_ENGINES as $searchEngine) {
            if (str_contains($userAgent, $searchEngine)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 检查是否为其他可疑爬虫
     *
     * @param string $userAgent
     *
     * @return bool
     */
    protected function isOtherCrawler(string $userAgent): bool
    {
        foreach (self::OTHER_CRAWLERS as $crawler) {
            if (str_contains($userAgent, $crawler)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 检查控制器方法是否标记了 EnableInstantFirstPaint 注解
     *
     * @param Request $request
     *
     * @return bool
     */
    protected function hasInstantFirstPaintAnnotation(Request $request): bool
    {
        // 如果没有控制器或方法，直接返回 false
        if (!$request->controller || !$request->action) {
            return false;
        }

        try {
            $controllerClass = $request->controller;
            $actionMethod = $request->action;

            // 检查控制器类是否存在
            if (!class_exists($controllerClass)) {
                return false;
            }

            // 检查方法是否存在
            if (!method_exists($controllerClass, $actionMethod)) {
                return false;
            }

            // 获取类级别注解
            $classReflection = new ReflectionClass($controllerClass);
            $classAnnotations = $classReflection->getAttributes(EnableInstantFirstPaint::class);

            // 获取方法级别注解
            $methodReflection = new ReflectionMethod($controllerClass, $actionMethod);
            $methodAnnotations = $methodReflection->getAttributes(EnableInstantFirstPaint::class);

            // 优先使用方法级别注解，如果没有则使用类级别注解
            $annotations = !empty($methodAnnotations) ? $methodAnnotations : $classAnnotations;

            if (empty($annotations)) {
                return false;
            }

            // 获取注解实例并检查是否启用
            $annotation = $annotations[0]->newInstance();

            return $annotation->enabled;
        } catch (Throwable $e) {
            // 出现异常时，返回 false
            return false;
        }
    }
}
