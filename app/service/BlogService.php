<?php

namespace app\service;

use app\model\Post;
use app\service\PaginationService;
use Illuminate\Support\Collection;
use League\CommonMark\CommonMarkConverter;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Exception\CommonMarkException;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\TaskList\TaskListExtension;
use League\CommonMark\MarkdownConverter;
use support\Redis;
use Throwable;

class BlogService
{
    /**
     * 获取博客配置
     *
     * @param string $key 配置键名
     * @param mixed $default 默认值
     * @return mixed 配置值
     * @throws Throwable
     */
    public static function getConfig(string $key, mixed $default = null): mixed
    {
        return blog_config($key, $default);
    }
    
    /**
     * 获取博客标题
     *
     * @return string 博客标题
     */
    public static function getBlogTitle(): string
    {
        return self::getConfig('title', 'WindBlog');
    }
    
    /**
     * 获取每页显示的文章数量
     *
     * @return int 每页文章数
     */
    public static function getPostsPerPage(): int
    {
        return self::getConfig('posts_per_page', 10);
    }
    
    /**
     * 获取博客文章列表
     *
     * @param int $page 当前页码
     * @param array $filters 筛选条件
     * @return array 包含文章列表和分页信息的数组
     */
    public static function getBlogPosts(int $page = 1, array $filters = []): array
    {
        $postsPerPage = self::getPostsPerPage();
        $cacheKey = 'blog_posts_page_' . $page . '_per_' . $postsPerPage;
        
        // 当没有筛选条件时尝试从缓存获取
        if (empty($filters)) {
            $cached = self::getFromCache($cacheKey);
            if ($cached) {
                return $cached;
            }
            
            // 尝试兼容旧的缓存格式
            $oldCacheKey = 'blog_posts_page_' . $page;
            $oldCached = self::getFromCache($oldCacheKey);
            if ($oldCached) {
                // 清理旧缓存并返回新格式
                \support\Redis::connection('cache')->del($oldCacheKey);
                
                // 确保返回数组格式
                if (!is_array($oldCached)) {
                    // 统计总文章数
                    $totalCount = Post::where('status', 'published')->count();
                    
                    // 生成分页HTML
                    $paginationHtml = PaginationService::generatePagination(
                        $page,
                        $totalCount,
                        $postsPerPage,
                        'index.page',
                        [],
                        10
                    );
                    
                    return [
                        'posts' => $oldCached,
                        'pagination' => $paginationHtml,
                        'totalCount' => $totalCount,
                        'currentPage' => $page,
                        'postsPerPage' => $postsPerPage
                    ];
                }
                return $oldCached;
            }
        }
        
        // 统计总文章数
        $totalCount = Post::where('status', 'published')->count();
        
        // 获取文章列表
        $posts = Post::where('status', 'published')
            ->orderByDesc('id')
            ->forPage($page, $postsPerPage)
            ->get();
        
        // 处理文章摘要
        self::processPostExcerpts($posts);
        
        // 生成分页HTML
        $paginationHtml = PaginationService::generatePagination(
            $page,
            $totalCount,
            $postsPerPage,
            'index.page',
            [],
            10
        );
        
        // 构建结果数组
        $result = [
            'posts' => $posts,
            'pagination' => $paginationHtml,
            'totalCount' => $totalCount,
            'currentPage' => $page,
            'postsPerPage' => $postsPerPage
        ];
        
        // 缓存结果
        if (empty($filters)) {
            self::cacheResult($cacheKey, $result);
        }
        
        return $result;
    }

    /**
     * 处理文章摘要
     *
     * @param Collection $posts 文章集合
     * @throws CommonMarkException
     */
    protected static function processPostExcerpts(Collection $posts): void
    {
        foreach ($posts as $post) {
            if (empty($post->excerpt)) {
                // 使用CommonMarkConverter将内容转换为HTML，再删除HTML标签生成摘要
                $config = [
                    'html_input' => 'allow',
                    'allow_unsafe_links' => false,
                    'max_nesting_level' => 10,
                    'renderer' => [
                        'soft_break' => "<br />\n",
                    ],
                    'commonmark' => [
                        'enable_em' => true,
                        'enable_strong' => true,
                        'use_asterisk' => true,
                        'use_underscore' => true,
                        'unordered_list_markers' => ['-', '+', '*'],
                    ]
                ];
                
                $environment = new Environment($config);
                $environment->addExtension(new CommonMarkCoreExtension());
                $environment->addExtension(new AutolinkExtension());
                $environment->addExtension(new StrikethroughExtension());
                $environment->addExtension(new TableExtension());
                $environment->addExtension(new TaskListExtension());
                
                $converter = new MarkdownConverter($environment);

                $html = $converter->convert($post->content);
                $excerpt = mb_substr(strip_tags((string)$html), 0, 200, 'UTF-8');
                
                // 自动生成文章摘要并保存
                $post->excerpt = $excerpt;
                $post->save();
            }
        }
    }
    
    /**
     * 从缓存获取数据
     *
     * @param string $key 缓存键名
     * @return array|bool 缓存数据或false
     */
    protected static function getFromCache(string $key): array|bool
    {
        try {
            $cached = cache($key);
            // 确保返回的是数组格式
            if (is_array($cached)) {
                return $cached;
            }
            return false;
        } catch (Throwable $e) {
            // 缓存获取失败时静默返回false
            return false;
        }
    }
    
    /**
     * 缓存结果数据
     *
     * @param string $key 缓存键名
     * @param mixed $data 要缓存的数据
     */
    protected static function cacheResult(string $key, mixed $data): void
    {
        try {
            cache($key, $data, true);
        } catch (Throwable $e) {
            // 缓存设置失败时记录错误但不中断流程
            \support\Log::channel('blog_service')->error('[cacheResult] Error caching data: ' . $e->getMessage());
        }
    }
}