<?php

namespace app\service\plugin;

/**
 * 插件接口（要求：必须实现 activate / deactivate / uninstall）
 * 插件可在 activate/deactivate 内注册/移除钩子或执行初始化/清理逻辑
 */
interface PluginInterface
{
    /**
     * 插件激活（首次或再次启用）
     */
    public function activate(HookManager $hooks): void;

    /**
     * 插件停用（从启用态切换为禁用态）
     */
    public function deactivate(HookManager $hooks): void;

    /**
     * 插件卸载（进行彻底清理，通常删除自身持久化数据）
     */
    public function uninstall(HookManager $hooks): void;
}