<?php

namespace app\controller;

use app\service\CaptchaService;
use support\Request;
use support\Response;

class CaptchaController
{
    protected array $noNeedLogin = ['image', 'config'];

    /**
     * 生成图形验证码
     */
    public function image(Request $request): Response
    {
        return CaptchaService::generateImageCaptcha($request);
    }

    /**
     * 获取验证码配置
     */
    public function config(Request $request): Response
    {
        return json([
            'code' => 0,
            'data' => CaptchaService::getFrontendConfig(),
        ]);
    }
}
