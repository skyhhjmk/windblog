<?php

namespace app\service;

use app\model\Setting;
use support\Request;
use support\Log;
use Throwable;

/**
 * 侧边栏服务类
 * 用于管理和获取博客侧边栏的内容和配置
 */
class SidebarService
{
    /**
     * 设置键名
     */
    const SETTING_KEY = 'sidebar';

    /**
     * 获取当前页面的侧边栏内容
     *
     * @param Request $request 请求对象
     * @param string  $pageKey 页面标识，如果为空则自动检测
     *
     * @return array 侧边栏配置数组
     */
    public static function getSidebarContent(Request $request, string $pageKey = ''): array
    {
        try {
            if (empty($pageKey)) {
                $pageKey = self::detectPageKey($request);
            }

            return self::getSidebarByPage($pageKey);
        } catch (Throwable $e) {
            Log::error('[SidebarService] Failed to get sidebar content: ' . $e->getMessage());
            return self::getDefaultSidebar($pageKey);
        }
    }

    /**
     * 自动检测当前页面标识
     *
     * @param Request $request 请求对象
     *
     * @return string 页面标识
     */
    public static function detectPageKey(Request $request): string
    {
        try {
            $path = $request->path();
            $pageKey = str_replace(['/', '\\'], '_', trim($path, '/'));
            
            return empty($pageKey) ? 'home' : $pageKey;
        } catch (Throwable $e) {
            Log::error('[SidebarService] Failed to detect page key: ' . $e->getMessage());
            return 'home';
        }
    }

    /**
     * 根据页面标识获取侧边栏配置
     *
     * @param string $pageKey 页面标识
     *
     * @return array 侧边栏配置数组
     */
    public static function getSidebarByPage(string $pageKey): array
    {
        try {
            // 获取侧边栏配置
            $setting = Setting::where('key', self::SETTING_KEY)->first();
            
            $sidebarConfig = [];
            
            // 如果存在配置，尝试加载
            if ($setting && !empty($setting->value)) {
                $allConfigs = json_decode($setting->value, true);
                if (is_array($allConfigs)) {
                    $sidebarConfig = $allConfigs[$pageKey] ?? $allConfigs['default'] ?? [];
                }
            }
            
            // 如果没有配置或配置无效，返回默认配置
            if (empty($sidebarConfig) || !is_array($sidebarConfig)) {
                return self::getDefaultSidebar($pageKey);
            }
            
            // 添加页面标识
            $sidebarConfig['page_key'] = $pageKey;
            
            // 渲染侧边栏小工具
            return self::renderSidebarWidgets($sidebarConfig);
        } catch (Throwable $e) {
            Log::error('[SidebarService] Failed to get sidebar by page: ' . $e->getMessage());
            return self::getDefaultSidebar($pageKey);
        }
    }

    /**
     * 获取默认侧边栏配置
     *
     * @param string $pageKey 页面标识
     * @return array 默认侧边栏配置数组
     */
    protected static function getDefaultSidebar(string $pageKey = 'default'): array
    {
        return [
            'page_key' => $pageKey,
            'title' => '侧边栏',
            'widgets' => [
                [
                    'id' => 'about',
                    'title' => '关于博主',
                    'type' => 'about',
                    'content' => '欢迎访问我的博客，这里记录了我的技术分享和生活感悟。',
                    'enabled' => true
                ],
                [
                    'id' => 'recent_posts',
                    'title' => '最新文章',
                    'type' => 'recent_posts',
                    'enabled' => true,
                    'limit' => 5
                ],
                [
                    'id' => 'categories',
                    'title' => '文章分类',
                    'type' => 'categories',
                    'enabled' => true
                ],
                [
                    'id' => 'tags',
                    'title' => '标签云',
                    'type' => 'tags',
                    'enabled' => true
                ]
            ]
        ];
    }

    /**
     * 渲染侧边栏小工具
     *
     * @param array $sidebarConfig 侧边栏配置
     *
     * @return array 渲染后的侧边栏配置
     */
    protected static function renderSidebarWidgets(array $sidebarConfig): array
    {
        // 确保widgets数组存在
        if (!isset($sidebarConfig['widgets']) || !is_array($sidebarConfig['widgets'])) {
            $sidebarConfig['widgets'] = [];
        }
        
        // 渲染每个启用的小工具为HTML
        foreach ($sidebarConfig['widgets'] as $key => &$widget) {
            if (isset($widget['enabled']) && $widget['enabled'] === true) {
                try {
                    // 调用重构后的WidgetService渲染小工具为HTML
                    $widget['html'] = WidgetService::renderToHtml($widget);
                } catch (Throwable $e) {
                    Log::error('[SidebarService] Failed to render widget: ' . $e->getMessage());
                    $widget['html'] = '';
                }
            } else {
                $widget['html'] = '';
            }
        }
        
        return $sidebarConfig;
    }

    /**
     * 保存侧边栏配置
     *
     * @param string $pageKey 页面标识
     * @param array $sidebarConfig 侧边栏配置
     *
     * @return bool 是否保存成功
     * @throws Throwable
     */
    public static function saveSidebarConfig(string $pageKey, array $sidebarConfig): bool
    {
        try {
            // 验证页面标识
            if (empty($pageKey)) {
                throw new \InvalidArgumentException('Page key cannot be empty');
            }
            
            // 确保sidebarConfig是数组
            if (!is_array($sidebarConfig)) {
                throw new \InvalidArgumentException('Sidebar config must be an array');
            }
            
            // 清理不必要的字段
            unset($sidebarConfig['html']);
            unset($sidebarConfig['page_key']);
            
            // 获取现有配置
            $setting = Setting::where('key', self::SETTING_KEY)->first();
            
            // 初始化或获取所有配置
            $allConfigs = [];
            if ($setting && !empty($setting->value)) {
                $allConfigs = json_decode($setting->value, true);
                if (!is_array($allConfigs)) {
                    Log::warning("[SidebarService] Invalid existing config format, initializing new array");
                    $allConfigs = [];
                }
                Log::debug("[SidebarService] Loaded existing config for pages: " . implode(', ', array_keys($allConfigs)));
            }
            
            // 验证小工具配置
            if (isset($sidebarConfig['widgets']) && !is_array($sidebarConfig['widgets'])) {
                $sidebarConfig['widgets'] = [];
                Log::warning("[SidebarService] Invalid widgets format for page {$pageKey}");
            }
            
            // 更新特定页面的配置，保留其他页面配置
            $allConfigs[$pageKey] = $sidebarConfig;
            Log::debug("[SidebarService] Updating config for page: {$pageKey}");
            
            // 转换为JSON
            $jsonConfig = json_encode($allConfigs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($jsonConfig === false) {
                throw new \RuntimeException('Failed to encode sidebar config to JSON: ' . json_last_error_msg());
            }
            
            if ($setting) {
                // 更新现有配置
                $setting->value = $jsonConfig;
                $result = $setting->save();
                Log::debug("[SidebarService] Sidebar config saved for page: {$pageKey}, total pages: " . count($allConfigs));
                return $result;
            } else {
                // 创建新配置
                $setting = new Setting();
                $setting->key = self::SETTING_KEY;
                $setting->value = $jsonConfig;
                $result = $setting->save();
                Log::info("[SidebarService] Initial sidebar config created for page: {$pageKey}");
                return $result;
            }
        } catch (Throwable $e) {
            Log::error('[SidebarService] Failed to save sidebar config: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 获取所有已配置的侧边栏页面
     *
     * @return array 侧边栏页面列表
     */
    public static function getSidebarPages(): array
    {
        try {
            // 获取所有已配置的页面
            $setting = Setting::where('key', self::SETTING_KEY)->first();
            
            $pages = [];
            if ($setting && !empty($setting->value)) {
                $allConfigs = json_decode($setting->value, true);
                if (is_array($allConfigs)) {
                    foreach (array_keys($allConfigs) as $pageKey) {
                        $pages[] = [
                            'key' => $pageKey,
                            'name' => ucfirst(str_replace('_', ' ', $pageKey))
                        ];
                    }
                }
            }
            
            // 添加常用页面
            $commonPages = [
                ['key' => 'home', 'name' => '首页'],
                ['key' => 'category', 'name' => '分类页'],
                ['key' => 'tag', 'name' => '标签页'],
                ['key' => 'post', 'name' => '文章详情页'],
                ['key' => 'page', 'name' => '单页'],
                ['key' => 'search', 'name' => '搜索页'],
                ['key' => 'default', 'name' => '默认']
            ];
            
            // 合并并去重
            $allPages = array_merge($pages, $commonPages);
            $uniquePages = [];
            $seenKeys = [];
            
            foreach ($allPages as $page) {
                if (!in_array($page['key'], $seenKeys)) {
                    $uniquePages[] = [
                        'key' => $page['key'],
                        'name' => $page['name'] ?? ucfirst(str_replace('_', ' ', $page['key']))
                    ];
                    $seenKeys[] = $page['key'];
                }
            }
            
            return $uniquePages;
        } catch (Throwable $e) {
            Log::error('[SidebarService] Failed to get sidebar pages: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * 渲染侧边栏为HTML
     *
     * @param string $pageKey 页面标识
     * @param Request|null $request 请求对象（可选）
     *
     * @return array 侧边栏HTML内容
     */
    public static function renderSidebar(string $pageKey, ?Request $request = null): array
    {
        try {
            if ($request) {
                $sidebarConfig = self::getSidebarContent($request, $pageKey);
            } else {
                $sidebarConfig = self::getSidebarByPage($pageKey);
            }
            
            return $sidebarConfig;
        } catch (Throwable $e) {
            Log::error('[SidebarService] Failed to render sidebar: ' . $e->getMessage());
            return self::getDefaultSidebar($pageKey);
        }
    }

    /**
     * 获取所有可用的小工具类型
     *
     * @return array 小工具类型列表
     */
    public static function getAvailableWidgets(): array
    {
        try {
            return WidgetService::getRegisteredWidgets();
        } catch (Throwable $e) {
            Log::error('[SidebarService] Failed to get available widgets: ' . $e->getMessage());
            return [];
        }
    }
}