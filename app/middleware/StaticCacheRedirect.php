<?php

namespace app\middleware;

use function public_path;

use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 静态缓存重定向中间件
 * 命中静态HTML缓存时直接返回，提升性能
 */
class StaticCacheRedirect implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
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
        // 简单登录判断：存在后台登录会话/令牌则绕过（可根据项目实际调整）
        $adminSession = $request->session()?->get('admin');
        $adminToken = $request->cookie('admin_token');
        if ($adminSession || $adminToken) {
            return $handler($request);
        }

        // 将URL路径映射到 public/cache/static 下的文件
        $rel = ltrim($path, '/');
        if ($rel === '') {
            $rel = 'index.html';
        } else {
            // 去除已有的 .html 后缀，再统一加 .html
            $rel = preg_replace('#\.html$#', '', $rel) . '.html';
        }
        $full = public_path() . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'static' . DIRECTORY_SEPARATOR . $rel;
        if (is_file($full)) {
            // 命中静态文件，直接返回
            $body = file_get_contents($full);
            // 基础缓存头（可根据需要调优）
            $generatedAt = @filemtime($full) ?: time();

            return new Response(200, [
                'Content-Type' => 'text/html; charset=utf-8',
                'Cache-Control' => 'public, max-age=60',
                'X-Static-Cache' => gmdate('c', $generatedAt),
            ], $body);
        }

        // 未命中，走后续业务
        return $handler($request);
    }
}
