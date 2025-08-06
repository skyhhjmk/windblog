<?php
namespace app\view\extension;

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
        ];
    }

    /**
     * 获取CSRF令牌
     * @return string
     */
    public function getCsrfToken(): string
    {
        // 简单实现CSRF令牌生成功能
        // 在实际项目中可能需要更安全的实现方式
        $request = \request();
        $sessionId = $request->sessionId();
        
        // 基于会话ID和时间生成一个简单的令牌
        // 注意：这只是一个简单的实现，在生产环境中应该使用更安全的方法
        return hash('sha256', $sessionId . time() . uniqid());
    }
}