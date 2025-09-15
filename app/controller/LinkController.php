<?php
/*
 * 这里面有很多屎山
 */

namespace app\controller;

use support\Request;
use app\model\Link;
use app\service\PaginationService;
use support\Response;
use Throwable;

/**
 * 链接广场控制器
 */
class LinkController
{
    /**
     * 不需要登录的方法
     */
    protected array $noNeedLogin = ['index', 'goto', 'info'];

    public function index(Request $request, int $page = 1)
    {
        $count = Link::count('*');

        $links_per_page = blog_config('links_per_page', 15, true);

        // 使用分页服务生成分页HTML
        $pagination_html = PaginationService::generatePagination(
            $page,
            $count,
            $links_per_page,
            'link.page',
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
            'page_title' => blog_config('title', 'WindBlog', true) . ' - 链接广场',
            'links' => $links,
            'pagination' => $pagination_html,
        ]);
    }

    /**
     * 链接跳转方法
     *
     * @param Request $request
     * @param int     $id 链接ID
     *
     * @return Response
     * @throws Throwable
     */
    public function goto(Request $request, int $id): Response
    {
        // 查找链接
        $link = Link::find($id);

        // 检查链接是否存在
        if (!$link) {
            return view('error/404', [
                'message' => '链接不存在'
            ]);
        }

        // 检查链接是否启用
        if (!$link->status) {
            return view('error/404', [
                'message' => '链接已被禁用'
            ]);
        }

        // 根据跳转类型处理
        switch ($link->redirect_type) {
            case 'direct':
                // 直接跳转
                return redirect($link->url, $link->target === '_blank' ? 302 : 301);

            case 'iframe':
                // 内嵌页面打开（这个需要在前端实现）
                return redirect($link->url);

            case 'goto':
            case 'info':
            default:
                // 使用中转页跳转
                return view('link/goto', [
                    'link' => $link,
                    'page_title' => blog_config('title', 'WindBlog', true) . ' - 外链跳转确认'
                ]);
        }
    }

    /**
     * 链接详情页面
     *
     * @param Request $request
     * @param int     $id 链接ID
     *
     * @return Response
     */
    public function info(Request $request, int $id): Response
    {
        // 查找链接
        $link = Link::find($id);

        // 检查链接是否存在
        if (!$link) {
            return view('error/404', [
                'message' => '链接不存在'
            ]);
        }

        // 检查链接是否启用
        if (!$link->status) {
            return view('error/404', [
                'message' => '链接已被禁用'
            ]);
        }

        return view('link/info', [
            'link' => $link,
            'page_title' => $link->name . ' - 链接详情'
        ]);
    }
}