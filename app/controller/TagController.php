<?php

namespace app\controller;

use support\Request;
use support\Response;
use app\service\BlogService;
use app\service\PaginationService;
use Webman\RateLimiter\Annotation\RateLimiter;
use app\model\Tag;
use support\Redis;

class TagController
{
    protected array $noNeedLogin = ['index', 'list'];

    #[RateLimiter(limit: 3, ttl: 3)]
    public function index(Request $request, string $slug, int $page = 1): Response
    {
        // 兼容 .html 形式
        if (is_string($slug) && str_ends_with($slug, '.html')) {
            $slug = substr($slug, 0, -5);
        }

        // 解析排序
        $sort = $request->get('sort', 'latest');
        $sort = in_array($sort, ['latest','hot']) ? $sort : 'latest';

        // 构建筛选条件
        $filters = [
            'tag' => $this->sanitize($slug),
            'sort' => $sort
        ];

        // 获取文章列表
        $result = BlogService::getBlogPosts($page, $filters);

        // 标题
        $blog_title = BlogService::getBlogTitle();

        // PJAX 检测
        $isPjax = ($request->header('X-PJAX') !== null)
            || (bool)$request->get('_pjax')
            || strtolower((string)$request->header('X-Requested-With')) === 'xmlhttprequest';

        // 侧边栏
        $sidebar = \app\service\SidebarService::getSidebarContent($request, 'tag');

        // 选择模板
        $viewName = $isPjax ? 'tag/index.content' : 'tag/index';

        // 统一分页渲染
        $pagination_html = PaginationService::generatePagination(
            $page,
            (int)($result['totalCount'] ?? 0),
            (int)($result['postsPerPage'] ?? BlogService::getPostsPerPage()),
            't.page',
            ['slug' => $slug, 'sort' => $sort],
            10
        );

        return view($viewName, [
            'page_title' => "标签: {$slug} - {$blog_title}",
            'tag_slug' => $slug,
            'posts' => $result['posts'],
            'pagination' => $pagination_html,
            'totalCount' => $result['totalCount'] ?? 0,
            'sort' => $sort,
            'sidebar' => $sidebar
        ]);
    }

    /**
     * 标签汇总页：展示全部标签及各自文章数量（缓存）
     */
    public function list(Request $request): Response
    {
        $isPjax = ($request->header('X-PJAX') !== null)
            || (bool)$request->get('_pjax')
            || strtolower((string)$request->header('X-Requested-With')) === 'xmlhttprequest';

        $sidebar = \app\service\SidebarService::getSidebarContent($request, 'tag');

        $cacheKey = 'tag_list_counts_v1';
        $data = Redis::connection('cache')->get($cacheKey);
        if ($data) {
            $tags = json_decode($data, true) ?: [];
        } else {
            $tags = Tag::query()
                ->withCount('posts')
                ->orderBy('id', 'asc')
                ->get(['id','name','slug','description'])
                ->map(fn($t) => [
                    'id' => (int)$t->id,
                    'name' => (string)$t->name,
                    'slug' => (string)$t->slug,
                    'description' => (string)($t->description ?? ''),
                    'count' => (int)($t->posts_count ?? 0)
                ])->toArray();
            Redis::connection('cache')->setex($cacheKey, 300, json_encode($tags, JSON_UNESCAPED_UNICODE));
        }

        $blog_title = BlogService::getBlogTitle();
        $viewName = $isPjax ? 'tag/list.content' : 'tag/list';
        return view($viewName, [
            'page_title' => "全部标签 - {$blog_title}",
            'tags' => $tags,
            'sidebar' => $sidebar
        ]);
    }

    protected function sanitize(string $value): string
    {
        $value = strip_tags($value);
        $value = trim($value);
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}