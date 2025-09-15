<?php

namespace app\controller;

use League\CommonMark\Exception\CommonMarkException;
use support\Request;
use app\service\BlogService;
use support\Response;
use Throwable;
use Webman\RateLimiter\Annotation\RateLimiter;

/**
 * 博客首页控制器
 */
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
     * @param int     $page    页码
     *
     * @return Response
     * @throws CommonMarkException
     * @throws Throwable
     */
    #[RateLimiter(limit: 3,ttl: 3)]
    public function index(Request $request, int $page = 1): Response
    {
        // 构建筛选条件
        $filters = $request->get() ?: [];
        
        // 调用博客服务获取文章数据
        $result = BlogService::getBlogPosts($page, $filters);
        
        // 获取博客标题
        $blog_title = BlogService::getBlogTitle();
        
        return view('index/index', [
            'page_title' => $blog_title . ' - count is -' . $result['totalCount'],
            'posts' => $result['posts'],
            'pagination' => $result['pagination'],
        ]);
    }

    /**
     * 调试用获取自己的全部session内容
     *
     * @param Request $request 请求对象
     * @return Response
     */
    public function getSession(Request $request): Response
    {
        // 这样就可以！！！！！！！！！！！！！！！！！！！！！！！！！！！！！！！
        try {
            // 检查session对象是否存在
            $session = $request->session();
            if (!$session) {
                return response('Session object not available', 500);
            }
            
            // 获取session ID
            $sessionId = $request->sessionId();
            
            // 获取所有session数据
            $all = $session->all();
            
            // 组织返回信息
            $result = [
                'session_id' => $sessionId,
                'session_data' => $all,
                'session_class' => get_class($session)
            ];
            
            return response(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 200)
                ->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            return response('Error: ' . $e->getMessage(), 500);
        }

        // 但是这样就不行！！！！！！！！！！！！！！！！！！！！！！！！！！！！！！！
        /* 这tm可是官方示例代码的：【获取全部session】
        $session = $request->session();
        $all = $session->all(); // 但是这里为什么获取的是null？？？？？？？？？？
        */
    }
}