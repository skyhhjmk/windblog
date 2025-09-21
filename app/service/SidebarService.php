<?php

namespace app\service;

use app\model\Setting;
use support\annotation\DisableDefaultRoute;
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
     * @throws Throwable
     */
    public static function getSidebarContent(Request $request, string $pageKey = ''): array
    {
        if (empty($pageKey)) {
            $pageKey = self::detectPageKey($request);
        }

        return self::getSidebarByPage($pageKey);
    }
    
    /**
     * 获取页面类型
     * 将传入的页面标识符转换为标准页面类型
     *
     * @param string $pageKey 页面标识符
     * @return string 标准页面类型
     */
    public static function getPageType(string $pageKey): string
    {
        // 从数据库获取页面类型映射
        $pageTypes = blog_config('page_types', []);
        
        // 检查页面类型是否有效
        if (isset($pageTypes[$pageKey])) {
            return $pageKey;
        }
        
        // 对于其他页面，尝试从路径中提取类型
        $pathParts = explode('_', $pageKey);
        if (!empty($pathParts) && isset($pageTypes[$pathParts[0]])) {
            return $pathParts[0];
        }
        
        // 返回默认类型
        return blog_config('default_page_type', 'default');
    }
    
    /**
     * 获取所有支持的页面类型
     *
     * @return array 所有页面类型的数组
     */
    public static function getAllPageTypes(): array
    {
        return blog_config('page_types', []);
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
            // 获取当前请求的路由信息
            $path = $request->path();
            
            // 简单的路径匹配，将路径转换为有效的页面标识
            $pageKey = str_replace(['/', '\\'], '_', trim($path, '/'));
            
            // 如果是首页，使用home作为标识
            if (empty($pageKey)) {
                $pageKey = 'home';
            }
            
            return $pageKey;
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
     * @throws Throwable
     */
    public static function getSidebarByPage(string $pageKey): array
    {
        try {
            // 获取侧边栏配置
            $setting = Setting::where('key', self::SETTING_KEY)->first();
            
            // 如果没有配置，返回默认的侧边栏结构
            if (!$setting) {
                $defaultSidebar = self::getDefaultSidebar();
                $defaultSidebar['page_key'] = $pageKey;
                return $defaultSidebar;
            }
            
            // 解析配置JSON
            $allConfigs = json_decode($setting->value, true);
            
            // 验证配置是否有效
            if (!is_array($allConfigs)) {
                $defaultSidebar = self::getDefaultSidebar();
                $defaultSidebar['page_key'] = $pageKey;
                return $defaultSidebar;
            }
            
            // 获取特定页面的配置，如果不存在则使用默认配置
            $sidebarConfig = $allConfigs[$pageKey] ?? $allConfigs['default'] ?? [];
            
            // 如果没有获取到该页面的配置或配置为空，返回默认配置
            if (empty($sidebarConfig) || !is_array($sidebarConfig)) {
                $defaultSidebar = self::getDefaultSidebar();
                $defaultSidebar['page_key'] = $pageKey;
                return $defaultSidebar;
            }
            
            // 添加页面标识
            $sidebarConfig['page_key'] = $pageKey;
            
            // 渲染侧边栏小工具
            return self::renderSidebarWidgets($sidebarConfig);
        } catch (Throwable $e) {
            Log::error('[SidebarService] Failed to get sidebar by page: ' . $e->getMessage());
            $defaultSidebar = self::getDefaultSidebar();
            $defaultSidebar['page_key'] = $pageKey;
            return $defaultSidebar;
        }
    }

    /**
     * 获取默认侧边栏配置
     *
     * @return array 默认侧边栏配置数组
     */
    protected static function getDefaultSidebar(): array
    {
        return [
            'title' => '侧边栏',
            'widgets' => [
                [
                    'id' => 'about',
                    'title' => '关于博主',
                    'type' => 'about',
                    'content' => '欢迎访问我的博客，这里记录了我的技术分享和生活感悟。',
                    'enabled' => true,
                    'params' => []
                ],
                [
                    'id' => 'recent_posts',
                    'title' => '最新文章',
                    'type' => 'recent_posts',
                    'enabled' => true,
                    'params' => [
                        'count' => 5
                    ]
                ],
                [
                    'id' => 'categories',
                    'title' => '文章分类',
                    'type' => 'categories',
                    'enabled' => true,
                    'params' => [
                        'count' => 10
                    ]
                ],
                [
                    'id' => 'tags',
                    'title' => '标签云',
                    'type' => 'tags',
                    'enabled' => true,
                    'params' => [
                        'count' => 20
                    ]
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
        
        // 获取页面类型
        $pageKey = $sidebarConfig['page_key'] ?? 'default';
        $pageType = self::getPageType($pageKey);
        
        // 渲染每个启用的小工具为HTML（带缓存，默认缓存60分钟）
        foreach ($sidebarConfig['widgets'] as $key => &$widget) {
            if (isset($widget['enabled']) && $widget['enabled'] === true) {
                try {
                    // 调用WidgetService渲染小工具，传入正确的参数顺序：widget、cacheMinutes（null表示使用默认缓存时间）、pageType
                    $widget['html'] = \app\service\WidgetService::renderWidgetToHtmlWithCache($widget, null, $pageType);
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
     * 渲染单个小工具
     *
     * @param array $widget 小工具配置
     * @param string $pageType 页面类型
     *
     * @return array 渲染后的小工具数据
     */
    protected static function renderWidget(array $widget, string $pageType = 'default'): array
    {
        try {
            // 直接调用WidgetService的renderWidget方法，该方法已处理所有类型的小工具
            return \app\service\WidgetService::renderWidget($widget, $pageType);
        } catch (Throwable $e) {
            Log::error('[SidebarService] Failed to render widget: ' . $e->getMessage());
            // 出错时返回原始widget
            return $widget;
        }
    }

    /**
     * 渲染分类小工具
     *
     * @param array $widget 小工具配置
     *
     * @return array 渲染后的小工具数据
     */
    protected static function renderCategoriesWidget(array $widget): array
    {
        try {
            // 获取分类数据
            $categories = \app\model\Category::withCount('posts')
                ->orderBy('posts_count', 'desc')
                ->get(['id', 'name', 'slug', 'posts_count']);
            
            $widget['categories'] = $categories;
        } catch (Throwable $e) {
            Log::error('[SidebarService] Failed to render categories widget: ' . $e->getMessage());
            $widget['categories'] = [];
        }
        
        return $widget;
    }

    /**
     * 渲染标签云小工具
     *
     * @param array $widget 小工具配置
     *
     * @return array 渲染后的小工具数据
     */
    protected static function renderTagsWidget(array $widget): array
    {
        try {
            // 获取标签云
            $tags = \app\model\Tag::withCount('posts')
                ->orderBy('posts_count', 'desc')
                ->get(['id', 'name', 'slug', 'posts_count']);
            
            $widget['tags'] = $tags;
        } catch (Throwable $e) {
            Log::error('[SidebarService] Failed to render tags widget: ' . $e->getMessage());
            $widget['tags'] = [];
        }
        
        return $widget;
    }

    /**
     * 渲染文章归档小工具
     *
     * @param array $widget 小工具配置
     *
     * @return array 渲染后的小工具数据
     */
    protected static function renderArchiveWidget(array $widget): array
    {
        try {
            // 获取文章归档（按年月分组）
            $archive = \app\model\Post::selectRaw('DATE_FORMAT(created_at, "%Y-%m") as year_month, COUNT(*) as count, MIN(created_at) as min_date')
                ->where('status', 'published')
                ->groupBy('year_month')
                ->orderBy('year_month', 'desc')
                ->get();
            
            $widget['archive'] = $archive;
        } catch (Throwable $e) {
            Log::error('[SidebarService] Failed to render archive widget: ' . $e->getMessage());
            $widget['archive'] = [];
        }
        
        return $widget;
    }

    /**
     * 渲染最新文章小工具
     *
     * @param array $widget 小工具配置
     *
     * @return array 渲染后的小工具数据
     */
    protected static function renderRecentPostsWidget(array $widget): array
    {
        try {
            // 获取最新文章
            $limit = $widget['limit'] ?? 5;
            $recentPosts = \app\model\Post::where('status', 'published')
                ->orderBy('created_at', 'desc')
                ->take($limit)
                ->get(['id', 'title', 'slug', 'created_at']);
            
            $widget['recent_posts'] = $recentPosts;
        } catch (Throwable $e) {
            Log::error('[SidebarService] Failed to render recent posts widget: ' . $e->getMessage());
            $widget['recent_posts'] = [];
        }
        
        return $widget;
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
            // 验证页面类型
            $pageTypes = blog_config('page_types', []);
            if (!isset($pageTypes[$pageKey])) {
                $pageKey = blog_config('default_page_type', 'default');
            }
            
            // 获取现有配置
            $setting = Setting::where('key', self::SETTING_KEY)->first();
            
            // 初始化或获取所有配置
            $allConfigs = [];
            if ($setting && !empty($setting->value)) {
                $allConfigs = json_decode($setting->value, true);
                if (!is_array($allConfigs)) {
                    $allConfigs = [];
                }
            }
            
            // 更新特定页面的配置
            $allConfigs[$pageKey] = $sidebarConfig;
            
            // 转换为JSON
            $jsonConfig = json_encode($allConfigs, JSON_UNESCAPED_UNICODE);
            
            if ($setting) {
                // 更新现有配置
                $setting->value = $jsonConfig;
                return $setting->save();
            } else {
                // 创建新配置
                $setting = new Setting();
                $setting->key = self::SETTING_KEY;
                $setting->value = $jsonConfig;
                return $setting->save();
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
                ['key' => 'home', 'name' => '首页'],  // 修改为name而不是display_name
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
                    // 确保使用name字段
                    $pageData = [
                        'key' => $page['key'],
                        'name' => $page['name'] ?? $page['display_name'] ?? ucfirst(str_replace('_', ' ', $page['key']))
                    ];
                    $uniquePages[] = $pageData;
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
     * @throws Throwable
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
            $defaultSidebar = self::getDefaultSidebar();
            $defaultSidebar['page_key'] = $pageKey;
            return $defaultSidebar;
        }
    }

    /**
     * 获取所有可用的小工具类型
     *
     * @return array 小工具类型列表
     */
    public static function getAvailableWidgets(): array
    {
        return \app\service\WidgetService::getRegisteredWidgets();
    }
}