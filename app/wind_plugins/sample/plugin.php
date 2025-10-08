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

return new class implements PluginInterface {
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

        // 响应过滤器：未声明“request:filter”权限，默认拒绝
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
};