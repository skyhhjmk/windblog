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
    
    /**
     * 注册插件菜单
     * 
     * @param string $type 菜单类型: 'admin' 后台, 'app' 前台
     * @return array 菜单配置数组
     * 
     * 示例返回值:
     * [
     *     [
     *         'title' => '插件菜单',
     *         'key' => 'plugin_sample',
     *         'icon' => 'layui-icon-app',
     *         'type' => 0, // 0: 分组 1: 链接
     *         'href' => '', // 当type=1时有效
     *         'children' => [
     *             [
     *                 'title' => '子菜单',
     *                 'key' => 'plugin_sample_child',
     *                 'icon' => '',
     *                 'type' => 1,
     *                 'href' => '/app/admin/plugin/sample/index'
     *             ]
     *         ]
     *     ]
     * ]
     */
    public function registerMenu(string $type): array;
    
    /**
     * 注册插件路由
     * 
     * @param string $pluginSlug 插件标识
     * @return array 路由配置数组
     * 
     * 示例返回值:
     * [
     *     [
     *         'method' => 'get', // 路由方法: get, post, put, delete 等
     *         'route' => "/app/admin/plugin/sample/index", // 路由路径
     *         'handler' => function() { return response('Hello'); }, // 处理函数
     *         'permission' => 'plugin.sample.route.index' // 路由权限
     *     ]
     * ]
     */
    public function registerRoutes(string $pluginSlug): array;
}