<?php

namespace app\controller;

use support\Request;
use support\Response;
use app\service\BlogService;
use app\service\PaginationService;
use Webman\RateLimiter\Annotation\RateLimiter;
use app\model\Tag;
use support\Redis;
use app\service\EnhancedCacheService;
use app\service\PJAXHelper;

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
        
        // 使用PJAXHelper检测是否为PJAX请求
        $isPjax = PJAXHelper::isPJAX($request);
        
        // 为PJAX请求生成缓存键
        $cacheKey = null;
        if ($isPjax) {
            $cacheKey = sprintf('tag:%s:page:%d:sort:%s', $slug, $page, $sort);
        }

        // 构建筛选条件
        $filters = [
            'tag' => $this->sanitize($slug),
            'sort' => $sort
        ];

        // 获取文章列表
        $result = BlogService::getBlogPosts($page, $filters);

        // 标题
        $blog_title = BlogService::getBlogTitle();

        // 这里不再需要重复的PJAX检测，因为前面已经检测过了

        // 侧边栏
        $sidebar = \app\service\SidebarService::getSidebarContent($request, 'tag');

        // 动态选择模板：PJAX 返回片段，非 PJAX 返回完整页面
        $viewName = PJAXHelper::getViewName('tag/index', $isPjax);

        // 获取标签名称用于标题展示
        $tagModel = Tag::query()->where('slug', $slug)->first(['name', 'slug']);
        $tag_name = $tagModel ? (string)$tagModel->name : $slug;

        // 统一分页渲染
        $pagination_html = PaginationService::generatePagination(
            $page,
            (int)($result['totalCount'] ?? 0),
            (int)($result['postsPerPage'] ?? BlogService::getPostsPerPage()),
            't.page',
            ['slug' => $slug, 'sort' => $sort],
            10
        );

        // 创建带缓存的PJAX响应
        $resp = PJAXHelper::createResponse(
            $request,
            $viewName,
            [
                'page_title' => "标签: {$tag_name} - {$blog_title}",
                'tag_slug' => $slug,
                'tag_name' => $tag_name,
                'posts' => $result['posts'],
                'pagination' => $pagination_html,
                'totalCount' => $result['totalCount'] ?? 0,
                'sort' => $sort,
                'sidebar' => $sidebar
            ],
            $cacheKey,
            120,
            'tag'
        );
        
        return $resp;
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
        $enhancedCache = new EnhancedCacheService();
        $data = $enhancedCache->get($cacheKey, 'tag', null, 300);
        if ($data !== false) {
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
            $enhancedCache->set($cacheKey, json_encode($tags, JSON_UNESCAPED_UNICODE), 300, 'tag');
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