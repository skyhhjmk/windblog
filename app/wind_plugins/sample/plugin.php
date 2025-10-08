<?php
/**
 * Plugin Name: Sample Demo
 * Plugin Slug: sample
 * Version: 1.0.0
 * Description: 演示插件：注册一个动作与一个过滤器。
 * Author: WindBlog
 * Requires PHP: 8.2
 * Requires at least: 8.2
 */

use app\service\plugin\PluginInterface;
use app\service\plugin\HookManager;

return new class implements PluginInterface {
    public function activate(HookManager $hooks): void
    {
        // 动作：示例事件
        $hooks->addAction('sample_event', function (string $msg) {
            // 简单演示：此处仅作为示例，不输出日志以避免依赖
        }, 10, 1);

        // 过滤器：示例字符串处理
        $hooks->addFilter('sample_filter', function (string $value) {
            return '[sample] ' . $value;
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