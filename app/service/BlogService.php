<?php

namespace app\service;

use app\model\Post;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Exception\CommonMarkException;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\TaskList\TaskListExtension;
use League\CommonMark\MarkdownConverter;
use support\Log;
use Throwable;

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
        $per = is_numeric($val) ? (int) $val : 10;
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
        $cache_key = self::generateCacheKey($page, $posts_per_page, $filters);

        // 尝试从缓存获取
        if (empty($filters)) {
            $cached = self::getFromCache($cache_key);
            if ($cached) {
                return $cached;
            }
        }

        // 尝试使用 ES 搜索
        $esSearch = self::tryElasticSearch($filters, $page, $posts_per_page);

        // 获取文章数据
        if (self::shouldUseElasticResult($esSearch)) {
            [$posts, $total_count] = self::fetchPostsByElastic($esSearch);
        } else {
            $query = self::buildBaseQuery();
            self::applyFilters($query, $filters);
            [$posts, $total_count] = self::fetchPostsByQuery($query, $page, $posts_per_page, $filters);
        }

        // 处理文章摘要
        self::ensurePostExcerpts($posts);

        // 构建并返回结果
        $result = self::buildResult($posts, $total_count, $page, $posts_per_page, $esSearch, $filters);

        // 缓存结果
        if (empty($filters)) {
            self::cacheResult($cache_key, $result);
        }

        return $result;
    }

    /**
     * 生成缓存键
     */
    protected static function generateCacheKey(int $page, int $perPage, array $filters): string
    {
        $filter_key = empty($filters) ? '' : '_' . hash('crc32b', serialize($filters));

        return 'blog_posts_page_' . $page . '_per_' . $perPage . $filter_key;
    }

    /**
     * 构建基础查询
     *
     * @return Builder 基础查询构建器
     */
    protected static function buildBaseQuery(): Builder
    {
        return Post::where('status', 'published')
            ->where(function (Builder $q) {
                $q->whereNull('published_at')
                    ->orWhere('published_at', '<=', utc_now());
            });
    }

    /**
     * 应用筛选条件
     */
    protected static function applyFilters($query, array $filters): void
    {
        if (empty($filters)) {
            return;
        }

        foreach ($filters as $key => $value) {
            if (empty($value)) {
                continue;
            }

            match ($key) {
                'search' => self::applySearchFilter($query, $value),
                'category' => self::applyCategoryFilter($query, $value),
                'tag' => self::applyTagFilter($query, $value),
                'author' => self::applyAuthorFilter($query, $value),
                default => null,
            };
        }
    }

    /**
     * 应用搜索筛选
     */
    protected static function applySearchFilter($query, string $value): void
    {
        $searchTermLower = mb_strtolower(addcslashes($value, '%_\\'));
        $query->where(function ($q) use ($searchTermLower) {
            $q->whereRaw('LOWER(title) like ?', ['%' . $searchTermLower . '%'])
                ->orWhereRaw('LOWER(content) like ?', ['%' . $searchTermLower . '%'])
                ->orWhereRaw('LOWER(excerpt) like ?', ['%' . $searchTermLower . '%']);
        });
    }

    /**
     * 应用分类筛选
     */
    protected static function applyCategoryFilter($query, string $value): void
    {
        $query->whereHas('categories', function ($q) use ($value) {
            $q->where('categories.slug', $value);
        });
    }

    /**
     * 应用标签筛选
     */
    protected static function applyTagFilter($query, string $value): void
    {
        $query->whereHas('tags', function ($q) use ($value) {
            $q->where('tags.slug', $value);
        });
    }

    /**
     * 应用作者筛选
     */
    protected static function applyAuthorFilter($query, string $value): void
    {
        $query->whereHas('authors', function ($q) use ($value) {
            $q->where('name', $value);
        });
    }

    /**
     * 尝试使用 ElasticSearch
     */
    protected static function tryElasticSearch(array $filters, int $page, int $perPage): ?array
    {
        if (empty($filters['search'])) {
            return null;
        }

        if (!(bool) self::getConfig('es.enabled', false)) {
            return null;
        }

        return ElasticService::searchPosts((string) $filters['search'], $page, $perPage);
    }

    /**
     * 判断是否应使用 ES 结果
     */
    protected static function shouldUseElasticResult(?array $esSearch): bool
    {
        return is_array($esSearch)
            && ($esSearch['used'] ?? false)
            && !empty($esSearch['ids']);
    }

    /**
     * 通过 ES 结果获取文章
     */
    protected static function fetchPostsByElastic(array $esSearch): array
    {
        $total_count = (int) $esSearch['total'];
        $ids = $esSearch['ids'];

        $posts = Post::whereIn('id', $ids)
            ->with(['authors', 'primaryAuthor', 'categories:id,name,slug', 'tags:id,name,slug'])
            ->get();

        // 按 ES 返回的顺序排序
        $orderMap = array_flip($ids);
        $posts = $posts->sortBy(function ($post) use ($orderMap) {
            return $orderMap[(int) $post->id] ?? PHP_INT_MAX;
        })->values();

        return [$posts, $total_count];
    }

    /**
     * 通过查询获取文章
     */
    protected static function fetchPostsByQuery($query, int $page, int $perPage, array $filters): array
    {
        $total_count = $query->count();

        self::applySearchOrdering($query, $filters);
        self::applySorting($query, $filters);

        $posts = $query
            ->forPage($page, $perPage)
            ->with(['authors', 'primaryAuthor', 'categories:id,name,slug', 'tags:id,name,slug'])
            ->get();

        return [$posts, $total_count];
    }

    /**
     * 应用搜索排序
     */
    protected static function applySearchOrdering($query, array $filters): void
    {
        if (empty($filters['search'])) {
            return;
        }

        $searchTerm = '%' . mb_strtolower(addcslashes((string) $filters['search'], '%_\\')) . '%';
        $query->orderByRaw(
            'CASE WHEN LOWER(title) LIKE ? THEN 0 WHEN LOWER(excerpt) LIKE ? THEN 1 WHEN LOWER(content) LIKE ? THEN 2 ELSE 3 END',
            [$searchTerm, $searchTerm, $searchTerm]
        );
    }

    /**
     * 应用排序规则
     */
    protected static function applySorting($query, array $filters): void
    {
        $sort = $filters['sort'] ?? 'latest';

        if ($sort === 'hot') {
            $query->withCount('comments')
                ->orderByDesc('featured')
                ->orderByDesc('comments_count')
                ->orderByDesc('id');
        } else {
            $query->orderByDesc('featured')->orderByDesc('id');
        }
    }

    /**
     * 确保文章有摘要
     *
     * @throws CommonMarkException
     */
    protected static function ensurePostExcerpts(Collection $posts): void
    {
        // 仅当集合中至少有一篇文章缺少摘要且存在内容时才进行处理，避免不必要的开销
        $needsProcessing = $posts->contains(function ($post) {
            return empty($post->excerpt)
                && empty($post->ai_summary)
                && !empty($post->content);
        });

        if (!$needsProcessing) {
            return;
        }

        try {
            self::processPostExcerpts($posts);
        } catch (CommonMarkException $e) {
            Log::error('Failed to process post excerpts: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 构建返回结果
     */
    protected static function buildResult(
        Collection $posts,
        int $totalCount,
        int $page,
        int $perPage,
        ?array $esSearch,
        array $filters
    ): array {
        $pagination_html = PaginationService::generatePagination(
            $page,
            $totalCount,
            $perPage,
            'index.page',
            [],
            10
        );

        $esMeta = self::buildEsMeta($esSearch, $filters);

        return [
            'posts' => $posts,
            'pagination' => $pagination_html,
            'totalCount' => $totalCount,
            'currentPage' => $page,
            'postsPerPage' => $perPage,
            'esMeta' => $esMeta,
        ];
    }

    /**
     * 构建 ES 元数据
     */
    protected static function buildEsMeta(?array $esSearch, array $filters): array
    {
        if (is_array($esSearch) && ($esSearch['used'] ?? false)) {
            return [
                'highlights' => $esSearch['highlights'] ?? [],
                'signals' => $esSearch['signals'] ?? [],
            ];
        }

        if (!empty($filters['search']) && (bool) self::getConfig('es.enabled', false)) {
            return ['signals' => ['degraded' => true]];
        }

        return [];
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
        // 创建MarkdownConverter实例
        $config = [
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 10,
            'renderer' => [
                'soft_break' => '<br />
',
            ],
            'commonmark' => [
                'enable_em' => true,
                'enable_strong' => true,
                'use_asterisk' => true,
                'use_underscore' => true,
                'unordered_list_markers' => ['-', '+', '*'],
            ],
        ];

        $environment = new Environment($config);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new AutolinkExtension());
        $environment->addExtension(new StrikethroughExtension());
        $environment->addExtension(new TableExtension());
        $environment->addExtension(new TaskListExtension());

        $converter = new MarkdownConverter($environment);

        foreach ($posts as $post) {
            if (empty($post->excerpt)) {
                // 使用CommonMarkConverter将内容转换为HTML，再删除HTML标签生成摘要
                $html = $converter->convert($post->content);
                $excerpt = mb_substr(strip_tags((string) $html), 0, 200, 'UTF-8');

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
            Log::error('[cacheResult] Error caching data: ' . $e->getMessage());
        }
    }
}
