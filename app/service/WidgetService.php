<?php

namespace app\service;

use support\Log;
use Throwable;
use app\service\CacheService;
use app\service\WidgetConfigService;

/**
 * 小工具服务接口
 * 用于管理和注册博客小工具
 */
class WidgetService
{
    /**
     * 已注册的小工具类型
     * @var array
     */
    protected static $registeredWidgets = [];

    /**
     * 注册小工具类型
     *
     * @param string              $type           小工具类型标识
     * @param string              $name           小工具显示名称
     * @param string              $description    小工具描述
     * @param callable|array|null $renderCallback 渲染回调函数或可调用数组
     *
     * @return bool 是否注册成功
     */
    public static function registerWidget(
        string $type,
        string $name,
        string $description = '',
        callable|array|null $renderCallback = null
    ): bool {
        try {
            // 验证类型是否已存在
            if (isset(self::$registeredWidgets[$type])) {
                Log::warning("[WidgetService] 小工具类型 '{$type}' 已存在，将被覆盖");
            }

            // 注册小工具
            self::$registeredWidgets[$type] = [
                'type' => $type,
                'name' => $name,
                'description' => $description,
                'render_callback' => $renderCallback
            ];

            Log::info("[WidgetService] 小工具类型 '{$type}' 注册成功");
            return true;
        } catch (Throwable $e) {
            Log::error('[WidgetService] 注册小工具失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 注销小工具类型
     *
     * @param string $type 小工具类型标识
     * @return bool 是否注销成功
     */
    public static function unregisterWidget(string $type): bool {
        try {
            if (isset(self::$registeredWidgets[$type])) {
                unset(self::$registeredWidgets[$type]);
                Log::info("[WidgetService] 小工具类型 '{$type}' 注销成功");
                return true;
            }
            return false;
        } catch (Throwable $e) {
            Log::error('[WidgetService] 注销小工具失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取所有已注册的小工具类型
     *
     * @return array 小工具类型列表
     */
    public static function getRegisteredWidgets(): array {
        return array_values(self::$registeredWidgets);
    }

    /**
     * 检查小工具类型是否已注册
     *
     * @param string $type 小工具类型标识
     * @return bool 是否已注册
     */
    public static function isWidgetRegistered(string $type): bool {
        return isset(self::$registeredWidgets[$type]);
    }

    /**
     * 获取小工具信息
     *
     * @param string $type 小工具类型标识
     * @return array|null 小工具信息，如果不存在返回null
     */
    public static function getWidgetInfo(string $type): ?array {
        return self::$registeredWidgets[$type] ?? null;
    }

    /**
     * 渲染小工具
     *
     * @param array $widget 小工具配置
     * @param string $pageType 页面类型
     * @return array 渲染后的小工具数据
     */
    public static function renderWidget(array $widget, string $pageType = 'default'): array {
        try {
            $widgetType = $widget['type'] ?? '';
            
            if (empty($widgetType)) {
                Log::warning('[WidgetService] 小工具类型为空，无法渲染');
                return $widget;
            }

            // 从WidgetConfigService获取按页面类型的配置
            $configFromService = WidgetConfigService::getWidgetConfig($widgetType, $pageType);
            
            // 合并配置
            $widget = array_merge($widget, $configFromService);
            
            // 检查小工具是否启用
            if (isset($widget['enabled']) && !$widget['enabled']) {
                return [];
            }

            // 检查是否已注册
            if (!self::isWidgetRegistered($widgetType)) {
                Log::warning("[WidgetService] 小工具类型 '{$widgetType}' 未注册，使用默认渲染");
                return self::renderDefaultWidget($widget, $pageType);
            }

            // 获取小工具信息
            $widgetInfo = self::getWidgetInfo($widgetType);
            if (!$widgetInfo) {
                return self::renderDefaultWidget($widget, $pageType);
            }

            // 如果有渲染回调函数，则调用
            $renderCallback = $widgetInfo['render_callback'] ?? null;
            if (is_callable($renderCallback)) {
                try {
                    $renderedWidget = call_user_func($renderCallback, $widget, $pageType);
                    if (is_array($renderedWidget)) {
                        return $renderedWidget;
                    }
                } catch (Throwable $e) {
                    Log::error('[WidgetService] 小工具渲染回调执行失败: ' . $e->getMessage());
                }
            }

            // 如果没有回调函数或回调失败，使用默认渲染
            return self::renderDefaultWidget($widget, $pageType);
        } catch (Throwable $e) {
            Log::error('[WidgetService] 渲染小工具失败: ' . $e->getMessage());
            return $widget;
        }
    }

    /**
     * 默认小工具渲染
     *
     * @param array $widget 小工具配置
     * @param string $pageType 页面类型
     * @return array 渲染后的小工具数据
     */
    protected static function renderDefaultWidget(array $widget, string $pageType = 'default'): array {
        $widgetType = $widget['type'] ?? '';
        
        switch ($widgetType) {
            case 'about':
                // 关于博主小工具，可以根据页面类型调整内容
                $widget = self::renderAboutWidget($widget, $pageType);
                break;
            case 'categories':
                $widget = self::renderCategoriesWidget($widget, $pageType);
                break;
            case 'tags':
                $widget = self::renderTagsWidget($widget, $pageType);
                break;
            case 'archive':
                $widget = self::renderArchiveWidget($widget, $pageType);
                break;
            case 'recent_posts':
                $widget = self::renderRecentPostsWidget($widget, $pageType);
                break;
            case 'html':
                // HTML小工具，从Setting模型获取自定义代码
                $widget = self::renderHtmlWidget($widget, $pageType);
                break;
            case 'popular_posts':
                $widget = self::renderPopularPostsWidget($widget, $pageType);
                break;
            case 'random_posts':
                $widget = self::renderRandomPostsWidget($widget, $pageType);
                break;
            default:
                // 未知类型的小工具
                Log::warning("[WidgetService] 未知小工具类型: {$widgetType}");
                break;
        }
        
        return $widget;
    }

    /**
     * 渲染分类小工具
     *
     * @param array $widget 小工具配置
     * @param string $pageType 页面类型
     * @return array 渲染后的小工具数据
     */
    protected static function renderCategoriesWidget(array $widget, string $pageType = 'default'): array {
        try {
            // 从WidgetConfigService获取配置
            $config = WidgetConfigService::getWidgetConfig('categories', $pageType);
            
            // 合并配置
            $widget = array_merge($widget, $config);
            
            // 获取分类数据
            $query = \app\model\Category::withCount('posts')
                ->orderBy('posts_count', 'desc');
            
            // 应用数量限制
            if (isset($widget['params']['count']) && $widget['params']['count'] > 0) {
                $query->take($widget['params']['count']);
            }
            
            $widget['categories'] = $query->get(['id', 'name', 'slug', 'posts_count']);
        } catch (Throwable $e) {
            Log::error('[WidgetService] 渲染分类小工具失败: ' . $e->getMessage());
            $widget['categories'] = [];
        }
        
        return $widget;
    }

    /**
     * 渲染标签云小工具
     *
     * @param array $widget 小工具配置
     * @param string $pageType 页面类型
     * @return array 渲染后的小工具数据
     */
    protected static function renderTagsWidget(array $widget, string $pageType = 'default'): array {
        try {
            // 从WidgetConfigService获取配置
            $config = WidgetConfigService::getWidgetConfig('tags', $pageType);
            
            // 合并配置
            $widget = array_merge($widget, $config);
            
            // 获取标签云
            $query = \app\model\Tag::withCount('posts')
                ->orderBy('posts_count', 'desc');
            
            // 应用数量限制
            if (isset($widget['params']['count']) && $widget['params']['count'] > 0) {
                $query->take($widget['params']['count']);
            }
            
            $widget['tags'] = $query->get(['id', 'name', 'slug', 'posts_count']);
        } catch (Throwable $e) {
            Log::error('[WidgetService] 渲染标签云小工具失败: ' . $e->getMessage());
            $widget['tags'] = [];
        }
        
        return $widget;
    }

    /**
     * 渲染文章归档小工具
     *
     * @param array $widget 小工具配置
     * @param string $pageType 页面类型
     * @return array 渲染后的小工具数据
     */
    protected static function renderArchiveWidget(array $widget, string $pageType = 'default'): array {
        try {
            // 从WidgetConfigService获取配置
            $config = WidgetConfigService::getWidgetConfig('archive', $pageType);
            
            // 合并配置
            $widget = array_merge($widget, $config);
            
            // 获取文章归档（按年月分组）
            $query = \app\model\Post::selectRaw('DATE_FORMAT(created_at, "%Y-%m") as year_month, COUNT(*) as count, MIN(created_at) as min_date')
                ->where('status', 'published')
                ->groupBy('year_month')
                ->orderBy('year_month', 'desc');
            
            // 应用数量限制
            if (isset($widget['params']['count']) && $widget['params']['count'] > 0) {
                $query->take($widget['params']['count']);
            }
            
            $widget['archive'] = $query->get();
        } catch (Throwable $e) {
            Log::error('[WidgetService] 渲染文章归档小工具失败: ' . $e->getMessage());
            $widget['archive'] = [];
        }
        
        return $widget;
    }

    /**
     * 渲染最新文章小工具
     *
     * @param array $widget 小工具配置
     * @param string $pageType 页面类型
     * @return array 渲染后的小工具数据
     */
    protected static function renderRecentPostsWidget(array $widget, string $pageType = 'default'): array {
        try {
            // 从WidgetConfigService获取配置
            $config = WidgetConfigService::getWidgetConfig('recent_posts', $pageType);
            
            // 合并配置
            $widget = array_merge($widget, $config);
            
            // 获取最新文章
            $limit = $widget['params']['count'] ?? ($widget['limit'] ?? 5);
            $recentPosts = \app\model\Post::where('status', 'published')
                ->orderBy('created_at', 'desc')
                ->take($limit)
                ->get(['id', 'title', 'slug', 'created_at']);
            
            $widget['recent_posts'] = $recentPosts;
        } catch (Throwable $e) {
            Log::error('[WidgetService] 渲染最新文章小工具失败: ' . $e->getMessage());
            $widget['recent_posts'] = [];
        }
        
        return $widget;
    }

    /**
     * 渲染小工具为HTML
     *
     * @param array $widget 小工具配置
     * @param string $pageType 页面类型
     * @return string 渲染后的HTML内容
     */
    public static function renderWidgetToHtml(array $widget, string $pageType = 'default'): string
    {
        try {
            // 先渲染小工具数据
            $renderedWidget = self::renderWidget($widget, $pageType);
            
            // 根据小工具类型生成HTML
            $widgetType = $renderedWidget['type'] ?? '';
            $title = $renderedWidget['title'] ?? '';
            $content = '';
            
            switch ($widgetType) {
                case 'about':
                    $content = self::renderAboutWidgetHtml($renderedWidget);
                    break;
                case 'categories':
                    $content = self::renderCategoriesWidgetHtml($renderedWidget);
                    break;
                case 'tags':
                    $content = self::renderTagsWidgetHtml($renderedWidget);
                    break;
                case 'archive':
                    $content = self::renderArchiveWidgetHtml($renderedWidget);
                    break;
                case 'recent_posts':
                    $content = self::renderRecentPostsWidgetHtml($renderedWidget);
                    break;
                case 'html':
                    $content = self::renderHtmlWidgetHtml($renderedWidget);
                    break;
                case 'popular_posts':
                    $content = self::renderPopularPostsWidgetHtml($renderedWidget);
                    break;
                case 'random_posts':
                    $content = self::renderRandomPostsWidgetHtml($renderedWidget);
                    break;
                default:
                    $content = '<div class="text-gray-500 text-sm italic">未知的小工具类型: ' . htmlspecialchars($widgetType) . '</div>';
                    break;
            }
            
            // 构建完整的小工具HTML
            $html = '<div class="bg-white rounded-lg shadow-md p-6 mb-6 animate-fade-in-up">';
            if (!empty($title)) {
                $html .= '<h3 class="text-lg font-semibold text-gray-800 mb-4 border-b pb-2">' . htmlspecialchars($title) . '</h3>';
            }
            $html .= $content;
            $html .= '</div>';
            
            return $html;
        } catch (Throwable $e) {
            Log::error('[WidgetService] 渲染小工具HTML失败: ' . $e->getMessage());
            return '<div class="bg-white rounded-lg shadow-md p-6 mb-6 text-red-500">小工具渲染失败</div>';
        }
    }

    /**
     * 渲染关于博主小工具HTML
     */
    protected static function renderAboutWidgetHtml(array $widget): string
    {
        $content = $widget['content'] ?? '欢迎访问我的博客，这里记录了我的技术分享和生活感悟。';
        return '<div class="text-gray-600 leading-relaxed">' . nl2br(htmlspecialchars($content)) . '</div>';
    }

    /**
     * 渲染分类小工具HTML
     */
    protected static function renderCategoriesWidgetHtml(array $widget): string
    {
        $categories = $widget['categories'] ?? [];
        if (empty($categories)) {
            return '<div class="text-gray-500 text-sm italic">暂无分类</div>';
        }
        
        $html = '<div class="space-y-2">';
        foreach ($categories as $category) {
            $html .= '<a href="/category/' . htmlspecialchars($category['slug']) . '" class="flex justify-between items-center text-gray-700 hover:text-blue-600 transition-colors">';
            $html .= '<span>' . htmlspecialchars($category['name']) . '</span>';
            $html .= '<span class="text-sm text-gray-500 bg-gray-100 px-2 py-1 rounded-full">' . htmlspecialchars($category['posts_count']) . '</span>';
            $html .= '</a>';
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * 渲染标签云小工具HTML
     */
    protected static function renderTagsWidgetHtml(array $widget): string
    {
        $tags = $widget['tags'] ?? [];
        if (empty($tags)) {
            return '<div class="text-gray-500 text-sm italic">暂无标签</div>';
        }
        
        $html = '<div class="flex flex-wrap gap-2">';
        foreach ($tags as $tag) {
            $html .= '<a href="/tag/' . htmlspecialchars($tag['slug']) . '" class="text-sm bg-gray-100 text-gray-700 px-3 py-1 rounded-full hover:bg-blue-100 hover:text-blue-600 transition-colors">';
            $html .= htmlspecialchars($tag['name']) . ' (' . htmlspecialchars($tag['posts_count']) . ')';
            $html .= '</a>';
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * 渲染文章归档小工具HTML
     */
    protected static function renderArchiveWidgetHtml(array $widget): string
    {
        $archive = $widget['archive'] ?? [];
        if (empty($archive)) {
            return '<div class="text-gray-500 text-sm italic">暂无归档</div>';
        }
        
        $html = '<div class="space-y-2">';
        foreach ($archive as $item) {
            $html .= '<a href="/archive/' . htmlspecialchars($item['year_month']) . '" class="flex justify-between items-center text-gray-700 hover:text-blue-600 transition-colors">';
            $html .= '<span>' . htmlspecialchars($item['year_month']) . '</span>';
            $html .= '<span class="text-sm text-gray-500 bg-gray-100 px-2 py-1 rounded-full">' . htmlspecialchars($item['count']) . '</span>';
            $html .= '</a>';
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * 渲染最新文章小工具HTML
     */
    protected static function renderRecentPostsWidgetHtml(array $widget): string
    {
        $recentPosts = $widget['recent_posts'] ?? [];
        if (empty($recentPosts)) {
            return '<div class="text-gray-500 text-sm italic">暂无文章</div>';
        }
        
        $html = '<div class="space-y-3">';
        foreach ($recentPosts as $post) {
            $html .= '<a href="/post/' . htmlspecialchars($post['slug']) . '" class="block group">';
            $html .= '<div class="text-sm font-medium text-gray-800 group-hover:text-blue-600 transition-colors mb-1">';
            $html .= htmlspecialchars($post['title']);
            $html .= '</div>';
            $html .= '<div class="text-xs text-gray-500">';
            $html .= htmlspecialchars($post['created_at']->format('Y-m-d'));
            $html .= '</div>';
            $html .= '</a>';
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * 渲染HTML小工具HTML
     */
    protected static function renderHtmlWidgetHtml(array $widget): string
    {
        $content = $widget['content'] ?? '';
        return '<div class="prose prose-sm max-w-none">' . $content . '</div>';
    }
    
    /**
     * 渲染热门文章小工具HTML
     */
    protected static function renderPopularPostsWidgetHtml(array $widget): string
    {
        $popularPosts = $widget['popular_posts'] ?? [];
        if (empty($popularPosts)) {
            return '<div class="text-gray-500 text-sm italic">暂无热门文章</div>';
        }
        
        $html = '<div class="space-y-3">';
        foreach ($popularPosts as $post) {
            $html .= '<a href="/post/' . htmlspecialchars($post['slug']) . '" class="block group">';
            $html .= '<div class="text-sm font-medium text-gray-800 group-hover:text-blue-600 transition-colors mb-1">';
            $html .= htmlspecialchars($post['title']);
            $html .= '</div>';
            $html .= '<div class="text-xs text-gray-500">';
            $html .= '浏览量: ' . htmlspecialchars($post['views'] ?? 0);
            $html .= '</div>';
            $html .= '</a>';
        }
        $html .= '</div>';
        return $html;
    }
    
    /**
     * 渲染随机文章小工具HTML
     */
    protected static function renderRandomPostsWidgetHtml(array $widget): string
    {
        $randomPosts = $widget['random_posts'] ?? [];
        if (empty($randomPosts)) {
            return '<div class="text-gray-500 text-sm italic">暂无文章</div>';
        }
        
        $html = '<div class="space-y-3">';
        foreach ($randomPosts as $post) {
            $html .= '<a href="/post/' . htmlspecialchars($post['slug']) . '" class="block group">';
            $html .= '<div class="text-sm font-medium text-gray-800 group-hover:text-blue-600 transition-colors">';
            $html .= htmlspecialchars($post['title']);
            $html .= '</div>';
            $html .= '</a>';
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * 渲染小工具为HTML（带缓存）
     *
     * @param array $widget 小工具配置
     * @param int|null $cacheMinutes 缓存时间（分钟），null表示使用配置，0表示不缓存
     * @param string $pageType 页面类型
     * @return string 渲染后的HTML内容
     */
    public static function renderWidgetToHtmlWithCache(array $widget, ?int $cacheMinutes = null, string $pageType = 'default'): string
    {
        try {
            // 获取小工具类型
            $widgetType = $widget['type'] ?? '';
            
            // 如果未指定缓存时间，使用配置
            if ($cacheMinutes === null) {
                $cacheMinutes = self::getWidgetCacheTtl($widgetType);
            }
            
            // 生成缓存键（包含页面类型），创建副本避免修改原数组
            $widgetWithPageType = array_merge($widget, ['page_type' => $pageType]);
            $cacheKey = self::generateWidgetCacheKey($widgetWithPageType);
            
            // 尝试从缓存获取
            if ($cacheMinutes > 0) {
                $cachedHtml = CacheService::cache($cacheKey);
                if ($cachedHtml !== false) {
                    return $cachedHtml;
                }
            }
            
            // 渲染HTML内容
            $html = self::renderWidgetToHtml($widget, $pageType);
            
            // 缓存结果
            if ($cacheMinutes > 0) {
                CacheService::cache($cacheKey, $html, true, $cacheMinutes * 60);
            }
            
            return $html;
        } catch (Throwable $e) {
            Log::error('[WidgetService] 渲染小工具HTML缓存失败: ' . $e->getMessage());
            return self::renderWidgetToHtml($widget, $pageType);
        }
    }

    /**
     * 获取小工具缓存配置
     *
     * @param string $widgetType 小工具类型
     * @return int 缓存时间（分钟），0表示不缓存
     */
    public static function getWidgetCacheTtl(string $widgetType): int
    {
        try {
            // 加载小工具配置
            $config = config('widgets', []);
            $cacheConfig = $config['cache'] ?? [];
            
            // 检查全局缓存是否启用
            $globalEnabled = $cacheConfig['enabled'] ?? true;
            if (!$globalEnabled) {
                return 0;
            }
            
            // 检查特定小工具的缓存配置
            $widgetCacheConfig = $cacheConfig['widgets'][$widgetType] ?? null;
            if ($widgetCacheConfig) {
                $widgetEnabled = $widgetCacheConfig['enabled'] ?? true;
                if (!$widgetEnabled) {
                    return 0;
                }
                return $widgetCacheConfig['ttl'] ?? ($cacheConfig['default_ttl'] ?? 60);
            }
            
            // 使用全局默认缓存时间
            return $cacheConfig['default_ttl'] ?? 60;
        } catch (Throwable $e) {
            Log::error('[WidgetService] 获取小工具缓存配置失败: ' . $e->getMessage());
            return 60; // 默认缓存60分钟
        }
    }

    /**
     * 检查小工具是否启用缓存
     *
     * @param string $widgetType 小工具类型
     * @return bool 是否启用缓存
     */
    public static function isWidgetCacheEnabled(string $widgetType): bool
    {
        return self::getWidgetCacheTtl($widgetType) > 0;
    }

    /**
     * 生成小工具缓存键
     *
     * @param array $widget 小工具配置
     * @return string 缓存键
     */
    protected static function generateWidgetCacheKey(array $widget): string
    {
        $widgetType = $widget['type'] ?? '';
        $widgetId = $widget['id'] ?? '';
        $uniqueKey = md5(json_encode($widget));
        
        return "widget:{$widgetType}:{$widgetId}:{$uniqueKey}";
    }

    /**
     * 清除小工具缓存
     *
     * @param array $widget 小工具配置
     * @return bool 是否清除成功
     */
    public static function clearWidgetCache(array $widget): bool
    {
        try {
            $cacheKey = self::generateWidgetCacheKey($widget);
            return CacheService::clearCache($cacheKey);
        } catch (Throwable $e) {
            Log::error('[WidgetService] 清除小工具缓存失败: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 渲染关于博主小工具
     *
     * @param array $widget 小工具配置
     * @param string $pageType 页面类型
     * @return array 渲染后的小工具数据
     */
    protected static function renderAboutWidget(array $widget, string $pageType = 'default'): array {
        try {
            // 从WidgetConfigService获取配置
            $config = WidgetConfigService::getWidgetConfig('about', $pageType);
            
            // 合并配置
            $widget = array_merge($widget, $config);
            
            // 如果没有指定内容，使用默认值
            if (!isset($widget['content']) || empty($widget['content'])) {
                $widget['content'] = '欢迎访问我的博客，这里记录了我的技术分享和生活感悟。';
            }
        } catch (Throwable $e) {
            Log::error('[WidgetService] 渲染关于博主小工具失败: ' . $e->getMessage());
        }
        
        return $widget;
    }
    
    /**
     * 渲染HTML小工具
     * 从Setting模型获取自定义代码
     *
     * @param array $widget 小工具配置
     * @param string $pageType 页面类型
     * @return array 渲染后的小工具数据
     */
    protected static function renderHtmlWidget(array $widget, string $pageType = 'default'): array {
        try {
            // 从WidgetConfigService获取配置
            $config = WidgetConfigService::getWidgetConfig('html', $pageType);
            
            // 合并配置
            $widget = array_merge($widget, $config);
            
            // 如果没有指定内容，使用默认值
            if (!isset($widget['content']) || empty($widget['content'])) {
                $widget['content'] = '<!-- 自定义HTML内容 -->';
            }
        } catch (Throwable $e) {
            Log::error('[WidgetService] 渲染HTML小工具失败: ' . $e->getMessage());
        }
        
        return $widget;
    }
    
    /**
     * 渲染热门文章小工具
     *
     * @param array $widget 小工具配置
     * @param string $pageType 页面类型
     * @return array 渲染后的小工具数据
     */
    protected static function renderPopularPostsWidget(array $widget, string $pageType = 'default'): array {
        try {
            // 从WidgetConfigService获取配置
            $config = WidgetConfigService::getWidgetConfig('popular_posts', $pageType);
            
            // 合并配置
            $widget = array_merge($widget, $config);
            
            // 获取热门文章
            $limit = $widget['params']['count'] ?? 5;
            $popularPosts = \app\model\Post::where('status', 'published')
                ->orderBy('views', 'desc')
                ->take($limit)
                ->get(['id', 'title', 'slug', 'views']);
            
            $widget['popular_posts'] = $popularPosts;
        } catch (Throwable $e) {
            Log::error('[WidgetService] 渲染热门文章小工具失败: ' . $e->getMessage());
            $widget['popular_posts'] = [];
        }
        
        return $widget;
    }
    
    /**
     * 渲染随机文章小工具
     *
     * @param array $widget 小工具配置
     * @param string $pageType 页面类型
     * @return array 渲染后的小工具数据
     */
    protected static function renderRandomPostsWidget(array $widget, string $pageType = 'default'): array {
        try {
            // 从WidgetConfigService获取配置
            $config = WidgetConfigService::getWidgetConfig('random_posts', $pageType);
            
            // 合并配置
            $widget = array_merge($widget, $config);
            
            // 获取随机文章
            $limit = $widget['params']['count'] ?? 5;
            $randomPosts = \app\model\Post::where('status', 'published')
                ->inRandomOrder()
                ->take($limit)
                ->get(['id', 'title', 'slug']);
            
            $widget['random_posts'] = $randomPosts;
        } catch (Throwable $e) {
            Log::error('[WidgetService] 渲染随机文章小工具失败: ' . $e->getMessage());
            $widget['random_posts'] = [];
        }
        
        return $widget;
    }
}