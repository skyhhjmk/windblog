<?php

namespace app\controller;

use app\service\I18nService;
use support\Request;
use support\Response;

class LangController
{
    protected array $noNeedLogin = ['change'];

    public function change(Request $request, string $code): Response
    {
        // 仅允许已启用语言
        $allowed = array_column(I18nService::getAvailableLocales(), 'code');
        if (!in_array($code, $allowed, true)) {
            $code = (string)blog_config('default_locale', 'zh_CN', true);
        }
        $back = (string)($request->header('referer') ?: '/');
        $cookie = I18nService::setLocaleCookie($code);
        $html = "<!DOCTYPE html><meta charset='utf-8'><script>document.cookie='" . $cookie . "';location.replace('" . htmlspecialchars($back, ENT_QUOTES) . "');</script>";
        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }
}
