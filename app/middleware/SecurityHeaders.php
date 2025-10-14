<?php

namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

/**
 * 安全响应头中间件
 *
 * 添加安全相关的HTTP响应头，防止常见的Web攻击
 */
class SecurityHeaders implements MiddlewareInterface
{
    /**
     * 处理请求
     *
     * @param Request $request
     * @param callable $handler
     * @return Response
     */
    public function process(Request $request, callable $handler): Response
    {
        $response = $handler($request);

        // X-Frame-Options: 防止点击劫持攻击
        // SAMEORIGIN: 只允许同源的页面嵌入
        $response->withHeader('X-Frame-Options', config('security.x_frame_options', 'SAMEORIGIN'));

        // X-Content-Type-Options: 防止MIME类型嗅探
        // nosniff: 禁止浏览器推测资源类型
        $response->withHeader('X-Content-Type-Options', 'nosniff');

        // X-XSS-Protection: 启用浏览器XSS过滤
        // 1; mode=block: 启用XSS过滤，并阻止页面加载
        $response->withHeader('X-XSS-Protection', '1; mode=block');

        // Referrer-Policy: 控制Referer头的发送
        // strict-origin-when-cross-origin: 同源时发送完整URL，跨域时只发送origin
        $response->withHeader('Referrer-Policy', config('security.referrer_policy', 'strict-origin-when-cross-origin'));

        // Permissions-Policy: 控制浏览器功能和API的使用
        $permissionsPolicy = config('security.permissions_policy', [
            'camera' => '()',
            'microphone' => '()',
            'geolocation' => '()',
            'payment' => '()',
        ]);
        if (!empty($permissionsPolicy)) {
            $policyString = implode(', ', array_map(
                fn($key, $value) => "$key=$value",
                array_keys($permissionsPolicy),
                $permissionsPolicy
            ));
            $response->withHeader('Permissions-Policy', $policyString);
        }

        // Content-Security-Policy: 内容安全策略
        // 这是最重要的安全头，需要根据实际情况配置
        $csp = config('security.content_security_policy', null);
        if ($csp) {
            if (is_array($csp)) {
                $cspString = implode('; ', array_map(
                    fn($key, $value) => "$key $value",
                    array_keys($csp),
                    $csp
                ));
            } else {
                $cspString = $csp;
            }
            $response->withHeader('Content-Security-Policy', $cspString);
        }

        // Strict-Transport-Security: 强制HTTPS
        // 仅在HTTPS环境下启用
        if ($request->connection->transport === 'ssl' || config('security.force_hsts', false)) {
            $hstsMaxAge = config('security.hsts_max_age', 31536000); // 默认1年
            $hstsIncludeSubDomains = config('security.hsts_include_subdomains', true);
            $hstsPreload = config('security.hsts_preload', false);

            $hstsValue = "max-age=$hstsMaxAge";
            if ($hstsIncludeSubDomains) {
                $hstsValue .= '; includeSubDomains';
            }
            if ($hstsPreload) {
                $hstsValue .= '; preload';
            }

            $response->withHeader('Strict-Transport-Security', $hstsValue);
        }

        // X-Permitted-Cross-Domain-Policies: 控制跨域策略文件
        $response->withHeader('X-Permitted-Cross-Domain-Policies', 'none');

        // X-Download-Options: 防止IE执行下载的文件
        $response->withHeader('X-Download-Options', 'noopen');

        // 移除可能泄露服务器信息的头
        $response->withoutHeader('X-Powered-By');
        $response->withoutHeader('Server');

        return $response;
    }
}
