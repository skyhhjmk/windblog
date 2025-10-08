<?php

namespace app\controller;

use support\Request;
use support\Response;
use app\service\BlogService;
use app\service\PaginationService;
use Webman\RateLimiter\Annotation\RateLimiter;
use app\model\Category;
use support\Redis;

class CategoryController
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

        // 构建筛选条件：传递 slug，由服务层适配
        $filters = [
            'category' => $this->sanitize($slug),
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
        $sidebar = \app\service\SidebarService::getSidebarContent($request, 'category');

        // 选择模板
        $viewName = $isPjax ? 'category/index.content' : 'category/index';

        // 使用项目统一的分页渲染，保证路由正确
        $pagination_html = PaginationService::generatePagination(
            $page,
            (int)($result['totalCount'] ?? 0),
            (int)($result['postsPerPage'] ?? BlogService::getPostsPerPage()),
            'c.page',
            ['slug' => $slug, 'sort' => $sort],
            10
        );

        return view($viewName, [
            'page_title' => "分类: {$slug} - {$blog_title}",
            'category_slug' => $slug,
            'posts' => $result['posts'],
            'pagination' => $pagination_html,
            'totalCount' => $result['totalCount'] ?? 0,
            'sort' => $sort,
            'sidebar' => $sidebar
        ]);
    }

    /**
     * 分类汇总页：展示全部分类及各自文章数量（缓存）
     */
    public function list(Request $request): Response
    {
        $isPjax = ($request->header('X-PJAX') !== null)
            || (bool)$request->get('_pjax')
            || strtolower((string)$request->header('X-Requested-With')) === 'xmlhttprequest';

        $sidebar = \app\service\SidebarService::getSidebarContent($request, 'category');

        $cacheKey = 'category_list_counts_v1';
        $data = Redis::connection('cache')->get($cacheKey);
        if ($data) {
            $categories = json_decode($data, true) ?: [];
        } else {
            $categories = Category::query()
                ->withCount('posts')
                ->ordered()
                ->get(['id','name','slug','description','sort_order'])
                ->map(fn($c) => [
                    'id' => (int)$c->id,
                    'name' => (string)$c->name,
                    'slug' => (string)$c->slug,
                    'description' => (string)($c->description ?? ''),
                    'count' => (int)($c->posts_count ?? 0)
                ])->toArray();
            Redis::connection('cache')->setex($cacheKey, 300, json_encode($categories, JSON_UNESCAPED_UNICODE));
        }

        $blog_title = BlogService::getBlogTitle();
        $viewName = $isPjax ? 'category/list.content' : 'category/list';
        return view($viewName, [
            'page_title' => "全部分类 - {$blog_title}",
            'categories' => $categories,
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