<?php

namespace app\controller;

use app\service\PJAXHelper;
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
     * index: Rainyun API工具页面，公开访问
     */
    protected array $noNeedLogin = ['index'];

    /**
     * Rainyun控制器首页
     * 显示API交互界面
     *
     * @param Request $request 请求对象
     *
     * @return Response
     */
    public function index(Request $request): Response
    {
        // 读取视图类型：默认展示控制台，可通过 ?view=index 或 ?manage=1 切换到旧版管理页
        $viewParam = (string) ($request->get('view', ''));
        $manageFlag = (string) ($request->get('manage', ''));
        $useIndex = ($viewParam === 'index') || ($manageFlag === '1' || strtolower($manageFlag) === 'true');

        $page_title = $useIndex ? 'Rainyun API 工具' : 'Rainyun 控制台';

        // 动态选择模板：PJAX 返回片段，非 PJAX 返回完整页面
        $tplBase = $useIndex ? 'rainyun/index' : 'rainyun/console';
        $viewName = PJAXHelper::getViewName($tplBase, PJAXHelper::isPJAX($request));

        // 使用PJAXHelper创建响应，确保包含必要的头信息
        // 不传递sidebar，让视图文件处理侧边栏隐藏
        return PJAXHelper::createResponse(
            $request,
            $viewName,
            [
                'page_title' => $page_title,
                'sidebar' => null,
            ]
        );
    }
}
