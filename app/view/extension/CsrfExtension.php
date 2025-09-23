<?php
namespace app\view\extension;

use app\service\CSRFHelper;
use Exception;
use support\Request;
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
        ];
    }

    /**
     * 获取CSRF令牌
     *
     * @return string
     * @throws Exception
     */
    public function getCsrfToken(): string
    {
        return CSRFHelper::generateValue(request());
    }

    public function getOneTimeCsrfToken(): string
    {
        return CSRFHelper::oneTimeToken(request());
    }
}