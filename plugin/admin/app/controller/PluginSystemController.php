<?php

namespace plugin\admin\app\controller;

use support\Request;
use support\Response;
use app\service\PluginService;

/**
 * 独立插件系统管理页与API（纯HTML+API）
 * - 页面：/app/admin/plugin-system/index
 * - API：/app/admin/plugin-system/list, /enable, /disable, /uninstall
 */
class PluginSystemController extends Base
{
    /**
     * 管理页：返回纯HTML
     */
    public function index(Request $request): Response
    {
        $path = base_path()
            . DIRECTORY_SEPARATOR . 'plugin'
            . DIRECTORY_SEPARATOR . 'admin'
            . DIRECTORY_SEPARATOR . 'app'
            . DIRECTORY_SEPARATOR . 'view'
            . DIRECTORY_SEPARATOR . 'plugin_system'
            . DIRECTORY_SEPARATOR . 'index.html';
        if (is_file($path)) {
            return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], (string)file_get_contents($path));
        }
        return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8'], 'plugin system page not found');
    }

    /**
     * 列出扫描到的插件及启用状态（支持搜索/筛选/分页）
     * GET 参数：
     * - q: 关键词（匹配 name/slug/description/author）
     * - status: enabled/disabled/all（默认all）
     * - page: 页码（从1开始）
     * - limit: 每页数量（默认10）
     */
    public function list(Request $request): Response
    {
        $q = (string)($request->get('q', ''));
        $status = (string)($request->get('status', 'all'));
        $page = max(1, (int)$request->get('page', 1));
        $limit = max(1, min(100, (int)$request->get('limit', 10)));

        $plugins = PluginService::all_plugins();
        $enabled = (array)(blog_config('plugins.enabled', [], true) ?: []);

        $items = [];
        foreach ($plugins as $slug => $meta) {
            $row = [
                'slug' => $slug,
                'name' => $meta->name ?? $slug,
                'version' => $meta->version ?? '',
                'author' => $meta->author ?? '',
                'description' => $meta->description ?? '',
                'enabled' => in_array($slug, $enabled, true),
                'requires_php' => $meta->requires_php ?? '',
                'requires_at_least' => $meta->requires_at_least ?? '',
            ];
            $items[] = $row;
        }

        // 筛选：状态
        if ($status === 'enabled') {
            $items = array_values(array_filter($items, fn($it) => !empty($it['enabled'])));
        } elseif ($status === 'disabled') {
            $items = array_values(array_filter($items, fn($it) => empty($it['enabled'])));
        }

        // 搜索：q
        if ($q !== '') {
            $qq = mb_strtolower($q);
            $items = array_values(array_filter($items, function ($it) use ($qq) {
                $pool = [
                    (string)($it['name'] ?? ''),
                    (string)($it['slug'] ?? ''),
                    (string)($it['description'] ?? ''),
                    (string)($it['author'] ?? ''),
                ];
                $joined = mb_strtolower(implode(' ', $pool));
                return str_contains($joined, $qq);
            }));
        }

        // 排序
        $field = (string)$request->get('field', '');
        $order = (string)$request->get('order', '');
        $sortable = [
            'name', 'slug', 'version', 'author', 'enabled',
            'requires_php', 'requires_at_least'
        ];
        if ($field && in_array($field, $sortable, true) && ($order === 'asc' || $order === 'desc')) {
            usort($items, function ($a, $b) use ($field, $order) {
                $va = $a[$field] ?? '';
                $vb = $b[$field] ?? '';
                // bool 转数字，其他转字符串比较
                if ($field === 'enabled') {
                    $va = $va ? 1 : 0;
                    $vb = $vb ? 1 : 0;
                } else {
                    $va = (string)$va;
                    $vb = (string)$vb;
                }
                if ($va == $vb) return 0;
                $cmp = $va < $vb ? -1 : 1;
                return $order === 'asc' ? $cmp : -$cmp;
            });
        }

        $count = count($items);
        // 分页
        $offset = ($page - 1) * $limit;
        $paged = array_slice($items, $offset, $limit);

        return json(['code' => 0, 'msg' => 'ok', 'data' => $paged, 'count' => $count]);
    }

    /**
     * 启用插件
     */
    public function enable(Request $request): Response
    {
        $slug = (string)$request->post('slug');
        if ($slug === '') {
            return json(['code' => 1, 'msg' => '缺少slug']);
        }
        $ok = PluginService::enable($slug);
        return json(['code' => $ok ? 0 : 1, 'msg' => $ok ? 'ok' : '启用失败']);
    }

    /**
     * 停用插件
     */
    public function disable(Request $request): Response
    {
        $slug = (string)$request->post('slug');
        if ($slug === '') {
            return json(['code' => 1, 'msg' => '缺少slug']);
        }
        $ok = PluginService::disable($slug);
        return json(['code' => $ok ? 0 : 1, 'msg' => $ok ? 'ok' : '停用失败']);
    }

    /**
     * 卸载插件（调用插件卸载钩子并从启用列表移除）
     */
    public function uninstall(Request $request): Response
    {
        $slug = (string)$request->post('slug');
        if ($slug === '') {
            return json(['code' => 1, 'msg' => '缺少slug']);
        }
        $ok = PluginService::uninstall($slug);
        return json(['code' => $ok ? 0 : 1, 'msg' => $ok ? 'ok' : '卸载失败']);
    }

    /**
     * 权限详情（声明/已授权/待授权 + 每项统计）
     */
    public function permissions(Request $request): Response
    {
        $slug = (string)$request->get('slug', '');
        if ($slug === '') {
            return json(['code' => 1, 'msg' => '缺少slug']);
        }
        $declared = PluginService::getDeclaredPermissions($slug);
        $granted = PluginService::getGrantedPermissions($slug);
        $pending = PluginService::getPendingPermissions($slug);

        $stats = [];
        foreach ($declared as $perm) {
            $base = PluginService::getCounts($slug, (string)$perm);
            $win  = PluginService::getWindowCounts($slug, (string)$perm);
            $stats[$perm] = array_merge($base, $win);
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'declared' => $declared,
            'granted' => $granted,
            'pending' => $pending,
            'stats' => $stats,
        ]]);
    }

    /**
     * 批量授权
     */
    public function grantPermissions(Request $request): Response
    {
        $slug = (string)$request->post('slug');
        $perms = (array)$request->post('permissions');
        if ($slug === '' || empty($perms)) {
            return json(['code' => 1, 'msg' => '缺少参数']);
        }
        PluginService::grantPermissions($slug, array_map('strval', $perms));
        return json(['code' => 0, 'msg' => 'ok']);
    }

    /**
     * 批量撤销
     */
    public function revokePermissions(Request $request): Response
    {
        $slug = (string)$request->post('slug');
        $perms = (array)$request->post('permissions');
        if ($slug === '' || empty($perms)) {
            return json(['code' => 1, 'msg' => '缺少参数']);
        }
        PluginService::revokePermissions($slug, array_map('strval', $perms));
        return json(['code' => 0, 'msg' => 'ok']);
    }

    /**
     * 单项授权
     */
    public function grantPermission(Request $request): Response
    {
        $slug = (string)$request->post('slug');
        $perm = (string)$request->post('permission');
        if ($slug === '' || $perm === '') {
            return json(['code' => 1, 'msg' => '缺少参数']);
        }
        PluginService::grantPermission($slug, $perm);
        return json(['code' => 0, 'msg' => 'ok']);
    }

    /**
     * 单项撤销
     */
    public function revokePermission(Request $request): Response
    {
        $slug = (string)$request->post('slug');
        $perm = (string)$request->post('permission');
        if ($slug === '' || $perm === '') {
            return json(['code' => 1, 'msg' => '缺少参数']);
        }
        PluginService::revokePermission($slug, $perm);
        return json(['code' => 0, 'msg' => 'ok']);
    }
    
    /**
     * 获取插件注册的后台菜单
     */
    public function pluginMenus(Request $request): Response
    {
        // 通过反射获取 PluginService 中的 manager 实例
        $pluginServiceRef = new \ReflectionClass(\app\service\PluginService::class);
        $managerProp = $pluginServiceRef->getProperty('manager');
        $managerProp->setAccessible(true);
        $manager = $managerProp->getValue(null);
        
        if ($manager) {
            $adminMenus = $manager->getAdminMenus();
            $formattedMenus = [];
            
            foreach ($adminMenus as $pluginSlug => $menus) {
                // 确保菜单格式正确
                if (is_array($menus) && !empty($menus)) {
                    $formattedMenus[] = $menus;
                }
            }
            
            return json(['code' => 0, 'msg' => 'ok', 'data' => array_values($formattedMenus)]);
        }
        
        return json(['code' => 0, 'msg' => 'ok', 'data' => []]);
    }
    
    /**
     * 处理插件请求
     */
    public function handlePluginRequest(Request $request, string $slug = '', string $action = ''): Response
    {
        if (!$slug || !$action) {
            return new Response(400, [], 'Missing plugin slug or action');
        }
        
        // 检查插件是否启用
        $enabled = (array)(blog_config('plugins.enabled', [], true) ?: []);
        if (!in_array($slug, $enabled, true)) {
            return new Response(404, [], "Plugin {$slug} not found or not enabled");
        }
        
        // 获取插件管理器
        $pluginServiceRef = new \ReflectionClass(\app\service\PluginService::class);
        $managerProp = $pluginServiceRef->getProperty('manager');
        $managerProp->setAccessible(true);
        $manager = $managerProp->getValue(null);
        
        if (!$manager) {
            return new Response(500, [], 'Plugin manager not available');
        }
        
        // 强制注册插件路由
        $manager->forceRegisterRoutes($slug);
        
        // 构造原始请求路径 - 使用实际的插件路由路径
        $originalPath = "/app/admin/plugin/{$slug}/{$action}";
        
        // 直接调用插件的路由处理器，而不是通过Webman路由调度器
        $plugins = $manager->allMetadata();
        if (!isset($plugins[$slug])) {
            return new Response(404, [], "Plugin {$slug} not found");
        }
        
        // 获取插件实例
        $pluginEntry = null;
        $pluginsProperty = new \ReflectionProperty($manager, 'plugins');
        $pluginsProperty->setAccessible(true);
        $pluginData = $pluginsProperty->getValue($manager);
        
        if (isset($pluginData[$slug])) {
            $pluginEntry = $pluginData[$slug];
        }
        
        if (!$pluginEntry || !isset($pluginEntry['instance'])) {
            return new Response(500, [], 'Plugin instance not available');
        }
        
        $pluginInstance = $pluginEntry['instance'];
        $routes = $pluginInstance->registerRoutes($slug);
        
        // 查找匹配的路由
        foreach ($routes as $route) {
            if (!isset($route['method']) || !isset($route['route']) || !isset($route['handler'])) {
                continue;
            }
            
            $method = strtolower($route['method']);
            $routePath = $route['route'];
            $handler = $route['handler'];
            
            // 检查方法是否匹配
            if ($method !== strtolower($request->method())) {
                continue;
            }
            
            // 检查路径是否匹配
            if ($routePath === $originalPath) {
                // 执行处理器
                try {
                    $response = call_user_func($handler, $request);
                    if ($response instanceof Response) {
                        return $response;
                    }
                    return new Response(200, [], (string)$response);
                } catch (\Throwable $e) {
                    return new Response(500, [], 'Internal Server Error: ' . $e->getMessage());
                }
            }
        }
        
        return new Response(404, [], "Plugin route {$originalPath} not found");
    }
}