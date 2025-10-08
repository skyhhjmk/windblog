<?php

namespace app\controller;

use app\service\SidebarService;
use support\Request;
use support\Response;
use app\service\BlogService;
use Throwable;
use League\CommonMark\Exception\CommonMarkException;
use app\service\ElasticService;

/**
 * 搜索控制器
 */
class SearchController
{
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
    public function index(Request $request, int $page = 1): Response
    {
        $keyword = $request->get('q', '');
        $type = strtolower((string)$request->get('type', 'all'));

        // 构建筛选条件
        $filters = [];
        if (!empty($keyword)) {
            $filters['search'] = $keyword;
        }
        if (in_array($type, ['post','tag','category'], true)) {
            $filters['type'] = $type;
        } else {
            $type = 'all';
        }

        // 调用博客服务获取文章数据
        $result = BlogService::getBlogPosts($page, $filters);

        // 获取博客标题
        $blog_title = BlogService::getBlogTitle();

        // PJAX 优化：检测是否为 PJAX 请求（兼容 header/_pjax 参数/XHR）
        $isPjax = ($request->header('X-PJAX') !== null)
            || (bool)$request->get('_pjax')
            || strtolower((string)$request->header('X-Requested-With')) === 'xmlhttprequest';


        // 获取侧边栏内容（PJAX 与非 PJAX 均获取）
        $sidebar = SidebarService::getSidebarContent($request, 'search');
        $suggestTitles = !empty($keyword) ? ElasticService::suggestTitles($keyword, 5) : [];

        // 动态选择模板
        $viewName = $isPjax ? 'search/index.content' : 'search/index';

        // 动态选择模板：PJAX 返回片段，非 PJAX 返回完整页面
        $viewName = $isPjax ? 'search/index.content' : 'search/index';
        return view($viewName, [
            'page_title' => "搜索: {$keyword} - {$blog_title}",
            'posts' => $result['posts'],
            'pagination' => $result['pagination'],
            'sidebar' => $sidebar,
            'search_keyword' => $keyword,
            'search_type' => $type,
            'esMeta' => $result['esMeta'] ?? [],
            'suggest_titles' => $suggestTitles,
            'totalCount' => $result['totalCount'] ?? 0
        ]);
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
    public function ajax(Request $request): Response
    {
        $keyword = $request->get('q', '');
        $type = strtolower((string)$request->get('type', 'all'));
        if (!in_array($type, ['all','post','tag','category'], true)) {
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
                $pid = (int)$post['id'];
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

            // 标签与分类匹配（最小实现，名称模糊匹配）
            $mixedItems = $postItems;
            if ($type === 'all' || $type === 'tag') {
                try {
                    $tagModel = \app\model\Tag::where('name', 'like', '%' . $keyword . '%')
                        ->orWhere('slug', 'like', '%' . $keyword . '%')
                        ->limit(10)->get(['id','name','slug']);
                    foreach ($tagModel as $t) {
                        $mixedItems[] = [
                            'type' => 'tag',
                            'id' => (int)$t->id,
                            'title' => (string)$t->name,
                            'url' => '/t/' . (string)$t->slug,
                        ];
                    }
                } catch (\Throwable $e) {}
            }
            if ($type === 'all' || $type === 'category') {
                try {
                    $catModel = \app\model\Category::where('name', 'like', '%' . $keyword . '%')
                        ->orWhere('slug', 'like', '%' . $keyword . '%')
                        ->limit(10)->get(['id','name','slug']);
                    foreach ($catModel as $c) {
                        $mixedItems[] = [
                            'type' => 'category',
                            'id' => (int)$c->id,
                            'title' => (string)$c->name,
                            'url' => '/c/' . (string)$c->slug,
                        ];
                    }
                } catch (\Throwable $e) {}
            }

            // 若指定了单一类型，则按类型过滤
            if ($type !== 'all') {
                $mixedItems = array_values(array_filter($mixedItems, function($it) use ($type) {
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
                ])),
                'titles' => ElasticService::suggestTitles($keyword, 10),
            ];

            return response(json_encode($response, JSON_UNESCAPED_UNICODE), 200)
                ->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            error_log('SearchController ajax error: ' . $e->getMessage());
            return response(json_encode(['success' => false, 'message' => '搜索失败: ' . $e->getMessage()]), 500)
                ->withHeader('Content-Type', 'application/json');
        }
    }
}