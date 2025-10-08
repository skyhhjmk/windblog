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
        PluginService::init();
    }
}
\app\bootstrap\PluginBootstrap::init();