<?php

namespace app\controller;

use app\annotation\EnableInstantFirstPaint;
use app\helper\BreadcrumbHelper;
use app\model\Tag;
use app\service\BlogService;
use app\service\EnhancedCacheService;
use app\service\PaginationService;
use app\service\PJAXHelper;
use app\service\SidebarService;
use support\Request;
use support\Response;

class TagController
{
    /**
     * 不需要登录的方法
     * index: 标签文章列表页，公开访问
     * list: 全部标签汇总页，公开访问
     */
    protected array $noNeedLogin = ['index', 'list'];

    #[EnableInstantFirstPaint]
    public function index(Request $request, string $slug, int $page = 1): Response
    {
        // 兼容 .html 形式
        if (is_string($slug) && str_ends_with($slug, '.html')) {
            $slug = substr($slug, 0, -5);
        }

        // URL解码 slug
        $slug = urldecode($slug);

        // 解析排序
        $sort = $request->get('sort', 'latest');
        $sort = in_array($sort, ['latest', 'hot']) ? $sort : 'latest';

        // 构建筛选条件
        $filters = [
            'tag' => $this->sanitizeSlug($slug),
            'sort' => $sort,
        ];

        // 获取文章列表
        $result = BlogService::getBlogPosts($page, $filters);

        // 获取标签名称用于标题展示
        $tagModel = Tag::query()->where('slug', $slug)->first(['name', 'slug']);
        $tag_name = $tagModel ? (string) $tagModel->name : $slug;

        // 生成面包屑导航
        $breadcrumbs = BreadcrumbHelper::forTag($tagModel, false);

        // AMP 渲染
        if ($this->isAmpRequest($request)) {
            $siteUrl = $request->host();
            $canonicalUrl = 'https://' . $siteUrl . '/tag/' . $slug . '.html';
            $postsPerPage = (int) ($result['postsPerPage'] ?? BlogService::getPostsPerPage());
            $totalCount = (int) ($result['totalCount'] ?? 0);
            $totalPages = max(1, (int) ceil($totalCount / max(1, $postsPerPage)));

            return view('tag/index.amp', [
                'page_title' => "标签: {$tag_name}",
                'tag_slug' => $slug,
                'tag_name' => $tag_name,
                'posts' => $result['posts'],
                'amp_pagination' => [
                    'current_page' => $page,
                    'total_pages' => $totalPages,
                ],
                'sort' => $sort,
                'breadcrumbs' => $breadcrumbs,
                'canonical_url' => $canonicalUrl,
                'request' => $request,
            ]);
        }

        // 使用PJAXHelper检测是否为PJAX请求
        $isPjax = PJAXHelper::isPJAX($request);

        // 为PJAX请求生成缓存键
        $cacheKey = null;
        if ($isPjax) {
            $cacheKey = sprintf('tag:%s:page:%d:sort:%s', $slug, $page, $sort);
        }

        // 标题
        $blog_title = BlogService::getBlogTitle();

        // 侧边栏
        $sidebar = SidebarService::getSidebarContent($request, 'tag');

        // 动态选择模板：PJAX 返回片段，非 PJAX 返回完整页面
        $viewName = PJAXHelper::getViewName('tag/index', $isPjax);

        // 统一分页渲染
        $pagination_html = PaginationService::generatePagination(
            $page,
            (int) ($result['totalCount'] ?? 0),
            (int) ($result['postsPerPage'] ?? BlogService::getPostsPerPage()),
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
                'sidebar' => $sidebar,
                'breadcrumbs' => $breadcrumbs,
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
        $cacheKey = 'tag_list_counts_v1';
        $enhancedCache = new EnhancedCacheService();
        $data = $enhancedCache->get($cacheKey, 'tag', null, 300);
        if ($data !== false) {
            $tags = json_decode($data, true) ?: [];
        }
        if (!isset($tags) || !is_array($tags) || count($tags) === 0) {
            // 缓存未命中或解析失败时回源DB
            $tags = Tag::query()
                ->withCount('posts')
                ->orderBy('id', 'asc')
                ->get(['id', 'name', 'slug', 'description'])
                ->map(fn ($t) => [
                    'id' => (int) $t->id,
                    'name' => (string) $t->name,
                    'slug' => (string) $t->slug,
                    'description' => (string) ($t->description ?? ''),
                    'count' => (int) ($t->posts_count ?? 0),
                ])->toArray();
            $enhancedCache->set($cacheKey, json_encode($tags, JSON_UNESCAPED_UNICODE), 300, 'tag');
        }

        $blog_title = BlogService::getBlogTitle();

        // AMP 渲染
        if ($this->isAmpRequest($request)) {
            $siteUrl = $request->host();
            $canonicalUrl = 'https://' . $siteUrl . '/tag';
            $breadcrumbs = BreadcrumbHelper::forTag(null, true);

            return view('tag/list.amp', [
                'page_title' => "全部标签 - {$blog_title}",
                'tags' => $tags,
                'breadcrumbs' => $breadcrumbs,
                'canonical_url' => $canonicalUrl,
                'request' => $request,
            ]);
        }

        $sidebar = SidebarService::getSidebarContent($request, 'tag');
        $viewName = PJAXHelper::isPJAX($request) ? 'tag/list.content' : 'tag/list';

        // 生成面包屑导航（标签列表页）
        $breadcrumbs = BreadcrumbHelper::forTag(null, true);

        return view($viewName, [
            'page_title' => "全部标签 - {$blog_title}",
            'tags' => $tags,
            'sidebar' => $sidebar,
            'breadcrumbs' => $breadcrumbs,
        ]);
    }

    protected function sanitize(string $value): string
    {
        // 通用清洗：移除标签与首尾空白
        return trim(strip_tags($value));
    }

    protected function sanitizeSlug(string $value): string
    {
        // 对 slug 仅做基础清洗，不做转义，避免中文被实体化
        return trim(strip_tags($value));
    }

    protected function isAmpRequest(Request $request): bool
    {
        if ($request->get('amp') === '1' || $request->get('amp') === 'true') {
            return true;
        }
        $path = $request->path();

        return str_starts_with($path, '/amp/');
    }
}
