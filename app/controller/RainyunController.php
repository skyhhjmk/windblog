<?php

namespace app\controller;

use app\service\PJAXHelper;
use app\service\SidebarService;
use support\Request;
use support\Response;

/**
 * Rainyun API控制器
 * 提供与Rainyun API交互的界面
 */
class RainyunController
{
    /**
     * 不需要登录的方法
     */
    protected array $noNeedLogin = ['index'];

    /**
     * Rainyun控制器首页
     * 显示API交互界面
     *
     * @param Request $request 请求对象
     * @return Response
     */
    public function index(Request $request): Response
    {
        // 获取页面标题
        $page_title = 'Rainyun API 工具';

        // 获取侧边栏内容（PJAX 与非 PJAX 均获取）
        $sidebar = SidebarService::getSidebarContent($request, 'rainyun');

        // 动态选择模板：PJAX 返回片段，非 PJAX 返回完整页面
        $viewName = PJAXHelper::isPJAX($request) ? 'rainyun/index.content' : 'rainyun/index';

        return view($viewName, [
            'page_title' => $page_title,
            'sidebar' => $sidebar,
        ]);
    }
}
