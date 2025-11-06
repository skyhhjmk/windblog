<?php

namespace app\controller;

use app\service\I18nService;
use support\Log;
use support\Request;
use support\Response;

/**
 * 语言切换控制器
 *
 * 支持多种响应方式：
 * 1. HTML重定向 - 适用于传统链接
 * 2. JSON响应 - 适用于AJAX请求
 * 3. 无刷新切换 - 适用于SPA应用
 */
class LangController
{
    protected array $noNeedLogin = ['change', 'list'];

    /**
     * 切换语言
     *
     * GET /lang/{code} - 页面重定向方式
     * GET /lang/{code}?ajax=1 - AJAX方式，返回JSON
     * POST /lang/{code} - 无刷新切换，返回JSON
     *
     * @param Request $request
     * @param string  $code 语言代码（如zh_CN、en、ja）
     *
     * @return Response
     */
    public function change(Request $request, string $code): Response
    {
        // 验证语言代码
        $allowed = array_column(I18nService::getAvailableLocales(), 'code');
        if (!in_array($code, $allowed, true)) {
            Log::warning("尝试切换到不支持的语言: {$code}");
            $code = (string) blog_config('default_locale', 'zh_CN', true);
        }

        // 设置Cookie，有效期1年）
        $response = $this->createResponse($request, $code);

        // 设置Cookie（优先级最高）
        $response->cookie('locale', $code, time() + 365 * 24 * 3600, '/');

        // 同步到Session（向后兼容）
        session(['lang' => $code]);

        return $response;
    }

    /**
     * 获取支持的语言列表
     *
     * GET /lang/list
     *
     * @param Request $request
     *
     * @return Response
     */
    public function list(Request $request): Response
    {
        $locales = I18nService::getAvailableLocales();
        $current = I18nService::getCurrentLocale($request);

        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => [
                'current' => $current,
                'locales' => $locales,
            ],
        ]);
    }

    /**
     * 创建响应（根据请求类型）
     *
     * @param Request $request
     * @param string  $code
     *
     * @return Response
     */
    private function createResponse(Request $request, string $code): Response
    {
        $isAjax = $request->isAjax() || $request->get('ajax') === '1';
        $isPost = $request->method() === 'POST';

        // AJAX请求或POST请求返回JSON
        if ($isAjax || $isPost) {
            return $this->jsonResponse($code);
        }

        // 普通GET请求返回HTML重定向
        return $this->htmlRedirectResponse($request, $code);
    }

    /**
     * JSON响应
     *
     * @param string $code
     *
     * @return Response
     */
    private function jsonResponse(string $code): Response
    {
        return json([
            'code' => 0,
            'msg' => trans('Language switched successfully'),
            'data' => [
                'locale' => $code,
            ],
        ]);
    }

    /**
     * HTML重定向响应
     *
     * @param Request $request
     * @param string  $code
     *
     * @return Response
     */
    private function htmlRedirectResponse(Request $request, string $code): Response
    {
        // 获取返回URL（优先使用referer，否则返回首页）
        $back = $this->getRedirectUrl($request);

        // 生成Cookie设置脚本
        $cookie = I18nService::setLocaleCookie($code);

        // 返回自动跳转的HTML页面
        $html = $this->buildRedirectHtml($cookie, $back);

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }

    /**
     * 获取重定向URL
     *
     * @param Request $request
     *
     * @return string
     */
    private function getRedirectUrl(Request $request): string
    {
        // 优先使用redirect参数
        $redirect = $request->get('redirect');
        if ($redirect) {
            // 防止开放重定向漏洞
            if ($this->isValidRedirectUrl($redirect)) {
                return $redirect;
            }
        }

        // 其次使用referer
        $referer = $request->header('referer');
        if ($referer && $this->isValidRedirectUrl($referer)) {
            return $referer;
        }

        // 默认返回首页
        return '/';
    }

    /**
     * 验证重定向URL是否安全
     *
     * @param string $url
     *
     * @return bool
     */
    private function isValidRedirectUrl(string $url): bool
    {
        // 防止开放重定向：只允许相对路径或同域名URL
        if (strpos($url, '/') === 0) {
            return true; // 相对路径
        }

        // 检查是否为同域名
        $urlHost = parse_url($url, PHP_URL_HOST);
        $currentHost = request()->host();

        return $urlHost === $currentHost;
    }

    /**
     * 构建重定向HTML
     *
     * @param string $cookie
     * @param string $redirectUrl
     *
     * @return string
     */
    private function buildRedirectHtml(string $cookie, string $redirectUrl): string
    {
        $safeUrl = htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex, nofollow">
    <title>Switching Language...</title>
</head>
<body>
    <script>
        // 设置Cookie
        document.cookie = '{$cookie}';
        // 重定向
        location.replace('{$safeUrl}');
    </script>
    <noscript>
        <meta http-equiv="refresh" content="0; url={$safeUrl}">
        <p>Redirecting... <a href="{$safeUrl}">Click here if not redirected</a></p>
    </noscript>
</body>
</html>
HTML;
    }
}
