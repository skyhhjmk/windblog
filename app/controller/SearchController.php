<?php

namespace app\controller;

use app\annotation\CSRFVerify;
use app\annotation\EnableInstantFirstPaint;
use app\helper\BreadcrumbHelper;
use app\model\Category;
use app\model\Tag;
use app\service\BlogService;
use app\service\ElasticService;
use app\service\PaginationService;
use app\service\PJAXHelper;
use app\service\SidebarService;
use Exception;
use League\CommonMark\Exception\CommonMarkException;
use support\Request;
use support\Response;
use Throwable;

/**
 * 搜索控制器
 */
class SearchController
{
    /**
     * 不需要登录的方法
     * index: 搜索结果页，公开访问
     * ajax: AJAX搜索接口，公开访问
     */
    protected array $noNeedLogin = ['index', 'ajax'];

    /**
     * 搜索页面
     *
     * @param Request $request 请求对象
     * @param int     $page    页码
     *
     * @return Response
     * @throws CommonMarkException
     * @throws Throwable
     */
    #[EnableInstantFirstPaint]
    public function index(Request $request, int $page = 1): Response
    {
        $keyword = $request->get('q', '');
        $type = strtolower((string) $request->get('type', 'all'));
        $sort = (string) $request->get('sort', '');
        $date = (string) $request->get('date', '');

        // 构建筛选条件
        $filters = [];
        if (!empty($keyword)) {
            $filters['search'] = $keyword;
        }
        if (in_array($type, ['post', 'tag', 'category'], true)) {
            $filters['type'] = $type;
        } else {
            $type = 'all';
        }

        // 调用博客服务获取文章数据
        // 传入排序到服务层；date 暂作为前端参数保留（服务层如需可后续支持）
        if (!empty($sort)) {
            $filters['sort'] = $sort;
        }
        $result = BlogService::getBlogPosts($page, $filters);

        // ES启用且指定类型时：在ES命中集合基础上按标签/分类名称进行二次过滤
        try {
            $esEnabled = (bool) BlogService::getConfig('es.enabled', false);
            $degraded = !empty(($result['esMeta']['signals']['degraded'] ?? false));
            if ($esEnabled && !empty($keyword) && in_array($type, ['tag', 'category'], true)) {
                $posts = $result['posts'];

                if (!$degraded) {
                    // 正常ES路径：用ES返回的标签/分类列表来筛选文章，空结果不算失败（将得到空集合）
                    $targets = $type === 'tag'
                        ? ElasticService::searchTags($keyword, 100)
                        : ElasticService::searchCategories($keyword, 100);

                    // 构建可匹配集合（id 优先，其次 name/slug，统一小写）
                    $idsSet = [];
                    $names = [];
                    $slugs = [];
                    foreach ($targets as $t) {
                        $id = (int) ($t['id'] ?? 0);
                        if ($id > 0) {
                            $idsSet[$id] = true;
                        }
                        $n = mb_strtolower((string) ($t['name'] ?? ''));
                        $s = mb_strtolower((string) ($t['slug'] ?? ''));
                        if ($n !== '') {
                            $names[$n] = true;
                        }
                        if ($s !== '') {
                            $slugs[$s] = true;
                        }
                    }

                    $filtered = $posts->filter(function ($post) use ($type, $idsSet, $names, $slugs) {
                        // 兼容模型对象与数组
                        $list = [];
                        if ($type === 'tag') {
                            $list = is_object($post) ? ($post->tags ?? []) : ($post['tags'] ?? []);
                        } else {
                            $list = is_object($post) ? ($post->categories ?? []) : ($post['categories'] ?? []);
                        }
                        if (!is_iterable($list)) {
                            return false;
                        }
                        foreach ($list as $item) {
                            // 关系项也可能是模型对象
                            $id = is_object($item) ? (int) ($item->id ?? 0) : (int) ($item['id'] ?? 0);
                            if ($id > 0 && isset($idsSet[$id])) {
                                return true;
                            }
                            $n = is_object($item) ? mb_strtolower((string) ($item->name ?? '')) : mb_strtolower((string) ($item['name'] ?? ''));
                            $s = is_object($item) ? mb_strtolower((string) ($item->slug ?? '')) : mb_strtolower((string) ($item['slug'] ?? ''));
                            if (($n !== '' && isset($names[$n])) || ($s !== '' && isset($slugs[$s]))) {
                                return true;
                            }
                        }

                        return false;
                    })->values();
                } else {
                    // 服务降级：回退到数据库匹配（名称/slug包含关键词）
                    $kwLower = mb_strtolower($keyword);
                    $filtered = $posts->filter(function ($post) use ($type, $kwLower) {
                        // 兼容模型对象与数组
                        $list = [];
                        if ($type === 'tag') {
                            $list = is_object($post) ? ($post->tags ?? []) : ($post['tags'] ?? []);
                        } else {
                            $list = is_object($post) ? ($post->categories ?? []) : ($post['categories'] ?? []);
                        }
                        if (!is_iterable($list)) {
                            return false;
                        }
                        foreach ($list as $item) {
                            // 关系项也可能是模型对象
                            $name = is_object($item) ? mb_strtolower((string) ($item->name ?? '')) : mb_strtolower((string) ($item['name'] ?? ''));
                            $slug = is_object($item) ? mb_strtolower((string) ($item->slug ?? '')) : mb_strtolower((string) ($item['slug'] ?? ''));
                            if (
                                ($name !== '' && mb_strpos($name, $kwLower) !== false) ||
                                ($slug !== '' && mb_strpos($slug, $kwLower) !== false)
                            ) {
                                return true;
                            }
                        }

                        return false;
                    })->values();
                }

                // 覆盖结果集与计数
                $result['posts'] = $filtered;
                $result['totalCount'] = count($filtered);
            }
        } catch (Throwable $e) {
            // 过滤过程中出错不影响主流程
        }

        // AMP 渲染
        if ($this->isAmpRequest($request)) {
            $siteUrl = $request->host();
            // canonical 保留原查询
            $canonicalUrl = 'https://' . $siteUrl . '/search?q=' . rawurlencode($keyword);
            $postsPerPage = (int) ($result['postsPerPage'] ?? BlogService::getPostsPerPage());
            $totalCount = (int) ($result['totalCount'] ?? 0);
            $totalPages = max(1, (int) ceil($totalCount / max(1, $postsPerPage)));

            return view('search/index.amp', [
                'page_title' => "搜索: {$keyword}",
                'posts' => $result['posts'],
                'amp_pagination' => [
                    'current_page' => $page,
                    'total_pages' => $totalPages,
                ],
                'search_keyword' => $keyword,
                'search_type' => $type,
                'search_sort' => $sort,
                'search_date' => $date,
                'canonical_url' => $canonicalUrl,
                'request' => $request,
            ]);
        }

        // 获取博客标题
        $blog_title = BlogService::getBlogTitle();

        // 获取侧边栏内容（PJAX 与非 PJAX 均获取）
        $sidebar = SidebarService::getSidebarContent($request, 'search');
        $suggestTitles = !empty($keyword) ? ElasticService::suggestTitles($keyword, 5) : [];

        // 为搜索页生成正确的分页HTML，携带查询参数，避免跳转到首页分页
        $pagination_html = PaginationService::generatePagination(
            $page,
            (int) ($result['totalCount'] ?? 0),
            (int) ($result['postsPerPage'] ?? BlogService::getPostsPerPage()),
            'search.page',
            [
                'q' => $keyword,
                'type' => $type,
                'sort' => $sort,
                'date' => $date,
            ]
        );

        // 生成面包屑导航
        $breadcrumbs = BreadcrumbHelper::forSearch($keyword);

        // 动态选择模板：PJAX 返回片段，非 PJAX 返回完整页面
        $viewName = PJAXHelper::isPJAX($request) ? 'search/index.content' : 'search/index';

        return view($viewName, [
            'page_title' => "搜索: {$keyword} - {$blog_title}",
            'posts' => $result['posts'],
            'pagination' => $pagination_html,
            'sidebar' => $sidebar,
            'search_keyword' => $keyword,
            'search_type' => $type,
            'search_sort' => $sort,
            'search_date' => $date,
            'esMeta' => $result['esMeta'] ?? [],
            'suggest_titles' => $suggestTitles,
            'totalCount' => $result['totalCount'] ?? 0,
            'breadcrumbs' => $breadcrumbs,
        ]);
    }

    protected function isAmpRequest(Request $request): bool
    {
        if ($request->get('amp') === '1' || $request->get('amp') === 'true') {
            return true;
        }
        $path = $request->path();

        return str_starts_with($path, '/amp/');
    }

    /**
     * AJAX搜索接口
     *
     * @param Request $request 请求对象
     *
     * @return Response
     * @throws CommonMarkException
     * @throws Throwable
     */
    #[CSRFVerify]
    public function ajax(Request $request): Response
    {
        $keyword = $request->get('q', '');
        $type = strtolower((string) $request->get('type', 'all'));
        if (!in_array($type, ['all', 'post', 'tag', 'category'], true)) {
            $type = 'all';
        }

        if (empty($keyword)) {
            return response(json_encode(['success' => false, 'message' => '请输入搜索关键词']), 200)
                ->withHeader('Content-Type', 'application/json');
        }

        // 构建筛选条件
        $filters = ['search' => $keyword];
        if ($type !== 'all') {
            $filters['type'] = $type;
        }

        try {
            // 获取第一页文章搜索结果
            $result = BlogService::getBlogPosts(1, ['search' => $keyword]);
            $postsArray = $result['posts']->toArray();
            $hlMap = $result['esMeta']['highlights'] ?? [];
            $signals = $result['esMeta']['signals'] ?? [];

            // 构造文章类条目（不返回长摘要详情，仅附带类型与分类/标签）
            $postItems = array_map(function ($post) use ($hlMap) {
                $pid = (int) $post['id'];
                $titleHl = $hlMap[$pid]['title'][0] ?? null;
                // 分类与标签（如存在）
                $categories = [];
                if (!empty($post['categories']) && is_array($post['categories'])) {
                    foreach ($post['categories'] as $c) {
                        $categories[] = ['name' => $c['name'] ?? '', 'slug' => $c['slug'] ?? ''];
                    }
                }
                $tags = [];
                if (!empty($post['tags']) && is_array($post['tags'])) {
                    foreach ($post['tags'] as $t) {
                        $tags[] = ['name' => $t['name'] ?? '', 'slug' => $t['slug'] ?? ''];
                    }
                }

                return [
                    'type' => 'post',
                    'id' => $post['id'],
                    'title' => $post['title'],
                    'highlight_title' => $titleHl,
                    'url' => '/post/' . $post['slug'] . '.html',
                    'categories' => $categories,
                    'tags' => $tags,
                ];
            }, $postsArray);

            // 标签与分类匹配：ES启用时调用 ElasticService 专用方法，未启用时回退DB模糊匹配
            $mixedItems = $postItems;
            $esEnabled = (bool) BlogService::getConfig('es.enabled', false);
            if ($esEnabled) {
                // 仅在 ES 服务降级时才对标签进行数据库回退；空结果不视为失败
                if ($type === 'all' || $type === 'tag') {
                    $tags = ElasticService::searchTags($keyword, 10);
                    $degraded = !empty($signals['degraded']);
                    if ($degraded && empty($tags)) {
                        try {
                            $kwLower = mb_strtolower($keyword);
                            $tagModel = Tag::where(function ($q) use ($kwLower) {
                                $q->whereRaw('LOWER(name) like ?', ['%' . $kwLower . '%'])
                                    ->orWhereRaw('LOWER(slug) like ?', ['%' . $kwLower . '%']);
                            })->limit(10)->get(['id', 'name', 'slug']);
                            foreach ($tagModel as $t) {
                                $tags[] = ['id' => (int) $t->id, 'name' => (string) $t->name, 'slug' => (string) $t->slug];
                            }
                        } catch (Throwable $e) {
                        }
                    }
                    foreach ($tags as $t) {
                        $mixedItems[] = [
                            'type' => 'tag',
                            'id' => (int) ($t['id'] ?? 0),
                            'title' => (string) ($t['name'] ?? ''),
                            'url' => '/tag/' . (string) ($t['slug'] ?? '') . '.html',
                        ];
                    }
                }
                // 仅在 ES 服务降级时才对分类进行数据库回退；空结果不视为失败
                if ($type === 'all' || $type === 'category') {
                    $cats = ElasticService::searchCategories($keyword, 10);
                    $degraded = !empty($signals['degraded']);
                    if ($degraded && empty($cats)) {
                        try {
                            $kwLower = mb_strtolower($keyword);
                            $catModel = Category::where(function ($q) use ($kwLower) {
                                $q->whereRaw('LOWER(name) like ?', ['%' . $kwLower . '%'])
                                    ->orWhereRaw('LOWER(slug) like ?', ['%' . $kwLower . '%']);
                            })->limit(10)->get(['id', 'name', 'slug']);
                            foreach ($catModel as $c) {
                                $cats[] = ['id' => (int) $c->id, 'name' => (string) $c->name, 'slug' => (string) $c->slug];
                            }
                        } catch (Throwable $e) {
                        }
                    }
                    foreach ($cats as $c) {
                        $mixedItems[] = [
                            'type' => 'category',
                            'id' => (int) ($c['id'] ?? 0),
                            'title' => (string) ($c['name'] ?? ''),
                            'url' => '/category/' . (string) ($c['slug'] ?? '') . '.html',
                        ];
                    }
                }
            } else {
                // ES未启用：沿用DB模糊匹配
                if ($type === 'all' || $type === 'tag') {
                    try {
                        $kwLower = mb_strtolower($keyword);
                        $tagModel = Tag::where(function ($q) use ($kwLower) {
                            $q->whereRaw('LOWER(name) like ?', ['%' . $kwLower . '%'])
                                ->orWhereRaw('LOWER(slug) like ?', ['%' . $kwLower . '%']);
                        })->limit(10)->get(['id', 'name', 'slug']);
                        foreach ($tagModel as $t) {
                            $mixedItems[] = [
                                'type' => 'tag',
                                'id' => (int) $t->id,
                                'title' => (string) $t->name,
                                'url' => '/tag/' . (string) $t->slug . '.html',
                            ];
                        }
                    } catch (Throwable $e) {
                    }
                }
                if ($type === 'all' || $type === 'category') {
                    try {
                        $kwLower = mb_strtolower($keyword);
                        $catModel = Category::where(function ($q) use ($kwLower) {
                            $q->whereRaw('LOWER(name) like ?', ['%' . $kwLower . '%'])
                                ->orWhereRaw('LOWER(slug) like ?', ['%' . $kwLower . '%']);
                        })->limit(10)->get(['id', 'name', 'slug']);
                        foreach ($catModel as $c) {
                            $mixedItems[] = [
                                'type' => 'category',
                                'id' => (int) $c->id,
                                'title' => (string) $c->name,
                                'url' => '/category/' . (string) $c->slug . '.html',
                            ];
                        }
                    } catch (Throwable $e) {
                    }
                }
            }

            // 若指定了单一类型，则按类型过滤
            if ($type !== 'all') {
                $mixedItems = array_values(array_filter($mixedItems, function ($it) use ($type) {
                    return $it['type'] === $type;
                }));
            }

            $response = [
                'success' => true,
                'keyword' => $keyword,
                'results' => $mixedItems,
                'total' => count($mixedItems),
                'has_more' => false,
                'signals' => $signals,
                'tips' => array_values(array_filter([
                    (!empty($signals['synonym']) ? '已应用同义词扩展匹配' : null),
                    (!empty($signals['highlighted']) ? '已为关键词提供高亮' : null),
                    (!empty($signals['analyzer']) ? ('使用分词器: ' . $signals['analyzer']) : null),
                    (!empty($signals['degraded']) ? '服务降级：ES 搜索优化失效' : null),
                ])),
                'titles' => ElasticService::suggestTitles($keyword, 10),
            ];

            return response(json_encode($response, JSON_UNESCAPED_UNICODE), 200)
                ->withHeader('Content-Type', 'application/json');

        } catch (Exception $e) {
            error_log('SearchController ajax error: ' . $e->getMessage());

            return response(json_encode(['success' => false, 'message' => '搜索失败: ' . $e->getMessage()]), 500)
                ->withHeader('Content-Type', 'application/json');
        }
    }
}
