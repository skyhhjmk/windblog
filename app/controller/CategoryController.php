<?php

namespace app\controller;

use app\annotation\EnableInstantFirstPaint;
use app\model\Category;
use app\service\BlogService;
use app\service\EnhancedCacheService;
use app\service\PaginationService;
use app\service\PJAXHelper;
use app\service\SidebarService;
use support\Request;
use support\Response;
use Webman\RateLimiter\Annotation\RateLimiter;

class CategoryController
{
    protected array $noNeedLogin = ['index', 'list'];

    #[EnableInstantFirstPaint]
    public function index(Request $request, string $slug, int $page = 1): Response
    {
        // 兼容 .html 形式
        if (is_string($slug) && str_ends_with($slug, '.html')) {
            $slug = substr($slug, 0, -5);
        }

        // 解析排序
        $sort = $request->get('sort', 'latest');
        $sort = in_array($sort, ['latest', 'hot']) ? $sort : 'latest';

        // 使用PJAXHelper检测是否为PJAX请求
        $isPjax = PJAXHelper::isPJAX($request);

        // 为PJAX请求生成缓存键
        $cacheKey = null;
        if ($isPjax) {
            $cacheKey = sprintf('category:%s:page:%d:sort:%s', $slug, $page, $sort);
        }

        // 构建筛选条件：传递 slug，由服务层适配
        $filters = [
            'category' => $this->sanitize($slug),
            'sort' => $sort,
        ];

        // 获取文章列表
        $result = BlogService::getBlogPosts($page, $filters);

        // 标题
        $blog_title = BlogService::getBlogTitle();

        // 侧边栏
        $sidebar = SidebarService::getSidebarContent($request, 'category');

        // 动态选择模板：PJAX 返回片段，非 PJAX 返回完整页面
        $viewName = PJAXHelper::getViewName('category/index', $isPjax);

        // 获取分类名称用于标题展示
        $categoryModel = Category::query()->where('slug', $slug)->first(['name', 'slug']);
        $category_name = $categoryModel ? (string) $categoryModel->name : $slug;

        // 使用项目统一的分页渲染，保证路由正确
        $pagination_html = PaginationService::generatePagination(
            $page,
            (int) ($result['totalCount'] ?? 0),
            (int) ($result['postsPerPage'] ?? BlogService::getPostsPerPage()),
            'c.page',
            ['slug' => $slug, 'sort' => $sort],
            10
        );

        // 创建带缓存的PJAX响应
        $resp = PJAXHelper::createResponse(
            $request,
            $viewName,
            [
                'page_title' => "分类: {$category_name} - {$blog_title}",
                'category_slug' => $slug,
                'category_name' => $category_name,
                'posts' => $result['posts'],
                'pagination' => $pagination_html,
                'totalCount' => $result['totalCount'] ?? 0,
                'sort' => $sort,
                'sidebar' => $sidebar,
            ],
            $cacheKey,
            120,
            'category'
        );

        return $resp;
    }

    /**
     * 分类汇总页：展示全部分类及各自文章数量（缓存）
     */
    public function list(Request $request): Response
    {
        $isPjax = ($request->header('X-PJAX') !== null)
            || (bool) $request->get('_pjax')
            || strtolower((string) $request->header('X-Requested-With')) === 'xmlhttprequest';

        $sidebar = SidebarService::getSidebarContent($request, 'category');

        $cacheKey = 'category_list_counts_v1';
        $enhancedCache = new EnhancedCacheService();
        $data = $enhancedCache->get($cacheKey, 'category', null, 300);
        if ($data !== false) {
            $categories = json_decode($data, true) ?: [];
        } else {
            $categories = Category::query()
                ->withCount('posts')
                ->ordered()
                ->get(['id', 'name', 'slug', 'description', 'sort_order'])
                ->map(fn ($c) => [
                    'id' => (int) $c->id,
                    'name' => (string) $c->name,
                    'slug' => (string) $c->slug,
                    'description' => (string) ($c->description ?? ''),
                    'count' => (int) ($c->posts_count ?? 0),
                ])->toArray();
            $enhancedCache->set($cacheKey, json_encode($categories, JSON_UNESCAPED_UNICODE), 300, 'category');
        }

        $blog_title = BlogService::getBlogTitle();
        $viewName = $isPjax ? 'category/list.content' : 'category/list';

        return view($viewName, [
            'page_title' => "全部分类 - {$blog_title}",
            'categories' => $categories,
            'sidebar' => $sidebar,
        ]);
    }

    protected function sanitize(string $value): string
    {
        $value = strip_tags($value);
        $value = trim($value);

        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
