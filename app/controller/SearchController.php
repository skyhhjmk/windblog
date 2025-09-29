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

        // 构建筛选条件
        $filters = [];
        if (!empty($keyword)) {
            $filters['search'] = $keyword;
        }

        // 调用博客服务获取文章数据
        $result = BlogService::getBlogPosts($page, $filters);

        // 获取博客标题
        $blog_title = BlogService::getBlogTitle();

        // 获取侧边栏内容
        $sidebar = SidebarService::getSidebarContent($request, 'search');

        return view('search/index', [
            'page_title' => "搜索: {$keyword} - {$blog_title}",
            'posts' => $result['posts'],
            'pagination' => $result['pagination'],
            'sidebar' => $sidebar,
            'search_keyword' => $keyword
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

        if (empty($keyword)) {
            return response(json_encode(['success' => false, 'message' => '请输入搜索关键词']), 200)
                ->withHeader('Content-Type', 'application/json');
        }

        // 构建筛选条件
        $filters = ['search' => $keyword];

        try {
            // 获取第一页搜索结果
            $result = BlogService::getBlogPosts(1, $filters);

            // 将Collection转换为数组进行处理
            $postsArray = $result['posts']->toArray();

            $response = [
                'success' => true,
                'keyword' => $keyword,
                'results' => array_map(function ($post) {
                    return [
                        'id' => $post['id'],
                        'title' => $post['title'],
                        'excerpt' => $post['excerpt'],
                        'created_at' => $post['created_at'],
                        'author' => !empty($post['primary_author']) ?
                            (is_array($post['primary_author']) ? ($post['primary_author'][0]['nickname'] ?? '未知作者') : ($post['primary_author']['nickname'] ?? '未知作者')) :
                            (!empty($post['authors']) ? ($post['authors'][0]['nickname'] ?? '未知作者') : '未知作者'),
                        'url' => '/post/' . $post['id']
                    ];
                }, $postsArray),
                'total' => $result['totalCount'],
                'has_more' => $result['totalCount'] > count($postsArray),
                // 加入联想标题
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