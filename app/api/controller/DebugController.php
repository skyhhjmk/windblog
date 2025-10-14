<?php

namespace app\api\controller;

use support\Request;
use Webman\Http\Response;

class DebugController
{
    /**
     * 获取服务器信息
     * @param Request $request
     * @return Response
     */
    public function serverInfo(Request $request): Response
    {
        $serverInfo = [
            'PHP版本' => PHP_VERSION,
            '服务器软件' => $_SERVER['SERVER_SOFTWARE'] ?? 'N/A',
            '服务器时间' => date('Y-m-d H:i:s'),
            '内存使用' => round(memory_get_usage() / 1024 / 1024, 2) . ' MB',
            '峰值内存使用' => round(memory_get_peak_usage() / 1024 / 1024, 2) . ' MB',
            '操作系统' => php_uname(),
            '最大执行时间' => ini_get('max_execution_time') . '秒',
            '内存限制' => ini_get('memory_limit'),
        ];

        return json($serverInfo);
    }

    /**
     * 获取请求信息
     * @param Request $request
     * @return Response
     */
    public function requestInfo(Request $request): Response
    {
        $requestInfo = [
            '请求方法' => $request->method(),
            '请求URI' => $request->uri(),
            '查询参数' => $request->get(),
            'POST数据' => $request->post(),
            '请求头' => $request->header(),
            '用户代理' => $request->header('user-agent', 'N/A'),
            '远程地址' => $request->getRealIp(),
        ];

        return json($requestInfo);
    }

    /**
     * 获取响应信息
     * @param Request $request
     * @return Response
     */
    public function responseInfo(Request $request): Response
    {
        // 这里可以返回一些通用的响应信息
        // 在实际应用中，这些信息可能需要通过其他方式获取
        $responseInfo = [
            '状态码' => 200,
            '内容类型' => 'application/json',
            '响应时间' => date('Y-m-d H:i:s'),
            '框架版本' => 'Webman Framework',
        ];

        return json($responseInfo);
    }
}
