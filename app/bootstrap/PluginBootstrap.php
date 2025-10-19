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
        if (is_installed()) {
            PluginService::init();
        }
    }

    public static function loadRoutes(): void
    {
        // 在路由加载后注册插件路由
        // 注意：不需要此方法，因为 init() 已经通过 loadEnabled() 加载并激活了插件
        // 路由会在 enable() 时自动注册
    }
}

\app\bootstrap\PluginBootstrap::init();
