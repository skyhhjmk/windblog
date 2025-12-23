<?php

namespace app\middleware;

use app\service\CSRFService;
use support\Request;
use Webman\Http\Response as HttpResponse;
use Webman\MiddlewareInterface;

/**
 * CSRF Token中间件
 * 自动为需要CSRF保护的页面设置token
 */
class CSRFTokenMiddleware implements MiddlewareInterface
{
    /**
     * 处理请求
     *
     * @param Request  $request 请求对象
     * @param callable $handler 下一个处理器
     *
     * @return HttpResponse
     */
    public function process(Request $request, callable $handler): HttpResponse
    {
        // 只对GET请求设置CSRF token
        if ($request->method() === 'GET') {
            $csrfService = new CSRFService();

            // 生成token并设置到Cookie
            $csrfService->generateToken($request, '_token', [
                'set_cookie' => true,
                'expire' => 3600, // 1小时过期
            ]);

            // 为登出操作生成一次性token
            $csrfService->generateToken($request, '_logout_token', [
                'set_cookie' => true,
                'expire' => 3600, // 1小时过期
                'one_time' => true,
            ]);
        }

        // 继续处理请求
        return $handler($request);
    }
}
