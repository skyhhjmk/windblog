<?php

namespace app\controller;

use app\service\PJAXHelper;
use support\Request;
use support\Response;
use Throwable;
use Webman\RateLimiter\Annotation\RateLimiter;

/**
 * 动画演示页面控制器
 * 用于展示和测试博客的高级动画效果
 */
class AnimationDemoController
{
    /**
     * 不需要登录的方法
     */
    protected array $noNeedLogin = ['index'];

    /**
     * 动画演示页面
     *
     * @param Request $request 请求对象
     * @return Response
     * @throws Throwable
     */
    #[RateLimiter(limit: 5, ttl: 3)]
    public function index(Request $request): Response
    {
        // 使用PJAXHelper检测是否为PJAX请求
        $isPjax = PJAXHelper::isPJAX($request);

        // 获取侧边栏内容
        $sidebar = \app\service\SidebarService::getSidebarContent($request, 'animation-demo');

        // 动态选择模板：PJAX 返回片段，非 PJAX 返回完整页面
        $viewName = PJAXHelper::getViewName('index/index', $isPjax);

        // 创建带缓存的PJAX响应
        $resp = PJAXHelper::createResponse(
            $request,
            'animation-demo',
            [
                'page_title' => '动画效果演示 - 风屿雨博客',
                'sidebar' => $sidebar,
            ],
            null, // 不使用缓存
            0,
            'page'
        );

        return $resp;
    }
}
