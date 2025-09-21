<?php
namespace app\service;

use app\model\Setting;
use app\model\Post;
use support\Log;
use Throwable;

/**
 * 自定义小工具服务
 * 负责处理自定义HTML小工具的渲染和管理
 */
class CustomWidgetService
{
    /**
     * 获取自定义HTML小工具
     * 
     * @param string $pageType 页面类型
     * @param string $widgetId 小工具ID，默认为'default'
     * @return array 小工具配置和内容
     */
    public static function getCustomHtmlWidget(string $pageType, string $widgetId = 'default'): array
    {
        try {
            // 从WidgetConfigService获取配置
            $config = WidgetConfigService::getWidgetConfig('custom_html_' . $widgetId, $pageType);
            
            // 如果小工具未启用，返回空配置
            if (!isset($config['enabled']) || !$config['enabled']) {
                return ['enabled' => false];
            }
            
            // 获取存储在数据库中的自定义HTML代码
            $customHtml = self::getCustomHtmlContent($widgetId);
            
            return [
                'enabled' => true,
                'title' => $config['title'] ?: '自定义HTML',
                'html' => $customHtml,
                'config' => $config,
                'widget_id' => $widgetId
            ];
        } catch (Throwable $e) {
            Log::error('[CustomWidgetService] 获取自定义HTML小工具失败: ' . $e->getMessage());
            return ['enabled' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 获取热门文章小工具
     * 
     * @param string $pageType 页面类型
     * @return array 小工具配置和内容
     */
    public static function getHotArticlesWidget(string $pageType): array
    {
        try {
            // 从WidgetConfigService获取配置
            $config = WidgetConfigService::getWidgetConfig('hot_articles', $pageType);
            
            // 如果小工具未启用，返回空配置
            if (!isset($config['enabled']) || !$config['enabled']) {
                return ['enabled' => false];
            }
            
            // 获取显示数量，默认为5
            $count = $config['params']['count'] ?? 5;
            
            // 从数据库中获取实际的热门文章
            $articles = self::getActualHotArticles($count);
            
            // 生成HTML内容
            $html = self::generateArticlesHtml($articles);
            
            return [
                'enabled' => true,
                'title' => $config['title'] ?: '热门文章',
                'html' => $html,
                'config' => $config,
                'articles' => $articles
            ];
        } catch (Throwable $e) {
            Log::error('[CustomWidgetService] 获取热门文章小工具失败: ' . $e->getMessage());
            // 错误时返回示例数据
            $count = 5;
            $articles = self::getSampleHotArticles($count);
            $html = self::generateArticlesHtml($articles);
            
            return [
                'enabled' => true,
                'title' => '热门文章',
                'html' => $html,
                'articles' => $articles,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * 获取随机文章小工具
     * 
     * @param string $pageType 页面类型
     * @return array 小工具配置和内容
     */
    public static function getRandomArticlesWidget(string $pageType): array
    {
        try {
            // 从WidgetConfigService获取配置
            $config = WidgetConfigService::getWidgetConfig('random_articles', $pageType);
            
            // 如果小工具未启用，返回空配置
            if (!isset($config['enabled']) || !$config['enabled']) {
                return ['enabled' => false];
            }
            
            // 获取显示数量，默认为5
            $count = $config['params']['count'] ?? 5;
            
            // 从数据库中获取实际的随机文章
            $articles = self::getActualRandomArticles($count);
            
            // 生成HTML内容
            $html = self::generateArticlesHtml($articles);
            
            return [
                'enabled' => true,
                'title' => $config['title'] ?: '随机推荐',
                'html' => $html,
                'config' => $config,
                'articles' => $articles
            ];
        } catch (Throwable $e) {
            Log::error('[CustomWidgetService] 获取随机文章小工具失败: ' . $e->getMessage());
            // 错误时返回示例数据
            $count = 5;
            $articles = self::getSampleRandomArticles($count);
            $html = self::generateArticlesHtml($articles);
            
            return [
                'enabled' => true,
                'title' => '随机推荐',
                'html' => $html,
                'articles' => $articles,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * 保存自定义HTML内容到数据库
     * 
     * @param string $widgetId 小工具ID
     * @param string $html HTML内容
     * @return bool 是否保存成功
     */
    public static function saveCustomHtmlContent(string $widgetId, string $html): bool
    {
        try {
            $settingKey = "custom_widget_html_{$widgetId}";
            
            // 更新或创建设置
            Setting::updateOrCreate(
                ['key' => $settingKey],
                ['value' => $html]
            );
            
            // 清除相关缓存
            WidgetService::clearWidgetCache(['type' => 'html', 'widget_id' => $widgetId]);
            
            return true;
        } catch (Throwable $e) {
            // 记录错误日志
            Log::error('[CustomWidgetService] 保存自定义HTML内容失败: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 从数据库获取自定义HTML内容
     * 
     * @param string $widgetId 小工具ID
     * @return string HTML内容
     */
    private static function getCustomHtmlContent(string $widgetId): string
    {
        try {
            $settingKey = "custom_widget_html_{$widgetId}";
            $setting = Setting::where('key', $settingKey)->first();
            
            if ($setting) {
                return $setting->value;
            }
        } catch (Throwable $e) {
            Log::error('[CustomWidgetService] 获取自定义HTML内容失败: ' . $e->getMessage());
        }
        
        // 返回默认内容
        return '<div class="widget-content p-4 bg-gray-50 rounded">
                   <p class="text-center text-gray-500">这是一个自定义HTML小工具。</p>
                 </div>';
    }
    
    /**
     * 获取实际热门文章数据
     * 
     * @param int $count 文章数量
     * @return array 热门文章数组
     */
    private static function getActualHotArticles(int $count): array
    {
        try {
            // 从数据库获取热门文章
            $posts = Post::where('status', 'published')
                ->orderBy('views', 'desc')
                ->take($count)
                ->get(['id', 'title', 'slug']);
            
            // 格式化结果
            $articles = [];
            foreach ($posts as $post) {
                $articles[] = [
                    'title' => $post->title,
                    'url' => route('article.detail', ['slug' => $post->slug]),
                    'views' => $post->views
                ];
            }
            
            return $articles;
        } catch (Throwable $e) {
            Log::error('[CustomWidgetService] 获取实际热门文章失败: ' . $e->getMessage());
            // 失败时返回示例数据
            return self::getSampleHotArticles($count);
        }
    }
    
    /**
     * 获取示例热门文章数据
     * 
     * @param int $count 文章数量
     * @return array 示例热门文章数组
     */
    private static function getSampleHotArticles(int $count): array
    {
        // 示例数据，实际应用中应该从数据库获取
        $allArticles = [
            ['title' => '如何高效学习编程', 'url' => '#', 'views' => 1245],
            ['title' => 'Web开发的未来趋势', 'url' => '#', 'views' => 987],
            ['title' => '数据库优化的10个技巧', 'url' => '#', 'views' => 856],
            ['title' => 'Linux命令行入门指南', 'url' => '#', 'views' => 743],
            ['title' => 'Git版本控制最佳实践', 'url' => '#', 'views' => 698],
            ['title' => '前端框架对比分析', 'url' => '#', 'views' => 542],
        ];
        
        // 根据count截取文章列表
        return array_slice($allArticles, 0, $count);
    }
    
    /**
     * 获取实际随机文章数据
     * 
     * @param int $count 文章数量
     * @return array 随机文章数组
     */
    private static function getActualRandomArticles(int $count): array
    {
        try {
            // 从数据库获取随机文章
            $posts = Post::where('status', 'published')
                ->inRandomOrder()
                ->take($count)
                ->get(['id', 'title', 'slug']);
            
            // 格式化结果
            $articles = [];
            foreach ($posts as $post) {
                $articles[] = [
                    'title' => $post->title,
                    'url' => route('article.detail', ['slug' => $post->slug])
                ];
            }
            
            return $articles;
        } catch (Throwable $e) {
            Log::error('[CustomWidgetService] 获取实际随机文章失败: ' . $e->getMessage());
            // 失败时返回示例数据
            return self::getSampleRandomArticles($count);
        }
    }
    
    /**
     * 获取示例随机文章数据
     * 
     * @param int $count 文章数量
     * @return array 示例随机文章数组
     */
    private static function getSampleRandomArticles(int $count): array
    {
        // 示例数据，实际应用中应该从数据库获取
        $allArticles = [
            ['title' => '如何高效学习编程', 'url' => '#'],
            ['title' => 'Web开发的未来趋势', 'url' => '#'],
            ['title' => '数据库优化的10个技巧', 'url' => '#'],
            ['title' => 'Linux命令行入门指南', 'url' => '#'],
            ['title' => 'Git版本控制最佳实践', 'url' => '#'],
            ['title' => '前端框架对比分析', 'url' => '#'],
        ];
        
        // 打乱文章顺序
        shuffle($allArticles);
        
        // 根据count截取文章列表
        return array_slice($allArticles, 0, $count);
    }
    
    /**
     * 生成文章列表HTML
     * 
     * @param array $articles 文章数组
     * @return string HTML内容
     */
    private static function generateArticlesHtml(array $articles): string
    {
        try {
            $html = '<div class="widget-content">
                       <ul class="list-disc pl-5 space-y-2">';
            
            foreach ($articles as $article) {
                $title = $article['title'];
                $url = $article['url'];
                
                // 如果有浏览量，显示浏览量
                $viewsHtml = isset($article['views']) ? 
                    ' <span class="text-xs text-gray-500">(' . $article['views'] . ' 次浏览)</span>' : '';
                
                $html .= '<li><a href="' . $url . '" class="hover:text-blue-600 transition-colors">' 
                      . $title . $viewsHtml . '</a></li>';
            }
            
            $html .= '</ul>
                     </div>';
            
            return $html;
        } catch (Throwable $e) {
            Log::error('[CustomWidgetService] 生成文章HTML失败: ' . $e->getMessage());
            return '<div class="widget-content text-red-500">文章列表加载失败</div>';
        }
    }
    
    /**
     * 渲染自定义小工具
     * 
     * @param array $widget 小工具配置
     * @param string $pageType 页面类型
     * @return array 渲染后的小工具数据
     */
    public static function renderCustomWidget(array $widget, string $pageType = 'default'): array
    {
        try {
            $widgetType = $widget['type'] ?? '';
            
            switch ($widgetType) {
                case 'custom_html':
                    $widgetId = $widget['widget_id'] ?? 'default';
                    $result = self::getCustomHtmlWidget($pageType, $widgetId);
                    break;
                case 'hot_articles':
                    $result = self::getHotArticlesWidget($pageType);
                    break;
                case 'random_articles':
                    $result = self::getRandomArticlesWidget($pageType);
                    break;
                default:
                    return ['enabled' => false, 'error' => '未知的自定义小工具类型'];
            }
            
            // 合并原小工具配置和渲染结果
            return array_merge($widget, $result);
        } catch (Throwable $e) {
            Log::error('[CustomWidgetService] 渲染自定义小工具失败: ' . $e->getMessage());
            return ['enabled' => false, 'error' => $e->getMessage()];
        }
    }
}