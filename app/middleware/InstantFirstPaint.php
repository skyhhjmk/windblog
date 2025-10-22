<?php

namespace app\middleware;

use app\annotation\EnableInstantFirstPaint;
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
 * - 对于首屏 HTML GET 请求，快速返回一个极简的 Loading HTML（含微量内联样式与脚本）。
 * - 前端脚本会立即以同 URL 重新请求完整页面，携带自定义请求头以绕过本中间件，然后以 document.write 方式替换整页。
 * - 对 API、后台、插件、静态资源、PJAX/AJAX、机器人 UA 等请求一律放行，避免副作用与 SEO 影响。
 * - 只有添加了 EnableInstantFirstPaint 注解的控制器方法才会触发骨架页逻辑。
 */
class InstantFirstPaint implements MiddlewareInterface
{
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
        if ($request->header('X-INSTANT-BYPASS') === '1' ||
            $request->get('no_instant') == '1' ||
            $request->get('_instant_bypass') === '1') {
            // 绕过骨架页，返回完整页面
            // 完整页面应该被 CDN 缓存，添加缓存头
            $response = $handler($request);

            // 仅对 HTML 响应添加缓存头
            $contentType = $response->getHeader('Content-Type');
            if ($contentType && strpos($contentType[0], 'text/html') !== false) {
                $response->withHeaders([
                    // CDN 缓存 1 小时，浏览器缓存 5 分钟
                    'Cache-Control' => 'public, max-age=300, s-maxage=3600',
                    // 告诉 CDN 这是完整页面，可缓存
                    'X-Content-Type' => 'full-page',
                    // Vary 头确保移动/PC 端分开缓存
                    'Vary' => 'Accept-Encoding',
                ]);
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
        $isAjax = strtolower((string) $request->header('X-Requested-With')) === 'xmlhttprequest' || $request->isAjax();
        $isPjax = ($request->header('X-PJAX') !== null) || $request->get('_pjax') || $request->isPjax();
        if ($isAjax || $isPjax) {
            return $handler($request);
        }

        // 跳过常见爬虫，避免只见 Loading 影响 SEO
        $ua = strtolower((string) $request->header('User-Agent'));
        if ($ua) {
            if (array_any(['bot', 'spider', 'crawler', 'bingpreview', 'slurp', 'duckduckbot', 'baiduspider', 'sogou', 'yisouspider', 'bytespider', 'petalbot', 'google'], fn ($kw) => str_contains($ua, $kw))) {
                return $handler($request);
            }
        }

        // 可选：仅对首页或慢页面启用（按需放开）
        // if ($path !== '/' && !preg_match('#^/(post|category|tag)/#', $path)) {
        //     return $next($request);
        // }

        // 返回一个极简骨架页，前端立即拉取完整页面并替换
        $html = $this->skeletonHtml($request);

        return new Response(200, [
            'Content-Type' => 'text/html; charset=utf-8',
            // 多重防缓存策略，防止 CDN 缓存骨架页
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0, s-maxage=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            // CDN 特殊指令（主流 CDN 支持）
            'CDN-Cache-Control' => 'no-store',
            'Cloudflare-CDN-Cache-Control' => 'no-store',
            'X-Instant' => '1',
        ], $html);
    }

    /**
     * 极简骨架页：最小 HTML + 内联样式 + 自动拉取完整页面
     *
     * @param Request $request
     * @return string
     */
    protected function skeletonHtml(Request $request): string
    {
        // 极致压缩的骨架页，减少首字节
        return <<<'HTML'
            <!doctype html>
            <html lang="zh-CN">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <title>加载中…</title>
            <style>
            html,body{height:100%;margin:0;background:#f9fafb;font-family:system-ui,-apple-system,sans-serif}
            .c{display:flex;align-items:center;justify-content:center;height:100%;flex-direction:column}
            .s{width:40px;height:40px;border:3px solid #e5e7eb;border-top-color:#3b82f6;border-radius:50%;animation:s .8s linear infinite}
            .t{margin-top:16px;color:#6b7280;font-size:14px}
            @keyframes s{to{transform:rotate(360deg)}}
            #p{position:fixed;top:0;left:0;height:2px;background:#3b82f6;width:0;transition:width .3s;z-index:9999}
            </style>
            </head>
            <body>
            <div id="p"></div>
            <div class="c">
            <div class="s"></div>
            <div class="t">首屏构建中…</div>
            </div>
            <script>
                (function(){
                // 循环检测：防止 CDN 缓存造成死循环
                try{var lc=parseInt(sessionStorage.getItem('_ilc')||'0');if(lc>=3){sessionStorage.removeItem('_ilc');location.href=location.href.split('?')[0]+'?no_instant=1&t='+Date.now();return}sessionStorage.setItem('_ilc',lc+1)}catch(e){}
                var p=function(w){try{document.getElementById('p').style.width=w+'%'}catch(e){}}
                var load=function(){
                try{
                var c=new AbortController();
                setTimeout(function(){try{c.abort()}catch(e){}},12e3);
                p(30);
                // 同时使用 Header + Query 参数，CDN 至少会转发 Query
                var sep=location.href.indexOf('?')===-1?'?':'&';
                var bypassUrl=location.href+sep+'_instant_bypass=1&t='+Date.now();
                fetch(bypassUrl,{headers:{'X-INSTANT-BYPASS':'1'},signal:c.signal,credentials:'same-origin'})
                .then(function(r){p(70);return r.text()})
                .then(function(h){
                // 检测是否又返回了骨架页（含有特定标记）
                if(h.indexOf('_ilc')!==-1&&h.length<2000){sessionStorage.removeItem('_ilc');location.href=location.href.split('?')[0]+'?no_instant=1&t='+Date.now();return}
                p(100);try{sessionStorage.removeItem('_ilc')}catch(e){}setTimeout(function(){try{document.open();document.write(h);document.close()}catch(e){location.reload()}},50)
                })
                .catch(function(){location.reload()})
                }catch(e){location.reload()}
                }
                if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',load);else load()
                })()
            </script>
            </body>
            </html>
            HTML;
    }

    /**
     * 检查控制器方法是否标记了 EnableInstantFirstPaint 注解
     *
     * @param Request $request
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
