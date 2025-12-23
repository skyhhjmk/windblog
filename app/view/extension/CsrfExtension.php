<?php

namespace app\view\extension;

use app\service\CSRFHelper;
use Exception;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

// 自定义Twig扩展，用于csrf_token函数
class CsrfExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('csrf_token', [$this, 'getCsrfToken']),
            new TwigFunction('one_time_csrf_token', [$this, 'getOneTimeCsrfToken']),
            new TwigFunction('csrf_token_cookie', [$this, 'setCsrfTokenCookie']),
        ];
    }

    /**
     * 获取CSRF令牌
     *
     * @param string $tokenName token名称
     *
     * @return string
     * @throws Exception
     */
    public function getCsrfToken(string $tokenName = '_token'): string
    {
        return CSRFHelper::generateValue(request(), $tokenName);
    }

    public function getOneTimeCsrfToken(): string
    {
        return CSRFHelper::oneTimeToken(request());
    }

    /**
     * 设置CSRF令牌Cookie
     * 适用于静态缓存场景
     *
     * @param string $tokenName token名称
     *
     * @return void
     * @throws Exception
     */
    public function setCsrfTokenCookie(string $tokenName = '_token'): void
    {
        CSRFHelper::setTokenCookie(request(), $tokenName);
    }
}
