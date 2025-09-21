<?php

namespace app\service;

use support\Log;
use Throwable;

/**
 * 默认小工具服务
 * 包含系统内置的默认小工具实现
 */
class DefaultWidgetService
{
    /**
     * 注册所有默认小工具
     * 这个方法应该在应用启动时调用
     */
    public static function registerDefaultWidgets(): void
    {
        try {
            // 注册关于博主小工具
            WidgetService::registerWidget(
                'about', 
                '关于博主', 
                '显示关于博主的信息',
                function(array $widget) {
                    // 获取页面类型
                    $pageType = $widget['page_type'] ?? 'default';
                    
                    // 从WidgetConfigService获取配置
                    $config = WidgetConfigService::getWidgetConfig('about', $pageType);
                    
                    // 合并配置
                    $widget = array_merge($widget, $config);
                    
                    // 默认关于内容
                    if (empty($widget['content'])) {
                        $widget['content'] = '欢迎访问我的博客，这里记录了我的技术分享和生活感悟。';
                    }
                    return $widget;
                }
            );

            // 注册文章分类小工具
            WidgetService::registerWidget(
                'categories', 
                '文章分类', 
                '显示博客文章分类列表',
                function(array $widget) {
                    // 获取页面类型
                    $pageType = $widget['page_type'] ?? 'default';
                    
                    // 从WidgetConfigService获取配置
                    $config = WidgetConfigService::getWidgetConfig('categories', $pageType);
                    
                    // 如果小工具未启用，返回空配置
                    if (isset($config['enabled']) && !$config['enabled']) {
                        return ['enabled' => false];
                    }
                    
                    // 合并配置
                    $widget = array_merge($widget, $config);
                    
                    try {
                        // 获取分类数据
                        $categories = \app\model\Category::withCount('posts')
                            ->orderBy('posts_count', 'desc');
                        
                        // 应用数量限制
                        if (isset($widget['params']['count']) && $widget['params']['count'] > 0) {
                            $categories->take($widget['params']['count']);
                        }
                        
                        $widget['categories'] = $categories->get(['id', 'name', 'slug', 'posts_count']);
                    } catch (Throwable $e) {
                        Log::error('[DefaultWidgetService] Failed to render categories widget: ' . $e->getMessage());
                        $widget['categories'] = [];
                    }
                    return $widget;
                }
            );

            // 注册标签云小工具
            WidgetService::registerWidget(
                'tags', 
                '标签云', 
                '显示博客文章标签云',
                function(array $widget) {
                    // 获取页面类型
                    $pageType = $widget['page_type'] ?? 'default';
                    
                    // 从WidgetConfigService获取配置
                    $config = WidgetConfigService::getWidgetConfig('tags', $pageType);
                    
                    // 如果小工具未启用，返回空配置
                    if (isset($config['enabled']) && !$config['enabled']) {
                        return ['enabled' => false];
                    }
                    
                    // 合并配置
                    $widget = array_merge($widget, $config);
                    
                    try {
                        // 获取标签云
                        $tags = \app\model\Tag::withCount('posts')
                            ->orderBy('posts_count', 'desc');
                        
                        // 应用数量限制
                        if (isset($widget['params']['count']) && $widget['params']['count'] > 0) {
                            $tags->take($widget['params']['count']);
                        }
                        
                        $widget['tags'] = $tags->get(['id', 'name', 'slug', 'posts_count']);
                    } catch (Throwable $e) {
                        Log::error('[DefaultWidgetService] Failed to render tags widget: ' . $e->getMessage());
                        $widget['tags'] = [];
                    }
                    return $widget;
                }
            );

            // 注册文章归档小工具
            WidgetService::registerWidget(
                'archive', 
                '文章归档', 
                '按月份显示文章归档列表',
                function(array $widget) {
                    // 获取页面类型
                    $pageType = $widget['page_type'] ?? 'default';
                    
                    // 从WidgetConfigService获取配置
                    $config = WidgetConfigService::getWidgetConfig('archive', $pageType);
                    
                    // 如果小工具未启用，返回空配置
                    if (isset($config['enabled']) && !$config['enabled']) {
                        return ['enabled' => false];
                    }
                    
                    // 合并配置
                    $widget = array_merge($widget, $config);
                    
                    try {
                        // 获取文章归档（按年月分组）
                        $archive = \app\model\Post::selectRaw('DATE_FORMAT(created_at, "%Y-%m") as year_month, COUNT(*) as count, MIN(created_at) as min_date')
                            ->where('status', 'published')
                            ->groupBy('year_month')
                            ->orderBy('year_month', 'desc');
                        
                        // 应用数量限制
                        if (isset($widget['params']['count']) && $widget['params']['count'] > 0) {
                            $archive->take($widget['params']['count']);
                        }
                        
                        $widget['archive'] = $archive->get();
                    } catch (Throwable $e) {
                        Log::error('[DefaultWidgetService] Failed to render archive widget: ' . $e->getMessage());
                        $widget['archive'] = [];
                    }
                    return $widget;
                }
            );

            // 注册最新文章小工具
            WidgetService::registerWidget(
                'recent_posts', 
                '最新文章', 
                '显示最新发布的文章列表',
                function(array $widget) {
                    // 获取页面类型
                    $pageType = $widget['page_type'] ?? 'default';
                    
                    // 从WidgetConfigService获取配置
                    $config = WidgetConfigService::getWidgetConfig('recent_posts', $pageType);
                    
                    // 如果小工具未启用，返回空配置
                    if (isset($config['enabled']) && !$config['enabled']) {
                        return ['enabled' => false];
                    }
                    
                    // 合并配置
                    $widget = array_merge($widget, $config);
                    
                    try {
                        // 获取最新文章
                        $limit = $widget['params']['count'] ?? ($widget['limit'] ?? 5);
                        $recentPosts = \app\model\Post::where('status', 'published')
                            ->orderBy('created_at', 'desc')
                            ->take($limit)
                            ->get(['id', 'title', 'slug', 'created_at']);
                        
                        $widget['recent_posts'] = $recentPosts;
                    } catch (Throwable $e) {
                        Log::error('[DefaultWidgetService] Failed to render recent posts widget: ' . $e->getMessage());
                        $widget['recent_posts'] = [];
                    }
                    return $widget;
                }
            );

            // 注册自定义HTML小工具
            WidgetService::registerWidget(
                'html', 
                '自定义HTML', 
                '自定义HTML内容',
                function(array $widget) {
                    // 获取页面类型
                    $pageType = $widget['page_type'] ?? 'default';
                    $widgetId = $widget['widget_id'] ?? 'default';
                    
                    // 从WidgetConfigService获取配置
                    $config = WidgetConfigService::getWidgetConfig('custom_html_' . $widgetId, $pageType);
                    
                    // 如果小工具未启用，返回空配置
                    if (isset($config['enabled']) && !$config['enabled']) {
                        return ['enabled' => false];
                    }
                    
                    // 合并配置
                    $widget = array_merge($widget, $config);
                    
                    // 获取存储在数据库中的自定义HTML代码
                    if (empty($widget['content'])) {
                        $settingKey = "custom_widget_html_{$widgetId}";
                        $setting = \app\model\Setting::where('key', $settingKey)->first();
                        
                        if ($setting) {
                            $widget['content'] = $setting->value;
                        } else {
                            $widget['content'] = '<div class="widget-content p-4 bg-gray-50 rounded">
                                                   <p class="text-center text-gray-500">这是一个自定义HTML小工具。</p>
                                                 </div>';
                        }
                    }
                    
                    return $widget;
                }
            );

            // 注册热门文章小工具
            WidgetService::registerWidget(
                'popular_posts', 
                '热门文章', 
                '显示最受欢迎的文章列表',
                function(array $widget) {
                    // 获取页面类型
                    $pageType = $widget['page_type'] ?? 'default';
                    
                    // 从WidgetConfigService获取配置
                    $config = WidgetConfigService::getWidgetConfig('popular_posts', $pageType);
                    
                    // 如果小工具未启用，返回空配置
                    if (isset($config['enabled']) && !$config['enabled']) {
                        return ['enabled' => false];
                    }
                    
                    // 合并配置
                    $widget = array_merge($widget, $config);
                    
                    try {
                        // 获取热门文章（使用发布日期作为排序依据，因为posts表中没有views字段）
                        $limit = $widget['params']['count'] ?? ($widget['limit'] ?? 5);
                        $popularPosts = \app\model\Post::where('status', 'published')
                            ->orderBy('created_at', 'desc')
                            ->take($limit)
                            ->get(['id', 'title', 'slug', 'created_at']);
                        
                        $widget['popular_posts'] = $popularPosts;
                    } catch (Throwable $e) {
                        Log::error('[DefaultWidgetService] Failed to render popular posts widget: ' . $e->getMessage());
                        $widget['popular_posts'] = [];
                    }
                    return $widget;
                }
            );

            // 注册随机文章小工具
            WidgetService::registerWidget(
                'random_posts', 
                '随机文章', 
                '随机显示博客中的文章',
                function(array $widget) {
                    // 获取页面类型
                    $pageType = $widget['page_type'] ?? 'default';
                    
                    // 从WidgetConfigService获取配置
                    $config = WidgetConfigService::getWidgetConfig('random_posts', $pageType);
                    
                    // 如果小工具未启用，返回空配置
                    if (isset($config['enabled']) && !$config['enabled']) {
                        return ['enabled' => false];
                    }
                    
                    // 合并配置
                    $widget = array_merge($widget, $config);
                    
                    try {
                        // 获取随机文章
                        $limit = $widget['params']['count'] ?? ($widget['limit'] ?? 5);
                        $randomPosts = \app\model\Post::where('status', 'published')
                            ->inRandomOrder()
                            ->take($limit)
                            ->get(['id', 'title', 'slug', 'created_at']);
                        
                        $widget['random_posts'] = $randomPosts;
                    } catch (Throwable $e) {
                        Log::error('[DefaultWidgetService] Failed to render random posts widget: ' . $e->getMessage());
                        $widget['random_posts'] = [];
                    }
                    return $widget;
                }
            );

            Log::info("[DefaultWidgetService] 默认小工具注册完成");
        } catch (Throwable $e) {
            Log::error("[DefaultWidgetService] 注册默认小工具失败: " . $e->getMessage());
        }
    }

    /**
     * 获取所有默认小工具的信息
     *
     * @return array 默认小工具信息列表
     */
    public static function getDefaultWidgetsInfo(): array
    {
        return [
            [
                'type' => 'about',
                'name' => '关于博主',
                'description' => '显示关于博主的信息',
                'is_core' => true
            ],
            [
                'type' => 'categories',
                'name' => '文章分类',
                'description' => '显示博客文章分类列表',
                'is_core' => true
            ],
            [
                'type' => 'tags',
                'name' => '标签云',
                'description' => '显示博客文章标签云',
                'is_core' => true
            ],
            [
                'type' => 'archive',
                'name' => '文章归档',
                'description' => '按月份显示文章归档列表',
                'is_core' => true
            ],
            [
                'type' => 'recent_posts',
                'name' => '最新文章',
                'description' => '显示最新发布的文章列表',
                'is_core' => true
            ],
            [
                'type' => 'popular_posts',
                'name' => '热门文章',
                'description' => '显示最受欢迎的文章列表',
                'is_core' => true
            ],
            [
                'type' => 'random_posts',
                'name' => '随机文章',
                'description' => '随机显示博客中的文章',
                'is_core' => true
            ],
            [
                'type' => 'html',
                'name' => '自定义HTML',
                'description' => '自定义HTML内容',
                'is_core' => true
            ]
        ];
    }
}