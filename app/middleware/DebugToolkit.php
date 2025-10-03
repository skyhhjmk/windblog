<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

/**
 * Class DebugToolkit
 * @package app\middleware
 */
class DebugToolkit implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $start = microtime(true);

        /** @var Response $response */
        $response = $handler($request);

        // 权限策略：必须存在 Header X-Debug-Toolkit=1
        $hasHeader = $request->header('x-debug-toolkit') === '1' || $request->header('X-Debug-Toolkit') === '1';
        $hasQuery  = !empty($request->get('debug'));
        $hasCookie = $request->cookie('debug_toolkit') === '1';

//        if (!$hasHeader) {
//            return $response;
//        }

        // 安全注入：仅当响应体可用且包含 </body>，且未已注入
        $content = $response->rawBody();
        if (!is_string($content)) {
            return $response;
        }
        if (stripos($content, '</body>') === false) {
            return $response;
        }
        if (strpos($content, '<!-- Debug Toolkit Start -->') !== false) {
            return $response;
        }

        // 采集数据（轻量）
        $end = microtime(true);
        $timingTotalMs = round(($end - $start) * 1000, 2);

        $data = [
            'trigger' => [
                'header' => $hasHeader,
                'query' => $hasQuery,
                'cookie' => $hasCookie,
            ],
            'request' => [
                'method' => $request->method(),
                'uri' => $request->uri(),
                'path' => $request->path(),
                'query' => $request->get(),
                'cookies' => $request->cookie(),
                // 头部仅保留常见字段，避免过大
                'headers' => [
                    'host' => $request->header('host'),
                    'user-agent' => $request->header('user-agent'),
                    'accept' => $request->header('accept'),
                    'content-type' => $request->header('content-type'),
                    'x-requested-with' => $request->header('x-requested-with'),
                ],
            ],
            'response' => [
                // Webman Response 可能不同实现，这里只提供精要占位
                'status' => method_exists($response, 'getStatusCode') ? $response->getStatusCode() : null,
                'headers' => method_exists($response, 'getHeaders') ? $response->getHeaders() : [],
                'length' => is_string($content) ? strlen($content) : null,
            ],
            'timing' => [
                'total_ms' => $timingTotalMs,
            ],

            'logs' => [
                'recent' => [],
                'endpoint' => '/api/debug/logs',
                'note' => '前端通过 /api/debug/logs 拉取最近N条日志',
            ],
            'sql' => [
                'queries' => [],
                'note' => '如已接入PDO事件或ORM监听可填充此处；当前为占位',
            ],
        ];

        // 生成调试工具注入片段
        $debugContent = $this->getDebugToolkit($data);

        $content = str_replace('</body>', $debugContent . '</body>', $content);
        return $response->withBody($content);
    }

    private function getDebugToolkit(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return <<<HTML
<!-- Debug Toolkit Start -->
<link rel="stylesheet" href="/assets/css/debug.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.0/css/all.min.css" integrity="sha512-DxV+EoADOkOygM4IR9yXP8Sb2qwgidEmeqAEmDKIOfPRQZOWbXCzLC6vjbZyy0vPisbH2SyW27+ddLVCN+OMzQ==" crossorigin="anonymous" referrerpolicy="no-referrer" />
<!-- Tailwind CDN（按你的选择加载） -->
<script src="https://cdn.tailwindcss.com"></script>
<script>window.__DEBUG_TOOLKIT_DATA = {$json};</script>
<script src="/assets/js/debug.js"></script>
<!-- Debug Toolkit End -->
HTML;
    }
}