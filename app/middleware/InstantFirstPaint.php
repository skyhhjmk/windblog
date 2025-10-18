<?php

namespace app\middleware;

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
 */
class InstantFirstPaint implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        // 仅处理 GET
        if (strtoupper($request->method()) !== 'GET') {
            return $handler($request);
        }

        $path = $request->path();
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }

        // 允许通过 Header/Query 显式绕过（防递归/调试）
        if ($request->header('X-INSTANT-BYPASS') === '1' || $request->get('no_instant') == '1') {
            return $handler($request);
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
        $isAjax = strtolower((string) $request->header('X-Requested-With')) === 'xmlhttprequest';
        $isPjax = ($request->header('X-PJAX') !== null) || (bool) $request->get('_pjax');
        if ($isAjax || $isPjax) {
            return $handler($request);
        }

        // 跳过常见爬虫，避免只见 Loading 影响 SEO
        $ua = strtolower((string) $request->header('User-Agent'));
        if ($ua) {
            foreach (['bot', 'spider', 'crawler', 'bingpreview', 'slurp', 'duckduckbot', 'baiduspider', 'sogou', 'yisouspider', 'bytespider', 'petalbot', 'google'] as $kw) {
                if (str_contains($ua, $kw)) {
                    return $handler($request);
                }
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
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
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
        $safeTitle = '加载中…';

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
            var p=function(w){try{document.getElementById('p').style.width=w+'%'}catch(e){}}
            var load=function(){
            try{
            var c=new AbortController();
            setTimeout(function(){try{c.abort()}catch(e){}},12e3);
            p(30);
            fetch(location.href,{headers:{'X-INSTANT-BYPASS':'1'},signal:c.signal,credentials:'same-origin'})
            .then(function(r){p(70);return r.text()})
            .then(function(h){p(100);setTimeout(function(){try{document.open();document.write(h);document.close()}catch(e){location.reload()}},50)})
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
}
