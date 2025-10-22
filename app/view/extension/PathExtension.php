<?php

namespace app\view\extension;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

// 自定义Twig扩展，用于path函数
class PathExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('path', [$this, 'getPath']),
            new TwigFunction('asset', function (string $path, ?string $version = null): string {
                $ver = $version ?? getenv('ASSET_VERSION') ?? getenv('APP_VERSION') ?? date('Ymd');
                $sep = str_contains($path, '?') ? '&' : '?';

                return $path . $sep . 'v=' . $ver;
            }),
        ];
    }

    /**
     * 生成路由路径
     *
     * @param string $name   路由名称
     * @param array  $params 路由参数
     *
     * @return string
     */
    public function getPath(string $name, array $params = []): string
    {
        // 简单实现路由路径生成功能
        // 实际项目中可能需要更复杂的路由匹配逻辑
        $routes = route($name, $params);

        if (!isset($routes)) {
            return '#'; // 如果找不到路由，返回#
        }

        $path = $routes;

        // 替换路径参数
        foreach ($params as $key => $value) {
            $path = str_replace('{' . $key . '}', $value, $path);
        }

        return $path;
    }
}
