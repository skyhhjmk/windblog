<?php

namespace plugin\admin\app\controller;

use app\service\LinkConnectService;
use Exception;
use support\Request;
use support\Response;

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
        } catch (Exception $e) {
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
        } catch (Exception $e) {
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
        } catch (Exception $e) {
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
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 生成本站互联链接（策略B：使用最近的未使用Token；无则提示先生成）
     */
    public function generateLink(): Response
    {
        try {
            $cfg = LinkConnectService::getConfig();
            $siteUrl = (string) ($cfg['site_info']['url'] ?? '') ?: (string) blog_config('site_url', '', true);
            if (!$siteUrl) {
                return $this->fail('站点 URL 未配置');
            }

            $token = LinkConnectService::getLatestUnusedToken();
            if (!$token) {
                return $this->fail('无可用Token，请先在“安全设置”生成Token');
            }

            $api = rtrim($siteUrl, '/') . '/api/wind-connect';
            $url = $api . '?token=' . urlencode($token);

            return $this->success('Success', ['url' => $url]);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 生成 Token
     */
    public function generateToken(): Response
    {
        try {
            $record = LinkConnectService::generateToken();

            return $this->success('Token 已生成', $record);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * Token 列表
     */
    public function tokens(): Response
    {
        try {
            return $this->success('Success', LinkConnectService::listTokens());
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 作废 Token
     */
    public function invalidateToken(Request $request): Response
    {
        try {
            $token = (string) $request->post('token', '');
            if (!$token) {
                return $this->fail('token 不能为空');
            }
            $ok = LinkConnectService::invalidateToken($token);

            return $ok ? $this->success('已作废', []) : $this->fail('作废失败');
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 发起对等站互联申请（快速互联）
     * @param Request $request
     * @return Response
     */
    public function applyToPeer(Request $request): Response
    {
        try {
            $input = [
                'peer_api'    => (string) $request->post('peer_api', ''),
                'name'        => (string) $request->post('name', ''),
                'url'         => (string) $request->post('url', ''),
                'icon'        => (string) $request->post('icon', ''),
                'description' => (string) $request->post('description', ''),
                'email'       => (string) $request->post('email', ''),
            ];
            $res = LinkConnectService::applyToPeer($input);
            if (($res['code'] ?? 1) === 0) {
                return $this->success($res['msg'] ?? '成功', []);
            }

            return $this->fail($res['msg'] ?? '失败');
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
