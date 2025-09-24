<?php

namespace plugin\admin\app\controller;

use app\service\SidebarService;
use support\Request;
use Throwable;

/**
 * 侧边栏管理控制器
 * 用于管理博客侧边栏的内容和配置
 */
class SidebarController extends Base
{
    /**
     * 无需登录的方法
     */
    protected $noNeedLogin = [];

    /**
     * 无需鉴权的方法
     */
    protected $noNeedAuth = [];

    /**
     * 侧边栏管理首页
     *
     * @return \support\Response
     */
    public function index(): \support\Response
    {
        try {
            // 只返回HTML内容，不传递数据
            return raw_view('sidebar/index');
        } catch (Throwable $e) {
            return $this->fail('加载侧边栏管理页面失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取特定页面的侧边栏配置
     *
     * @param Request $request 请求对象
     *
     * @return \support\Response
     */
    public function getSidebar(Request $request): \support\Response
    {
        try {
            $pageKey = $request->get('page_key', 'default');
            $sidebarConfig = SidebarService::getSidebarByPage($pageKey);
            
            return $this->success('获取侧边栏配置成功', $sidebarConfig);
        } catch (Throwable $e) {
            return $this->fail('获取侧边栏配置失败: ' . $e->getMessage());
        }
    }

    /**
     * 保存侧边栏配置
     *
     * @param Request $request 请求对象
     *
     * @return \support\Response
     */
    public function saveSidebar(Request $request): \support\Response
    {
        try {
            $pageKey = $request->post('page_key', 'default');
            $sidebarConfig = $request->post('sidebar_config', []);
            
            // 确保是数组格式
            if (!is_array($sidebarConfig)) {
                // 尝试解析JSON字符串
                $parsed = json_decode($sidebarConfig, true);
                if (!is_array($parsed)) {
                    return $this->fail('侧边栏配置数据格式错误');
                }
                $sidebarConfig = $parsed;
            }
            
            // 检查是否直接发送了widgets数组
            if (empty($sidebarConfig) || !isset($sidebarConfig['widgets'])) {
                $widgets = $request->post('widgets', []);
                if (is_array($widgets)) {
                    $sidebarConfig = ['widgets' => $widgets];
                } else {
                    return $this->fail('侧边栏配置数据格式错误');
                }
            }
            
            // 确保widgets数组存在且有效
            if (!isset($sidebarConfig['widgets']) || !is_array($sidebarConfig['widgets'])) {
                $sidebarConfig['widgets'] = [];
            }
            
            // 保存配置
            $result = SidebarService::saveSidebarConfig($pageKey, $sidebarConfig);
            
            return $result ? $this->success('保存侧边栏配置成功') : $this->fail('保存侧边栏配置失败');
        } catch (Throwable $e) {
            return $this->fail('保存侧边栏配置失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取所有可用的小工具类型
     *
     * @return \support\Response
     */
    public function getAvailableWidgets(): \support\Response
    {
        try {
            $widgets = SidebarService::getAvailableWidgets();
            return $this->success('获取小工具类型成功', $widgets);
        } catch (Throwable $e) {
            return $this->fail('获取小工具类型失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取可用的页面列表
     *
     * @return \support\Response
     */
    public function getPages(): \support\Response
    {
        try {
            $pages = SidebarService::getSidebarPages();
            return $this->success('获取页面列表成功', $pages);
        } catch (Throwable $e) {
            return $this->fail('获取页面列表失败: ' . $e->getMessage());
        }
    }

    /**
     * 添加小工具到侧边栏
     *
     * @param Request $request 请求对象
     *
     * @return \support\Response
     */
    public function addWidget(Request $request): \support\Response
    {
        try {
            $pageKey = $request->post('page_key', 'default');
            $widgetType = $request->post('widget_type', '');
            
            if (empty($widgetType)) {
                return $this->fail('小工具类型不能为空');
            }
            
            // 获取当前侧边栏配置
            $sidebarConfig = SidebarService::getSidebarByPage($pageKey);
            
            // 确保widgets数组存在
            if (!isset($sidebarConfig['widgets']) || !is_array($sidebarConfig['widgets'])) {
                $sidebarConfig['widgets'] = [];
            }
            
            // 创建新的小工具配置
            $availableWidgets = SidebarService::getAvailableWidgets();
            $widgetInfo = null;
            
            foreach ($availableWidgets as $widget) {
                if ($widget['type'] === $widgetType) {
                    $widgetInfo = $widget;
                    break;
                }
            }
            
            if (!$widgetInfo) {
                return $this->fail('无效的小工具类型');
            }
            
            // 生成唯一ID
            $widgetId = $widgetType . '_' . uniqid();
            
            // 创建新小工具，设置合理的默认值
            $newWidget = [
                'id' => $widgetId,
                'title' => $widgetInfo['name'],
                'type' => $widgetType,
                'enabled' => true,
                'content' => $widgetType === 'about' ? '欢迎访问我的博客' : '',
                'limit' => in_array($widgetType, ['recent_posts', 'popular_posts', 'random_posts']) ? 5 : null
            ];
            
            // 添加到widgets数组
            $sidebarConfig['widgets'][] = $newWidget;
            
            // 保存更新后的配置
            SidebarService::saveSidebarConfig($pageKey, $sidebarConfig);
            
            return $this->success('添加小工具成功', $newWidget);
        } catch (Throwable $e) {
            return $this->fail('添加小工具失败: ' . $e->getMessage());
        }
    }

    /**
     * 删除侧边栏中的小工具
     *
     * @param Request $request 请求对象
     *
     * @return \support\Response
     */
    public function removeWidget(Request $request): \support\Response
    {
        try {
            $pageKey = $request->post('page_key', 'default');
            $widgetId = $request->post('widget_id', '');
            
            if (empty($widgetId)) {
                return $this->fail('小工具ID不能为空');
            }
            
            // 获取当前侧边栏配置
            $sidebarConfig = SidebarService::getSidebarByPage($pageKey);
            
            // 确保widgets数组存在
            if (!isset($sidebarConfig['widgets']) || !is_array($sidebarConfig['widgets'])) {
                return $this->fail('侧边栏配置中没有小工具');
            }
            
            // 查找并移除小工具
            $found = false;
            foreach ($sidebarConfig['widgets'] as $key => &$widget) {
                if ($widget['id'] === $widgetId) {
                    unset($sidebarConfig['widgets'][$key]);
                    $found = true;
                    break;
                }
            }
            
            if (!$found) {
                return $this->fail('未找到指定的小工具');
            }
            
            // 重新索引数组
            $sidebarConfig['widgets'] = array_values($sidebarConfig['widgets']);
            
            // 保存更新后的配置
            SidebarService::saveSidebarConfig($pageKey, $sidebarConfig);
            
            return $this->success('删除小工具成功');
        } catch (Throwable $e) {
            return $this->fail('删除小工具失败: ' . $e->getMessage());
        }
    }

    /**
     * 更新小工具配置
     *
     * @param Request $request 请求对象
     *
     * @return \support\Response
     */
    public function updateWidget(Request $request): \support\Response
    {
        try {
            $pageKey = $request->post('page_key', 'default');
            $widgetId = $request->post('widget_id', '');
            $widgetConfig = $request->post('widget_config', []);
            
            if (empty($widgetId) || !is_array($widgetConfig)) {
                return $this->fail('参数错误');
            }
            
            // 获取当前侧边栏配置
            $sidebarConfig = SidebarService::getSidebarByPage($pageKey);
            
            // 确保widgets数组存在
            if (!isset($sidebarConfig['widgets']) || !is_array($sidebarConfig['widgets'])) {
                return $this->fail('侧边栏配置中没有小工具');
            }
            
            // 查找并更新小工具
            $found = false;
            foreach ($sidebarConfig['widgets'] as &$widget) {
                if ($widget['id'] === $widgetId) {
                    // 合并配置，保留原始ID和类型
                    $widget = array_merge($widget, $widgetConfig);
                    $widget['id'] = $widgetId; // 确保ID不变
                    
                    // 如果配置了类型，允许更改类型
                    if (isset($widgetConfig['type'])) {
                        $widget['type'] = $widgetConfig['type'];
                    }
                    
                    $found = true;
                    break;
                }
            }
            
            if (!$found) {
                return $this->fail('未找到指定的小工具');
            }
            
            // 保存更新后的配置
            SidebarService::saveSidebarConfig($pageKey, $sidebarConfig);
            
            return $this->success('更新小工具配置成功');
        } catch (Throwable $e) {
            return $this->fail('更新小工具配置失败: ' . $e->getMessage());
        }
    }
}