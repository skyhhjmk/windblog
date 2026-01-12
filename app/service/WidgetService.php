<?php

namespace app\service;

use app\model\Category;
use app\model\Post;
use app\model\Tag;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use support\Log;
use Throwable;

/**
 * 小工具服务
 * 统一管理所有小工具的注册、渲染和配置
 */
class WidgetService
{
    /**
     * 已注册的小工具类型
     *
     * @var array
     */
    protected static $registeredWidgets = [];

    /**
     * 小工具渲染器映射
     *
     * @var array
     */
    protected static $widgetRenderers = [];

    /**
     * 小工具HTML渲染器映射
     *
     * @var array
     */
    protected static $widgetHtmlRenderers = [];

    /**
     * 小工具类型默认标题映射
     *
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
        'html' => '自定义HTML',
        'ads' => '广告',
    ];

    /**
     * 注册小工具类型
     *
     * @param string        $type        小工具类型
     * @param string        $name        小工具名称
     * @param string        $description 小工具描述
     * @param callable|null $renderer    数据渲染器
     * @param callable|null $htmlRenderer HTML渲染器
     *
     * @return bool 是否注册成功
     */
    public static function registerWidget(string $type, string $name, string $description = '', ?callable $renderer = null, ?callable $htmlRenderer = null): bool
    {
        try {
            if (isset(self::$registeredWidgets[$type])) {
                Log::warning("[WidgetService] 小工具类型 '{$type}' 已存在，将被覆盖");
            }

            self::$registeredWidgets[$type] = [
                'type' => $type,
                'name' => $name,
                'description' => $description,
            ];

            // 注册自定义渲染器
            if ($renderer && is_callable($renderer)) {
                self::$widgetRenderers[$type] = $renderer;
            }

            // 注册自定义HTML渲染器
            if ($htmlRenderer && is_callable($htmlRenderer)) {
                self::$widgetHtmlRenderers[$type] = $htmlRenderer;
            }

            //            Log::debug("[WidgetService] 小工具类型 '{$type}' 注册成功");
            return true;
        } catch (Throwable $e) {
            Log::error('[WidgetService] 注册小工具失败: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * 获取所有已注册的小工具类型
     *
     * @return array 小工具类型列表
     */
    public static function getRegisteredWidgets(): array
    {
        return array_values(self::$registeredWidgets);
    }

    /**
     * 渲染小工具数据
     *
     * @param array $widget 小工具配置
     * @param string $pageType 页面类型
     *
     * @return array 渲染后的小工具数据
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

            $widget = array_merge($config, $widget);
            $widget['params'] = array_merge($config['params'] ?? [], $widget['params'] ?? []);

            // 优先使用自定义渲染器
            if (isset(self::$widgetRenderers[$widgetType]) && is_callable(self::$widgetRenderers[$widgetType])) {
                try {
                    return call_user_func(self::$widgetRenderers[$widgetType], $widget, $pageType);
                } catch (Throwable $e) {
                    Log::error("[WidgetService] 自定义渲染器执行失败: {$widgetType} - " . $e->getMessage());
                }
            }

            // 使用内置渲染器
            $renderMethod = 'render' . str_replace('_', '', ucwords($widgetType, '_'));

            if (method_exists(__CLASS__, $renderMethod)) {
                return self::{$renderMethod}($widget);
            }

            Log::warning("[WidgetService] 未知小工具类型: {$widgetType}");

            return array_merge($widget, ['enabled' => false]);
        } catch (Throwable $e) {
            Log::error("[WidgetService] 渲染小工具失败: {$widgetType} - " . $e->getMessage());

            return array_merge($widget, ['enabled' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * 获取小工具配置
     *
     * @param string $widgetType 小工具类型
     * @param string $pageType 页面类型
     *
     * @return array 小工具配置
     */
    public static function getWidgetConfig(string $widgetType, string $pageType = 'default'): array
    {
        // 按类型设置默认参数，避免全局默认影响其它小工具
        $defaultParamsMap = [
            'tags' => ['count' => 50, 'visible' => 30],
            'categories' => ['count' => 5],
            'recent_posts' => ['count' => 5],
            'popular_posts' => ['count' => 5],
            'random_posts' => ['count' => 5],
            'archive' => ['count' => 5],
            'about' => [],
            'html' => [],
        ];
        $defaultConfig = [
            'enabled' => true,
            'title' => self::$defaultTitles[$widgetType] ?? ucfirst($widgetType),
            'params' => $defaultParamsMap[$widgetType] ?? ['count' => 5],
        ];

        try {
            $settingKey = "widget_{$widgetType}_{$pageType}";
            $config = blog_config($settingKey, []);

            if (empty($config) && $pageType !== 'default') {
                $config = blog_config("widget_{$widgetType}_default", []);
            }

            if (!empty($config)) {
                return array_merge($defaultConfig, $config);
            }
        } catch (Throwable $e) {
            Log::error("[WidgetService] 获取小工具配置失败: {$widgetType} - " . $e->getMessage());
        }

        return $defaultConfig;
    }

    /**
     * 渲染小工具为HTML
     *
     * @param array $widget 小工具数据
     *
     * @return string 渲染后的HTML
     */
    public static function renderToHtml(array $widget): string
    {
        try {
            if (isset($widget['enabled']) && !$widget['enabled']) {
                return '';
            }

            $widgetType = $widget['type'] ?? '';

            // 获取渲染后的小工具数据
            $widgetData = self::renderWidget($widget);

            // 优先使用自定义HTML渲染器
            if (isset(self::$widgetHtmlRenderers[$widgetType]) && is_callable(self::$widgetHtmlRenderers[$widgetType])) {
                try {
                    return call_user_func(self::$widgetHtmlRenderers[$widgetType], $widgetData);
                } catch (Throwable $e) {
                    Log::error("[WidgetService] 自定义HTML渲染器执行失败: {$widgetType} - " . $e->getMessage());
                }
            }

            // 使用Twig模板渲染小工具
            return self::renderWidgetWithTwig($widgetData);

        } catch (Throwable $e) {
            Log::error('[WidgetService] 渲染小工具HTML失败: ' . $e->getMessage());

            return '<div class="bg-white rounded-lg shadow-md p-6 mb-6 text-red-500">小工具渲染失败</div>';
        }
    }

    /**
     * 使用Twig模板渲染小工具
     *
     * @param array $widget 小工具数据
     *
     * @return string 渲染后的HTML
     */
    protected static function renderWidgetWithTwig(array $widget): string
    {
        try {
            $widgetType = $widget['type'] ?? '';
            // 不要包含 .html.twig 后缀，view() 函数会自动添加
            $templatePath = "widgets/{$widgetType}";

            // 检查模板是否存在（需要完整路径检查）
            $viewPath = app_path() . '/view/default/' . $templatePath . '.html.twig';
            if (!file_exists($viewPath)) {
                Log::warning("[WidgetService] 小工具模板不存在: {$viewPath}，使用默认HTML渲染器");
                // 回退到原有的HTML渲染方法
                $htmlMethod = 'render' . str_replace('_', '', ucwords($widgetType, '_')) . 'Html';
                if (method_exists(__CLASS__, $htmlMethod)) {
                    $content = self::{$htmlMethod}($widget);

                    return self::wrapWidgetHtml($widget['title'] ?? '', $content);
                }

                return '<div class="text-gray-500 text-sm italic">未知的小工具类型</div>';
            }

            // 记录渲染开始时间
            $renderStartTime = microtime(true);

            // 使用Twig渲染模板
            $html = view($templatePath, $widget)->rawBody();

            // 计算渲染耗时
            $renderTime = round((microtime(true) - $renderStartTime) * 1000, 2);

            // 记录小工具渲染时间日志
            Log::debug("[WidgetService] 小工具Twig渲染耗时: {$renderTime}ms, 类型: {$widgetType}, 模板: {$templatePath}");

            // 如果渲染时间超过200ms，记录警告日志
            if ($renderTime > 200) {
                Log::warning("[WidgetService] 小工具渲染耗时过长: {$renderTime}ms, 类型: {$widgetType}, 模板: {$templatePath}");
            }

            return $html;

        } catch (Throwable $e) {
            Log::error("[WidgetService] Twig模板渲染失败: {$e->getMessage()}");

            return '<div class="bg-white rounded-lg shadow-md p-6 mb-6 text-red-500">小工具渲染失败</div>';
        }
    }

    /**
     * 包装小工具HTML内容（向后兼容）
     *
     * @param string $title 小工具标题
     * @param string $content 小工具内容
     *
     * @return string 包装后的HTML
     */
    protected static function wrapWidgetHtml(string $title, string $content): string
    {
        return '<div class="bg-white rounded-lg shadow-md p-6 mb-6">'
            . (!empty($title) ? '<h3 class="text-lg font-semibold text-gray-800 mb-4 border-b pb-2">' . htmlspecialchars($title) . '</h3>' : '')
            . $content
            . '</div>';
    }

    /**
     * 批量渲染小工具
     *
     * @param array $widgets 小工具配置列表
     * @param string $pageType 页面类型
     *
     * @return array 渲染后的小工具数据列表
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
            'about' => ['关于博主', '显示关于博主的信息'],
            'categories' => ['文章分类', '显示博客文章分类列表'],
            'tags' => ['标签云', '显示博客文章标签云'],
            'archive' => ['文章归档', '按月份显示文章归档列表'],
            'recent_posts' => ['最新文章', '显示最新发布的文章列表'],
            'html' => ['自定义HTML', '自定义HTML内容'],
            'popular_posts' => ['热门文章', '显示最受欢迎的文章列表'],
            'random_posts' => ['随机文章', '随机显示博客中的文章'],
            'ads' => ['广告', '显示广告位（侧边栏）'],
        ];

        foreach ($widgets as $type => $info) {
            // 现在使用Twig模板，不再需要注册HTML渲染器
            self::registerWidget($type, $info[0], $info[1], null, null);
        }
    }

    /**
     * 批量注册小工具（插件使用）
     *
     * @param array $widgets 小工具配置列表
     */
    public static function registerBatchWidgets(array $widgets): void
    {
        foreach ($widgets as $widgetType => $config) {
            $name = $config['name'] ?? ucfirst($widgetType);
            $description = $config['description'] ?? '';
            $renderer = $config['renderer'] ?? null;
            $htmlRenderer = $config['html_renderer'] ?? null;

            self::registerWidget($widgetType, $name, $description, $renderer, $htmlRenderer);
        }
    }

    /**
     * 渲染小工具为HTML并缓存
     *
     * @param array  $widget   小工具配置
     * @param int|null $cacheMinutes 缓存分钟数，null表示使用默认缓存
     * @param string $pageType 页面类型
     *
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
            $cacheMinutes ??= 60;

            // 尝试从缓存获取
            $cachedHtml = cache($cacheKey);
            if ($cachedHtml !== false) {
                return $cachedHtml;
            }

            // 渲染HTML
            $html = self::renderToHtml($widget);

            // 缓存结果
            cache($cacheKey, $html, true, $cacheMinutes * 60);

            return $html;

        } catch (Throwable $e) {
            Log::error('[WidgetService] 渲染小工具HTML缓存失败: ' . $e->getMessage());

            return '<div class="bg-white rounded-lg shadow-md p-6 mb-6 text-red-500">小工具渲染失败</div>';
        }
    }

    /**
     * 渲染数据方法 - 已合并到主渲染逻辑中
     */

    /**
     * 渲染关于博主小工具
     */
    protected static function renderAbout(array $widget): array
    {
        if (!isset($widget['content']) || empty($widget['content'])) {
            $widget['content'] = '欢迎访问我的博客，这里记录了我的技术分享和生活感悟。';
        }

        return $widget;
    }

    /**
     * 渲染分类小工具
     */
    protected static function renderCategories(array $widget): array
    {
        return self::getDataWrapper(function () use ($widget) {
            $count = $widget['params']['count'] ?? 5;
            $query = Category::withCount(['posts' => function ($q) {
                $q->where('status', 'published');
            }])->orderBy('posts_count', 'desc');

            if ($count > 0) {
                $query->take($count);
            }

            $widget['categories'] = $query->get(['id', 'name', 'slug', 'posts_count']);

            return $widget;
        }, $widget, 'categories', []);
    }

    /**
     * 渲染标签云小工具
     */
    protected static function renderTags(array $widget): array
    {
        return self::getDataWrapper(function () use ($widget) {
            $count = $widget['params']['count'] ?? 50;
            $query = Tag::withCount(['posts' => function ($q) {
                $q->where('status', 'published');
            }])->orderBy('posts_count', 'desc');

            if ($count > 0) {
                $query->take($count);
            }

            $widget['tags'] = $query->get(['id', 'name', 'slug', 'posts_count']);

            return $widget;
        }, $widget, 'tags', []);
    }

    /**
     * 渲染文章归档小工具
     */
    protected static function renderArchive(array $widget): array
    {
        return self::getDataWrapper(function () use ($widget) {
            $count = $widget['params']['count'] ?? 5;
            // 使用PostgreSQL兼容的日期格式化函数
            $query = Post::selectRaw("TO_CHAR(created_at, 'YYYY-MM') as year_month, COUNT(*) as count")
                ->where('status', 'published')
                ->groupBy('year_month')
                ->orderBy('year_month', 'desc');

            if ($count > 0) {
                $query->take($count);
            }

            $widget['archive'] = $query->get();

            return $widget;
        }, $widget, 'archive', []);
    }

    /**
     * 渲染最新文章小工具
     */
    protected static function renderRecentPosts(array $widget): array
    {
        return self::getDataWrapper(function () use ($widget) {
            $limit = $widget['params']['count'] ?? 5;
            $widget['recent_posts'] = Post::where('status', 'published')
                ->orderBy('created_at', 'desc')
                ->take($limit)
                ->get(['id', 'title', 'slug', 'created_at']);

            return $widget;
        }, $widget, 'recent_posts', []);
    }

    /**
     * 渲染HTML小工具
     */
    protected static function renderHtml(array $widget): array
    {
        try {
            $widgetId = $widget['widget_id'] ?? 'default';

            if (empty($widget['content'])) {
                try {
                    $settingKey = "custom_widget_html_{$widgetId}";
                    $content = blog_config($settingKey, '');

                    if ($content) {
                        $widget['content'] = $content;
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
        } catch (Throwable $e) {
            Log::error('[WidgetService] 渲染HTML小工具失败: ' . $e->getMessage());
        }

        return $widget;
    }

    /**
     * 渲染热门文章小工具
     */
    protected static function renderPopularPosts(array $widget): array
    {
        return self::getDataWrapper(function () use ($widget) {
            $limit = $widget['params']['count'] ?? 5;

            try {
                $driver = DB::connection()->getDriverName();
                $query = Post::select('posts.*')
                    ->leftJoin('post_ext', function ($join) {
                        $join->on('posts.id', '=', 'post_ext.post_id')
                            ->where('post_ext.key', '=', 'view_count');
                    })
                    ->where('posts.status', 'published');

                if ($driver === 'pgsql') {
                    $query->orderByRaw("COALESCE((post_ext.value->>'count')::int, 0) DESC");
                } elseif ($driver === 'mysql') {
                    $query->orderByRaw("COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(post_ext.value, '$.count')) AS UNSIGNED), 0) DESC");
                } elseif ($driver === 'sqlite') {
                    $query->orderByRaw("COALESCE(json_extract(post_ext.value, '$.count') + 0, 0) DESC");
                } else {
                    $query->orderBy('posts.created_at', 'desc');
                }

                $posts = $query->take($limit)->get(['posts.id', 'posts.title', 'posts.slug', 'posts.created_at']);

                foreach ($posts as $p) {
                    $ext = $p->getExt('view_count');
                    $p->views = $ext ? (int) (($ext->value['count'] ?? 0)) : 0;
                }

                $widget['popular_posts'] = $posts;
            } catch (Throwable $e) {
                Log::warning('[WidgetService] 热门文章获取失败: ' . $e->getMessage());
                $widget['popular_posts'] = collect();
            }

            return $widget;
        }, $widget, 'popular_posts', []);
    }

    /**
     * 渲染随机文章小工具
     */
    protected static function renderRandomPosts(array $widget): array
    {
        return self::getDataWrapper(function () use ($widget) {
            $limit = $widget['params']['count'] ?? 5;
            // 只获取已发布的文章，使用PostgreSQL兼容的随机排序
            $widget['random_posts'] = Post::where('status', 'published')
                ->orderByRaw('RANDOM()')
                ->take($limit)
                ->get(['id', 'title', 'slug', 'created_at']);

            // 调试信息
            if ($widget['random_posts']->isEmpty()) {
                Log::info('[WidgetService] 随机文章小工具：数据库中没有已发布的文章');
            }

            return $widget;
        }, $widget, 'random_posts', []);
    }

    /**
     * 渲染广告小工具（侧边栏）
     */
    protected static function renderAds(array $widget): array
    {
        $limit = (int) ($widget['params']['count'] ?? 1);
        $ads = AdService::getActiveAdsByPosition('sidebar', $limit > 0 ? $limit : null);
        foreach ($ads as $i => $ad) {
            $ads[$i]['snippet_html'] = AdService::renderAdHtml($ad, 'sidebar');
        }
        $widget['ads'] = $ads;

        return $widget;
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
            $html .= '<a href="' . URLHelper::generateCategoryUrl($category['slug']) . '" class="flex justify-between items-center text-gray-800 hover:text-blue-600 transition-colors">';
            $html .= '<span>' . htmlspecialchars($category['name']) . '</span>';
            $html .= '<span class="text-xs bg-blue-50 text-blue-600 px-2 py-0.5 rounded-full border border-blue-100">' . htmlspecialchars($category['posts_count']) . '</span>';
            $html .= '</a>';
        }
        $html .= '</div>';

        return $html;
    }

    protected static function renderTagsHtml(array $widget): string
    {
        $tags = $widget['tags'] ?? [];
        if ($tags instanceof Collection) {
            $tags = $tags->all();
        }
        if (empty($tags)) {
            return '<div class="text-gray-500 text-sm italic">暂无标签</div>';
        }

        // 按用户配置的首屏直显数量展示，其余折叠
        $visible = (int) ($widget['params']['visible'] ?? 30);
        if ($visible < 0) {
            $visible = 0;
        }
        $primary = array_slice($tags, 0, $visible);
        $extra = array_slice($tags, $visible);

        // 柔和渐变色池
        $gradients = [
            'bg-gradient-to-r from-indigo-200 via-purple-200 to-pink-200 text-indigo-700 hover:from-indigo-300 hover:via-purple-300 hover:to-pink-300',
            'bg-gradient-to-r from-sky-200 via-cyan-200 to-teal-200 text-sky-700 hover:from-sky-300 hover:via-cyan-300 hover:to-teal-300',
            'bg-gradient-to-r from-amber-200 via-orange-200 to-rose-200 text-amber-700 hover:from-amber-300 hover:via-orange-300 hover:to-rose-300',
            'bg-gradient-to-r from-lime-200 via-green-200 to-emerald-200 text-green-700 hover:from-lime-300 hover:via-green-300 hover:to-emerald-300',
            'bg-gradient-to-r from-fuchsia-200 via-pink-200 to-rose-200 text-fuchsia-700 hover:from-fuchsia-300 hover:via-pink-300 hover:to-rose-300',
            'bg-gradient-to-r from-blue-200 via-indigo-200 to-violet-200 text-blue-700 hover:from-blue-300 hover:via-indigo-300 hover:to-violet-300',
        ];

        // 生成稳定的旋转角与透明度
        $computeStyle = function ($tag) use ($gradients) {
            // 同时兼容数组与Eloquent模型对象
            if (is_array($tag)) {
                $key = (string) ($tag['slug'] ?? $tag['id'] ?? $tag['name'] ?? '');
            } else {
                $key = (string) ($tag->slug ?? $tag->id ?? $tag->name ?? '');
            }
            $seed = crc32($key);
            $angle = (($seed % 11) - 5); // -5 ~ +5 度
            $opacity = 0.90 + (($seed % 10) * 0.01); // 0.90 ~ 0.99
            $gidx = $seed % count($gradients);
            $gradient = $gradients[$gidx];

            // 初始旋转 + 半透明；hover通过内联事件修改transform以回正并放大
            $style = 'transform: rotate(' . $angle . 'deg); opacity: ' . number_format($opacity, 2) . '; will-change: transform, opacity;';
            $classes = 'text-sm px-3 py-1 rounded-full border border-white/60 shadow-sm transition-all duration-150 ' . $gradient . ' hover:shadow-md';

            return [$style, $classes, $angle];
        };

        $renderTag = function ($tag) use ($computeStyle) {
            [$style, $classes, $angle] = $computeStyle($tag);
            $name = is_array($tag) ? ($tag['name'] ?? '') : ($tag->name ?? '');
            $count = is_array($tag) ? ($tag['posts_count'] ?? 0) : ($tag->posts_count ?? 0);
            $slug = is_array($tag) ? ($tag['slug'] ?? '') : ($tag->slug ?? '');
            $label = htmlspecialchars($name) . ' (' . htmlspecialchars($count) . ')';

            return '<a href="' . URLHelper::generateTagUrl($slug) . '"'
                . ' class="' . $classes . '"'
                . ' style="' . $style . '"'
                . ' onmouseover="this.style.transform=\'rotate(0deg) scale(1.05)\'"'
                . ' onmouseout="this.style.transform=\'rotate(' . (int) $angle . 'deg) scale(1)\'">'
                . $label . '</a>';
        };

        $html = '<div class="flex flex-wrap gap-2">';
        foreach ($primary as $tag) {
            $html .= $renderTag($tag);
        }
        $html .= '</div>';

        if (!empty($extra)) {
            $html .= '<details class="mt-3 group">';
            $html .= '<summary class="cursor-pointer text-sm text-gray-600 hover:text-blue-600 transition-colors select-none">更多标签</summary>';
            $html .= '<div class="mt-2 flex flex-wrap gap-2">';
            foreach ($extra as $tag) {
                $html .= $renderTag($tag);
            }
            $html .= '</div>';
            $html .= '</details>';
        }

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
            $html .= '<a href="' . URLHelper::generatePostUrl($post['slug']) . '" class="block group">';
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
            $html .= '<a href="' . URLHelper::generatePostUrl($post['slug']) . '" class="block group">';
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
            $html .= '<a href="' . URLHelper::generatePostUrl($post['slug']) . '" class="block group">';
            $html .= '<div class="text-sm font-medium text-gray-800 group-hover:text-blue-600 transition-colors">';
            $html .= htmlspecialchars($post['title']);
            $html .= '</div>';
            $html .= '</a>';
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * 数据获取包装器 - 统一错误处理和数据设置
     *
     * @param callable $callback     数据获取回调函数
     * @param array    $widget       小工具配置
     * @param string   $dataKey      数据键名
     * @param mixed    $defaultValue 默认值
     *
     * @return array 处理后的小工具数据
     */
    protected static function getDataWrapper(callable $callback, array $widget, string $dataKey, $defaultValue): array
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            Log::error("[WidgetService] 渲染小工具失败 ({$dataKey}): " . $e->getMessage());
            $widget[$dataKey] = $defaultValue;

            return $widget;
        }
    }
}
