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
        /** @var Response $response */
        $response = $handler($request);

        if (session('is_admin')) {
            // 获取原始内容
            $content = $response->rawBody();

            // 添加调试工具的CSS和JS
            $debugContent = $this->getDebugToolkit();
            $content = str_replace('</body>', $debugContent . '</body>', $content);

            // 重新设置响应内容
            return $response->withBody($content);
        } else {
            return $response;
        }
    }

    private function getDebugToolkit(): string
    {
        return <<<HTML
<!-- Debug Toolkit Start -->
<link rel="stylesheet" href="/assets/css/debug.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.0/css/all.min.css" integrity="sha512-DxV+EoADOkOygM4IR9yXP8Sb2qwgidEmeqAEmDKIOfPRQZOWbXCzLC6vjbZyy0vPisbH2SyW27+ddLVCN+OMzQ==" crossorigin="anonymous" referrerpolicy="no-referrer" />
<script src="/assets/js/debug.js"></script>
<!-- Debug Toolkit End -->
HTML;
    }
}