<?php

namespace app\bootstrap;

use app\service\PluginService;

/**
 * 轻量启动入口：在应用启动阶段调用以加载启用的插件
 */
class PluginBootstrap
{
    public static function init(): void
    {
        if (file_exists(base_path() . '/.env')) {
            PluginService::init();
        }
    }

    public static function loadRoutes(): void
    {
        // 在路由加载后注册插件路由
        if (file_exists(base_path() . '/.env')) {
            $enabled = (array) (blog_config('plugins.enabled', [], true) ?: []);
            foreach ($enabled as $slug) {
                // 重新启用插件以注册路由
                PluginService::enable($slug);
            }
        }
    }
}

\app\bootstrap\PluginBootstrap::init();
