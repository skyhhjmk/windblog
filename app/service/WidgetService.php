<?php

namespace app\service;

use app\model\Post;
use app\model\Category;
use app\model\Tag;
use app\model\Setting;
use support\Log;
use Throwable;

/**
 * 小工具服务 - 精简优化版
 * 统一管理所有小工具的注册、渲染和配置
 */
class WidgetService
{
    /**
     * 已注册的小工具类型
     * @var array
     */
    protected static $registeredWidgets = [];

    /**
     * 小工具渲染器映射
     * @var array
     */
    protected static $widgetRenderers = [];

    /**
     * 小工具类型默认标题映射
     * @var array
     */
    protected static $defaultTitles = [
        'about' => '关于博主',
        'categories' => '文章分类',
        'tags' => '标签云',
        'archive' => '文章归档',
        'recent_posts' => '最新文章',
        'popular_posts' => '热门文章',
        'random_posts' => '随机文章',
        'html' => '自定义HTML'
    ];

    /**
     * 注册小工具类型
     */
    public static function registerWidget(string $type, string $name, string $description = '', ?callable $renderer = null): bool
    {
        try {
            if (isset(self::$registeredWidgets[$type])) {
                Log::warning("[WidgetService] 小工具类型 '{$type}' 已存在，将被覆盖");
            }

            self::$registeredWidgets[$type] = [
                'type' => $type,
                'name' => $name,
                'description' => $description
            ];

            // 注册自定义渲染器
            if ($renderer && is_callable($renderer)) {
                self::$widgetRenderers[$type] = $renderer;
            }

            Log::debug("[WidgetService] 小工具类型 '{$type}' 注册成功");
            return true;
        } catch (Throwable $e) {
            Log::error('[WidgetService] 注册小工具失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取所有已注册的小工具类型
     */
    public static function getRegisteredWidgets(): array
    {
        return array_values(self::$registeredWidgets);
    }

    /**
     * 渲染小工具
     */
    public static function renderWidget(array $widget, string $pageType = 'default'): array
    {
        $widgetType = $widget['type'] ?? '';
        
        if (!isset(self::$registeredWidgets[$widgetType])) {
            Log::warning("[WidgetService] 未知小工具类型: {$widgetType}");
            return array_merge($widget, ['enabled' => false]);
        }

        try {
            $config = self::getWidgetConfig($widgetType, $pageType);
            
            if (isset($config['enabled']) && !$config['enabled']) {
                return ['enabled' => false];
            }

            $widget = array_merge($widget, $config);
            return self::renderSingleWidget($widget, $pageType);
        } catch (Throwable $e) {
            Log::error("[WidgetService] 渲染小工具失败: {$widgetType} - " . $e->getMessage());
            return array_merge($widget, ['enabled' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * 渲染单个小工具
     */
    protected static function renderSingleWidget(array $widget, string $pageType): array
    {
        $widgetType = $widget['type'] ?? '';
        
        // 优先使用自定义渲染器
        if (isset(self::$widgetRenderers[$widgetType]) && is_callable(self::$widgetRenderers[$widgetType])) {
            try {
                return call_user_func(self::$widgetRenderers[$widgetType], $widget, $pageType);
            } catch (Throwable $e) {
                Log::error("[WidgetService] 自定义渲染器执行失败: {$widgetType} - " . $e->getMessage());
            }
        }
        
        // 使用内置渲染器
        $renderMethod = 'render' . str_replace('_', '', ucwords($widgetType, '_')) . 'Widget';
        
        if (method_exists(__CLASS__, $renderMethod)) {
            return self::$renderMethod($widget);
        }
        
        Log::warning("[WidgetService] 未知小工具类型: {$widgetType}");
        return array_merge($widget, ['enabled' => false]);
    }

    /**
     * 获取小工具配置
     */
    public static function getWidgetConfig(string $widgetType, string $pageType = 'default'): array
    {
        $defaultConfig = [
            'enabled' => true,
            'title' => self::$defaultTitles[$widgetType] ?? ucfirst($widgetType),
            'params' => ['count' => 5]
        ];

        try {
            $settingKey = "widget_{$widgetType}_{$pageType}";
            $setting = Setting::where('key', $settingKey)->first();

            if (!$setting && $pageType !== 'default') {
                $setting = Setting::where('key', "widget_{$widgetType}_default")->first();
            }

            if ($setting) {
                $config = json_decode($setting->value, true);
                return array_merge($defaultConfig, $config);
            }
        } catch (Throwable $e) {
            Log::error("[WidgetService] 获取小工具配置失败: {$widgetType} - " . $e->getMessage());
        }

        return $defaultConfig;
    }

    /**
     * 渲染关于博主小工具
     */
    protected static function renderAboutWidget(array $widget): array
    {
        if (!isset($widget['content']) || empty($widget['content'])) {
            $widget['content'] = '欢迎访问我的博客，这里记录了我的技术分享和生活感悟。';
        }
        return $widget;
    }

    /**
     * 渲染分类小工具
     */
    protected static function renderCategoriesWidget(array $widget): array
    {
        try {
            $count = $widget['params']['count'] ?? 5;
            $query = Category::withCount('posts')->orderBy('posts_count', 'desc');
            
            if ($count > 0) {
                $query->take($count);
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
     */
    protected static function renderTagsWidget(array $widget): array
    {
        try {
            $count = $widget['params']['count'] ?? 5;
            $query = Tag::withCount('posts')->orderBy('posts_count', 'desc');
            
            if ($count > 0) {
                $query->take($count);
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
     */
    protected static function renderArchiveWidget(array $widget): array
    {
        try {
            $count = $widget['params']['count'] ?? 5;
            $query = Post::selectRaw('DATE_FORMAT(created_at, "%Y-%m") as year_month, COUNT(*) as count')
                ->where('status', 'published')
                ->groupBy('year_month')
                ->orderBy('year_month', 'desc');
            
            if ($count > 0) {
                $query->take($count);
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
     */
    protected static function renderRecentPostsWidget(array $widget): array
    {
        try {
            $limit = $widget['params']['count'] ?? 5;
            $widget['recent_posts'] = Post::where('status', 'published')
                ->orderBy('created_at', 'desc')
                ->take($limit)
                ->get(['id', 'title', 'slug', 'created_at']);
        } catch (Throwable $e) {
            Log::error('[WidgetService] 渲染最新文章小工具失败: ' . $e->getMessage());
            $widget['recent_posts'] = [];
        }
        
        return $widget;
    }

    /**
     * 渲染HTML小工具
     */
    protected static function renderHtmlWidget(array $widget): array
    {
        $widgetId = $widget['widget_id'] ?? 'default';
        
        if (empty($widget['content'])) {
            try {
                $settingKey = "custom_widget_html_{$widgetId}";
                $setting = Setting::where('key', $settingKey)->first();
                
                if ($setting) {
                    $widget['content'] = $setting->value;
                } else {
                    $widget['content'] = '<div class="widget-content p-4 bg-gray-50 rounded">
                       <p class="text-center text-gray-500">这是一个自定义HTML小工具。</p>
                     </div>';
                }
            } catch (Throwable $e) {
                Log::error('[WidgetService] 获取自定义HTML内容失败: ' . $e->getMessage());
                $widget['content'] = '<div class="text-red-500">内容加载失败</div>';
            }
        }
        
        return $widget;
    }

    /**
     * 渲染热门文章小工具
     */
    protected static function renderPopularPostsWidget(array $widget): array
    {
        try {
            $limit = $widget['params']['count'] ?? 5;
            $widget['popular_posts'] = Post::where('status', 'published')
                ->orderBy('views', 'desc')
                ->take($limit)
                ->get(['id', 'title', 'slug', 'created_at', 'views']);
        } catch (Throwable $e) {
            Log::error('[WidgetService] 渲染热门文章小工具失败: ' . $e->getMessage());
            $widget['popular_posts'] = [];
        }
        
        return $widget;
    }

    /**
     * 渲染随机文章小工具
     */
    protected static function renderRandomPostsWidget(array $widget): array
    {
        try {
            $limit = $widget['params']['count'] ?? 5;
            $widget['random_posts'] = Post::where('status', 'published')
                ->inRandomOrder()
                ->take($limit)
                ->get(['id', 'title', 'slug', 'created_at']);
        } catch (Throwable $e) {
            Log::error('[WidgetService] 渲染随机文章小工具失败: ' . $e->getMessage());
            $widget['random_posts'] = [];
        }
        
        return $widget;
    }

    /**
     * 批量渲染小工具
     */
    public static function renderBatchWidgets(array $widgets, string $pageType = 'default'): array
    {
        $results = [];
        foreach ($widgets as $widget) {
            $results[] = self::renderWidget($widget, $pageType);
        }
        return $results;
    }

    /**
     * 注册默认小工具
     */
    public static function registerDefaultWidgets(): void
    {
        $widgets = [
            'about' => ['关于博主', '显示关于博主的信息', [__CLASS__, 'renderAboutWidget']],
            'categories' => ['文章分类', '显示博客文章分类列表', [__CLASS__, 'renderCategoriesWidget']],
            'tags' => ['标签云', '显示博客文章标签云', [__CLASS__, 'renderTagsWidget']],
            'archive' => ['文章归档', '按月份显示文章归档列表', [__CLASS__, 'renderArchiveWidget']],
            'recent_posts' => ['最新文章', '显示最新发布的文章列表', [__CLASS__, 'renderRecentPostsWidget']],
            'html' => ['自定义HTML', '自定义HTML内容', [__CLASS__, 'renderHtmlWidget']],
            'popular_posts' => ['热门文章', '显示最受欢迎的文章列表', [__CLASS__, 'renderPopularPostsWidget']],
            'random_posts' => ['随机文章', '随机显示博客中的文章', [__CLASS__, 'renderRandomPostsWidget']]
        ];
        
        foreach ($widgets as $type => $info) {
            self::registerWidget($type, $info[0], $info[1], $info[2]);
        }
        
        Log::info('[WidgetService] 默认小工具注册完成');
    }
    
    /**
     * 获取小工具渲染器
     */
    public static function getWidgetRenderer(string $widgetType): ?callable
    {
        return self::$widgetRenderers[$widgetType] ?? null;
    }
    
    /**
     * 设置小工具渲染器
     */
    public static function setWidgetRenderer(string $widgetType, callable $renderer): bool
    {
        if (!isset(self::$registeredWidgets[$widgetType])) {
            Log::warning("[WidgetService] 无法为未注册的小工具类型 '{$widgetType}' 设置渲染器");
            return false;
        }
        
        self::$widgetRenderers[$widgetType] = $renderer;
        Log::debug("[WidgetService] 小工具类型 '{$widgetType}' 渲染器设置成功");
        return true;
    }
    
    /**
     * 批量注册小工具（插件使用）
     */
    public static function registerBatchWidgets(array $widgets): void
    {
        foreach ($widgets as $widgetType => $config) {
            $name = $config['name'] ?? ucfirst($widgetType);
            $description = $config['description'] ?? '';
            $renderer = $config['renderer'] ?? null;
            
            self::registerWidget($widgetType, $name, $description, $renderer);
        }
    }

    /**
     * 生成小工具HTML
     */
    public static function renderToHtml(array $widget): string
    {
        try {
            if (isset($widget['enabled']) && !$widget['enabled']) {
                return '';
            }
            
            $widgetType = $widget['type'] ?? '';
            $title = $widget['title'] ?? '';
            
            // 优先使用自定义HTML渲染器
            if (isset(self::$widgetRenderers[$widgetType]) && is_callable(self::$widgetRenderers[$widgetType])) {
                try {
                    $content = call_user_func(self::$widgetRenderers[$widgetType], $widget);
                    return self::wrapWidgetHtml($title, $content);
                } catch (Throwable $e) {
                    Log::error("[WidgetService] 自定义HTML渲染器执行失败: {$widgetType} - " . $e->getMessage());
                }
            }
            
            // 使用内置HTML渲染器
            $htmlMethod = 'render' . str_replace('_', '', ucwords($widgetType, '_')) . 'Html';
            
            if (method_exists(__CLASS__, $htmlMethod)) {
                $content = self::$htmlMethod($widget);
                return self::wrapWidgetHtml($title, $content);
            }
            
            return '<div class="text-gray-500 text-sm italic">未知的小工具类型</div>';
                
        } catch (Throwable $e) {
            Log::error('[WidgetService] 渲染小工具HTML失败: ' . $e->getMessage());
            return '<div class="bg-white rounded-lg shadow-md p-6 mb-6 text-red-500">小工具渲染失败</div>';
        }
    }
    
    /**
     * 包装小工具HTML内容
     */
    protected static function wrapWidgetHtml(string $title, string $content): string
    {
        return '<div class="bg-white rounded-lg shadow-md p-6 mb-6">'
            . (!empty($title) ? '<h3 class="text-lg font-semibold text-gray-800 mb-4 border-b pb-2">' . htmlspecialchars($title) . '</h3>' : '')
            . $content
            . '</div>';
    }

    /**
     * HTML渲染方法
     */
    protected static function renderAboutHtml(array $widget): string
    {
        $content = $widget['content'] ?? '欢迎访问我的博客，这里记录了我的技术分享和生活感悟。';
        return '<div class="text-gray-600 leading-relaxed">' . nl2br(htmlspecialchars($content)) . '</div>';
    }

    protected static function renderCategoriesHtml(array $widget): string
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

    protected static function renderTagsHtml(array $widget): string
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

    protected static function renderArchiveHtml(array $widget): string
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

    protected static function renderRecentPostsHtml(array $widget): string
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

    protected static function renderHtmlHtml(array $widget): string
    {
        $content = $widget['content'] ?? '';
        return '<div class="prose prose-sm max-w-none">' . $content . '</div>';
    }

    protected static function renderPopularPostsHtml(array $widget): string
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

    protected static function renderRandomPostsHtml(array $widget): string
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
     * 渲染小工具为HTML并缓存
     *
     * @param array $widget 小工具配置
     * @param int|null $cacheMinutes 缓存分钟数，null表示使用默认缓存
     * @param string $pageType 页面类型
     * @return string 渲染后的HTML
     */
    public static function renderWidgetToHtmlWithCache(array $widget, ?int $cacheMinutes = null, string $pageType = 'default'): string
    {
        try {
            $widgetType = $widget['type'] ?? '';
            $widgetId = $widget['id'] ?? md5(serialize($widget));
            
            // 生成缓存键
            $cacheKey = "widget_{$widgetType}_{$widgetId}_{$pageType}";
            
            // 如果禁用缓存，直接渲染
            if ($cacheMinutes === 0) {
                return self::renderToHtml($widget);
            }
            
            // 使用默认缓存时间（60分钟）
            $cacheMinutes = $cacheMinutes ?? 60;
            
            // 尝试从缓存获取
            $cachedHtml = cache()->get($cacheKey);
            if ($cachedHtml !== null) {
                return $cachedHtml;
            }
            
            // 渲染HTML
            $html = self::renderToHtml($widget);
            
            // 缓存结果
            cache()->set($cacheKey, $html, $cacheMinutes * 60);
            
            return $html;
            
        } catch (Throwable $e) {
            Log::error('[WidgetService] 渲染小工具HTML缓存失败: ' . $e->getMessage());
            return '<div class="bg-white rounded-lg shadow-md p-6 mb-6 text-red-500">小工具渲染失败</div>';
        }
    }
}