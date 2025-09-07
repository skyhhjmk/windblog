<?php

namespace app\controller;

use support\Request;
use app\service\BlogService;

class IndexController
{
    /**
     * 不需要登录的方法
     */
    protected array $noNeedLogin = ['index', 'getSession'];

    /**
     * 博客首页
     *
     * @param Request $request 请求对象
     * @param int $page 页码
     * @return \support\Response
     */
    public function index(Request $request, int $page = 1)
    {
        // 构建筛选条件
        $filters = $request->get() ?: [];
        
        // 调用博客服务获取文章数据
        $result = BlogService::getBlogPosts($page, $filters);
        
        // 获取博客标题
        $blogTitle = BlogService::getBlogTitle();
        
        return view('index/index', [
            'page_title' => $blogTitle . ' - count is -' . $result['totalCount'],
            'posts' => $result['posts'],
            'pagination' => $result['pagination'],
        ]);
    }

    /**
     * 调试用获取自己的全部session内容
     *
     * @param Request $request 请求对象
     * @return \support\Response
     */
    public function getSession(Request $request)
    {
        $session = $request->session();
        $all = $session->all();
        return response(var_export($all), 200);
    }
}