<?php
/**
 * Plugin Name: Sample Demo
 * Plugin Slug: sample
 * Version: 1.0.0
 * Description: 演示插件：注册请求进入/响应退出动作与响应过滤器。
 * Author: WindBlog
 * Requires PHP: 8.2
 * Requires at least: 8.2
 */

use app\service\plugin\PluginInterface;
use app\service\plugin\HookManager;
use Webman\Route;
use support\Response;

return new class implements PluginInterface {
    /**
     * 插件安装时调用
     * 
     * @param HookManager $hooks 钩子管理器
     */
    public function onInstall(HookManager $hooks): void
    {
        // 示例：记录插件安装日志
        try {
            \support\Log::info('[sample-plugin] 插件已安装');
        } catch (\Throwable $e) {
            // 忽略日志异常
        }
        
        // 示例：初始化插件所需数据表或配置
        // 这里可以创建数据库表、初始化配置等操作
    }
    
    /**
     * 插件升级时调用
     * 
     * @param string $prevVersion 之前版本
     * @param string $curVersion 当前版本
     * @param HookManager $hooks 钩子管理器
     */
    public function onUpgrade(string $prevVersion, string $curVersion, HookManager $hooks): void
    {
        // 示例：记录插件升级日志
        try {
            \support\Log::info("[sample-plugin] 插件已从版本 {$prevVersion} 升级到 {$curVersion}");
        } catch (\Throwable $e) {
            // 忽略日志异常
        }
        
        // 示例：根据版本差异执行不同的升级逻辑
        // if (version_compare($prevVersion, '1.1.0', '<')) {
        //     // 执行从1.1.0版本以下升级所需的特定操作
        // }
    }
    public function activate(HookManager $hooks): void
    {
        // 请求进入动作：依授权决定是否执行
        \app\service\PluginService::add_action('request_enter', function ($request) {
            if (\app\service\PluginService::ensurePermission('sample', 'request:action')) {
                // 演示：在请求对象上添加一个属性标记
                try {
                    $request->sample_flag = true;
                } catch (\Throwable $e) {
                    // 保持演示最小侵入，忽略异常
                }
            }
        }, 10, 1);

        // 响应发出前动作：依授权决定是否执行
        \app\service\PluginService::add_action('response_exit', function ($response) {
            if (\app\service\PluginService::ensurePermission('sample', 'request:action')) {
                // 演示：为响应头添加一个示例标记
                try {
                    $response->header('X-Sample-Action', '1');
                } catch (\Throwable $e) {
                    // 忽略异常
                }
            }
        }, 10, 1);

        // 响应过滤器：未声明"request:filter"权限，默认拒绝
        \app\service\PluginService::add_filter('response_filter', function ($resp) {
            // 过滤器调用前强制权限检查：未授权则拒绝修改，返回原对象
            if (!\app\service\PluginService::ensurePermission('sample', 'request:filter')) {
                return $resp;
            }
            // 如获授权（未来可在admin授权后），示例地添加另一个标头
            try {
                $resp->header('X-Sample-Filter', '2');
            } catch (\Throwable $e) {
                // 忽略异常
            }
            return $resp;
        }, 10, 1);
    }

    public function deactivate(HookManager $hooks): void
    {
        // 演示：通常在此移除钩子或清理资源（此处省略）
    }

    public function uninstall(HookManager $hooks): void
    {
        // 演示：彻底清理插件自身数据（此处省略）
    }
    
    /**
     * 注册插件菜单
     * 
     * @param string $type 菜单类型: 'admin' 后台, 'app' 前台
     * @return array 菜单配置数组
     */
    public function registerMenu(string $type): array
    {
        if ($type === 'admin') {
            // 注册后台菜单
            return [
                [
                    'title' => '示例插件',
                    'key' => 'plugin_sample',
                    'icon' => 'layui-icon-app',
                    'type' => 0, // 0: 分组 1: 链接
                    'href' => '', // 当type=1时有效
                    'children' => [
                        [
                            'title' => '仪表板',
                            'key' => 'plugin_sample_dashboard',
                            'icon' => 'layui-icon-console',
                            'type' => 1,
                            'href' => '/app/admin/pluginsandbox/sample/dashboard'
                        ],
                        [
                            'title' => '设置',
                            'key' => 'plugin_sample_settings',
                            'icon' => 'layui-icon-set',
                            'type' => 1,
                            'href' => '/app/admin/pluginsandbox/sample/settings'
                        ]
                    ]
                ]
            ];
        } else if ($type === 'app') {
            // 注册前台菜单
            return [
                [
                    'title' => '示例插件',
                    'key' => 'plugin_sample_app',
                    'icon' => '',
                    'type' => 1,
                    'href' => '/plugin/sample/index'
                ]
            ];
        }
        
        return [];
    }
    
    /**
     * 注册插件路由
     * 
     * @param string $pluginSlug 插件标识
     * @return array 路由配置数组
     */
    public function registerRoutes(string $pluginSlug): array
    {
        return [
            [
                'method' => 'get',
                'route' => "/app/admin/plugin/{$pluginSlug}/dashboard",
                'handler' => function () {
                    return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], '<h1>示例插件仪表板页面</h1>');
                },
                'permission' => "plugin:{$pluginSlug}:route:dashboard"
            ],
            [
                'method' => 'get',
                'route' => "/app/admin/plugin/{$pluginSlug}/settings",
                'handler' => function () {
                    return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], '<h1>示例插件设置页面</h1>');
                },
                'permission' => "plugin:{$pluginSlug}:route:settings"
            ],
            [
                'method' => 'get',
                'route' => "/plugin/{$pluginSlug}/index",
                'handler' => function () {
                    return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], '<h1>示例插件前台页面</h1>');
                },
                'permission' => "plugin:{$pluginSlug}:route:index"
            ]
        ];
    }
};