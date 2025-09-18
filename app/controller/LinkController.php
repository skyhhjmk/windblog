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
    protected array $noNeedLogin = ['index', 'goto', 'info', 'request'];

    public function index(Request $request, int $page = 1)
    {
        $count = Link::where('status', 'true')->count('*');

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
                $links = Link::where('status', 'true')->orderByDesc('id')->forPage($page, $links_per_page)->get();
                cache('blog_links_page_' . $page . '_per_' . $links_per_page, $links, true);
            }
        } else {
            $links = Link::where('status', 'true')->orderByDesc('id')->forPage($page, $links_per_page)->get();
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

    /**
     * 申请友链页面
     *
     * @param Request $request
     *
     * @return Response
     * @throws Throwable
     */
    public function request(Request $request): Response
    {
        // 如果是POST请求，处理表单提交
        if ($request->method() === 'POST') {
            // 获取表单数据
            $name = $request->post('name', '');
            $url = $request->post('url', '');
            $description = $request->post('description', '');
            $contact = $request->post('contact', '');
            $supportsWindConnect = $request->post('supports_wind_connect', false);
            $allowsCrawling = $request->post('allows_crawling', false);
            $captcha = $request->post('captcha', '');

            // 简单验证
            if (empty($name) || empty($url) || empty($description)) {
                return json(['code' => 1, 'msg' => '请填写必填字段']);
            }

            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                return json(['code' => 1, 'msg' => '请输入有效的网址']);
            }

            // 检查是否已存在相同的链接
            $existingLink = Link::where('url', $url)->first();
            if ($existingLink) {
                return json(['code' => 1, 'msg' => '该链接已存在']);
            }

            // 创建待审核的链接
            $link = new Link();
            $link->name = $name;
            $link->url = $url;
            $link->description = $description;
            $link->status = false; // 默认为未审核状态
            $link->sort_order = 999; // 默认排序
            $link->target = '_blank';
            $link->redirect_type = 'goto';
            $link->show_url = true;
            
            // 构建内容信息
            $contentInfo = [];
            $contentInfo[] = "联系信息: " . $contact;
            if ($supportsWindConnect) {
                $contentInfo[] = "支持风屿互联协议";
            }
            if ($allowsCrawling) {
                $contentInfo[] = "允许资源爬虫访问";
            }
            
            $link->content = implode("\n", $contentInfo);
            $link->save();

            return json(['code' => 0, 'msg' => '申请成功，等待管理员审核']);
        }

        // 显示申请页面
        return view('link/request', [
            'page_title' => blog_config('title', 'WindBlog', true) . ' - 申请友链'
        ]);
    }
}