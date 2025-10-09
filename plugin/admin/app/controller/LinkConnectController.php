<?php

namespace plugin\admin\app\controller;

use support\Request;
use support\Response;
use app\service\LinkConnectService;

/**
 * 互联协议管理控制器
 * 用于管理互联协议的相关功能和设置
 */
class LinkConnectController extends Base
{
    /**
     * 互联协议管理页面
     * @return Response
     */
    public function index(): Response
    {
        return view('linkconnect/index');
    }

    /**
     * 获取配置
     * @return Response
     */
    public function getConfig(): Response
    {
        try {
            $config = LinkConnectService::getConfig();
            return $this->success('Success', $config);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 保存配置
     * @param Request $request
     * @return Response
     */
    public function saveConfig(Request $request): Response
    {
        try {
            $config = $request->post('config', []);
            
            if (empty($config)) {
                return $this->fail('配置数据不能为空');
            }
            
            $result = LinkConnectService::saveConfig($config);
            
            if ($result) {
                return $this->success('配置保存成功', []);
            } else {
                return $this->fail('配置保存失败');
            }
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 获取示例配置
     * @return Response
     */
    public function getExample(): Response
    {
        try {
            $example = LinkConnectService::getExample();
            return $this->success('Success', $example);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 测试连接
     * @param Request $request
     * @return Response
     */
    public function testConnection(Request $request): Response
    {
        try {
            $url = $request->post('url', '');
            
            if (empty($url)) {
                return $this->fail('请输入测试URL');
            }
            
            $result = LinkConnectService::testConnection($url);
            
            if ($result['success']) {
                return $this->success($result['message'], $result);
            } else {
                return $this->fail($result['message']);
            }
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}