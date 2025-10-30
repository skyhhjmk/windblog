<?php

namespace app\controller;

use support\Request;
use support\Response;

/**
 * Push 配置控制器
 */
class PushConfigController
{
    /**
     * 不需要登录的方法
     *
     * @var array
     */
    protected $noNeedLogin = ['config'];

    /**
     * 获取 Push 公开配置
     *
     * @param Request $request
     *
     * @return Response
     */
    public function config(Request $request): Response
    {
        // 只返回公开的配置信息
        $config = [
            'websocket' => str_replace('0.0.0.0', $request->host(), config('plugin.webman.push.app.websocket')),
            'app_key' => config('plugin.webman.push.app.app_key'),
            'auth' => config('plugin.webman.push.app.auth'),
        ];

        return json([
            'code' => 0,
            'data' => $config,
        ]);
    }
}
