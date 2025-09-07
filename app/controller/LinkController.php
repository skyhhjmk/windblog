<?php
/*
 * 这里面有很多屎山
 */

namespace app\controller;

use support\Request;
use app\model\Link;
use app\service\PaginationService;
use function Symfony\Component\Translation\t;

class LinkController
{
    /**
     * 不需要登录的方法
     */
    protected array $noNeedLogin = ['index'];

    public function index(Request $request, int $page = 1)
    {
        $count = Link::count('*');

        $links_per_page = blog_config('links_per_page', 10, true);

        // 使用分页服务生成分页HTML
        $pagination_html = PaginationService::generatePagination(
            $page,
            $count,
            $links_per_page,
            'index.page',
            [],
            10
        );

        if (empty($request->get())) {
            $cached = cache('blog_links_page_' . $page);
            if ($cached) {
                return $cached;
            } else {
                $links = Link::orderByDesc('id')->forPage($page, $links_per_page)->get();
                cache('blog_links_page_' . $page . '_per_' . $links_per_page, $links, true);
            }
        } else {
            $links = Link::orderByDesc('id')->forPage($page, $links_per_page)->get();
        }

        return view('link/index', [
            'page_title' => blog_config('title', 'WindBlog', true) . ' - 链接',
            'links' => $links,
            'pagination' => $pagination_html,
        ]);
    }
}