<?php

namespace app\api\controller;

use app\service\LinkConnectService;
use support\Request;
use support\Response;
use Throwable;

/**
 * 友链互联控制器
 * 负责处理/api/wind-connect请求，接收来自其他站点的友链申请
 */
class WindConnectController
{
    /**
     * 处理友链互联请求
     *
     * @param Request $request HTTP请求对象
     * @return Response JSON响应
     */
    public function index(Request $request): Response
    {
        try {
            // 获取请求头
            $headers = $request->header();

            // 获取请求体（JSON格式）
            $body = $request->rawBody();
            $payload = json_decode($body, true);

            // 检查请求体是否为有效JSON
            if (json_last_error() !== JSON_ERROR_NONE) {
                return json(['code' => 1, 'msg' => '无效的JSON格式']);
            }

            // 调用LinkConnectService处理友链互联请求
            $result = LinkConnectService::receiveFromPeer($headers, $payload);

            // 返回处理结果
            return json($result);
        } catch (Throwable $e) {
            // 记录异常并返回错误信息
            return json(['code' => 1, 'msg' => '处理请求时发生错误: ' . $e->getMessage()]);
        }
    }
}
