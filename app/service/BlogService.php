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
use support\Log;
use support\Redis;
use Throwable;
use app\service\ElasticService;

/**
 * 博客服务类
 */
class BlogService
{
    /**
     * 获取博客配置
     *
     * @param string $key     配置键名
     * @param mixed  $default 默认值
     *
     * @return mixed 配置值
     * @throws Throwable
     */
    public static function getConfig(string $key, mixed $default = null): mixed
    {
        return blog_config($key, $default, true);
    }

    /**
     * 获取博客标题
     *
     * @return string 博客标题
     * @throws Throwable
     */
    public static function getBlogTitle(): string
    {
        return self::getConfig('title', 'WindBlog');
    }

    /**
     * 获取每页显示的文章数量
     *
     * @return int 每页文章数
     * @throws Throwable
     */
    public static function getPostsPerPage(): int
    {
        $val = self::getConfig('posts_per_page', 10);
        $per = is_numeric($val) ? (int)$val : 10;
        if ($per <= 0) {
            $per = 10;
        }
        return $per;
    }

    /**
     * 获取博客文章列表
     *
     * @param int   $page    当前页码
     * @param array $filters 筛选条件
     *
     * @return array 包含文章列表和分页信息的数组
     * @throws CommonMarkException|Throwable
     */
    public static function getBlogPosts(int $page = 1, array $filters = []): array
    {
        $posts_per_page = self::getPostsPerPage();
        
        // 生成缓存键，包含筛选条件
        $filter_key = empty($filters) ? '' : '_' . md5(serialize($filters));
        $cache_key = 'blog_posts_page_' . $page . '_per_' . $posts_per_page . $filter_key;

        // 当没有筛选条件时尝试从缓存获取
        if (empty($filters)) {
            $cached = self::getFromCache($cache_key);
            if ($cached) {
                return $cached;
            }
        }

        // 构建查询
        $query = Post::where('status', 'published');

        // 处理搜索筛选条件
        if (!empty($filters)) {
            foreach ($filters as $key => $value) {
                switch ($key) {
                    case 'search':
                        if (!empty($value)) {
                            $query->where(function($q) use ($value) {
                                $q->where('title', 'like', "%{$value}%")
                                  ->orWhere('content', 'like', "%{$value}%")
                                  ->orWhere('excerpt', 'like', "%{$value}%");
                            });
                        }
                        break;
                    case 'category':
                        if (!empty($value)) {
                            $query->where('category_id', $value);
                        }
                        break;
                    case 'tag':
                        if (!empty($value)) {
                            $query->whereHas('tags', function($q) use ($value) {
                                $q->where('name', $value);
                            });
                        }
                        break;
                    case 'author':
                        if (!empty($value)) {
                            $query->whereHas('author', function($q) use ($value) {
                                $q->where('name', $value);
                            });
                        }
                        break;
                }
            }
        }

        // 如果开启了 ES 并且存在搜索关键字，则优先走 ES
        $esSearch = null;
        if (!empty($filters['search']) && (bool)self::getConfig('es.enabled', false)) {
            $esSearch = ElasticService::searchPosts((string)$filters['search'], $page, $posts_per_page);
        }

        if (is_array($esSearch) && ($esSearch['used'] ?? false) && !empty($esSearch['ids'])) {
            // 使用 ES 返回的顺序与总数
            $total_count = (int)$esSearch['total'];
            $ids = $esSearch['ids'];

            // 拉取文章并根据 ES 命中顺序排序
            $posts = Post::whereIn('id', $ids)
                ->with(['authors', 'primaryAuthor'])
                ->get();

            $orderMap = array_flip($ids);
            $posts = $posts->sortBy(function ($post) use ($orderMap) {
                $pid = (int)$post->id;
                return $orderMap[$pid] ?? PHP_INT_MAX;
            })->values();

        } else {
            // 统计总文章数
            $total_count = $query->count();

            // 获取文章列表并预加载作者信息
            $posts = $query->orderByDesc('id')
                ->forPage($page, $posts_per_page)
                ->with(['authors', 'primaryAuthor'])
                ->get();
        }

        // 处理文章摘要
        try {
            self::processPostExcerpts($posts);
        } catch (CommonMarkException $e) {
            Log::error(trans('Failed to process post excerpts'), $e->getMessage());
            throw $e;
        }

        // 生成分页HTML
        $pagination_html = PaginationService::generatePagination(
            $page,
            $total_count,
            $posts_per_page,
            'index.page',
            [],
            10
        );

        // 构建结果数组
        $result = [
            'posts' => $posts,
            'pagination' => $pagination_html,
            'totalCount' => $total_count,
            'currentPage' => $page,
            'postsPerPage' => $posts_per_page
        ];

        // 缓存结果
        if (empty($filters)) {
            self::cacheResult($cache_key, $result);
        }

        return $result;
    }

    /**
     * 处理文章摘要
     *
     * @param Collection $posts 文章集合
     *
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
     *
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
     * @param string $key  缓存键名
     * @param mixed  $data 要缓存的数据
     */
    protected static function cacheResult(string $key, mixed $data): void
    {
        try {
            cache($key, $data, true);
        } catch (Throwable $e) {
            // 缓存设置失败时记录错误但不中断流程
            \support\Log::error('[cacheResult] Error caching data: ' . $e->getMessage());
        }
    }
}