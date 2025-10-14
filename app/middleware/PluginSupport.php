<?php

namespace app\middleware;

use app\service\PluginService;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

class PluginSupport implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        // 请求进入时动作钩子
        PluginService::do_action('request_enter', $request);

        // 继续处理
        $response = $handler($request);

        // 响应过滤器（允许插件尝试变更响应；未授权将默认拒绝修改）
        $response = PluginService::apply_filters('response_filter', $response);

        // 响应发出前动作钩子
        PluginService::do_action('response_exit', $response);

        return $response;
    }
}
