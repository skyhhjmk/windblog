<?php

namespace plugin\admin\app\controller;

use support\Cache;
use support\Db;
use support\Request;
use support\Response;
use Throwable;

/**
 * OAuth 平台管理
 */
class OAuthController extends Base
{
    /**
     * OAuth管理页面
     *
     * @return Response
     */
    public function index(): Response
    {
        return raw_view('oauth/index');
    }

    /**
     * 获取所有OAuth平台列表
     *
     * @return Response
     */
    public function list(): Response
    {
        try {
            $providers = [];

            // 从数据库动态读取所有oauth_*配置
            $allSettings = Db::table('settings')
                ->where('key', 'like', 'oauth_%')
                ->get();

            foreach ($allSettings as $setting) {
                // 提取provider名称 (oauth_xxx => xxx)
                $providerKey = str_replace('oauth_', '', $setting->key);

                try {
                    $config = json_decode($setting->value, true);

                    if ($config && is_array($config)) {
                        $providers[] = [
                            'key' => $providerKey,
                            'enabled' => $config['enabled'] ?? false,
                            'name' => $config['name'] ?? ucfirst($providerKey),
                            'icon' => $config['icon'] ?? 'fab fa-' . $providerKey,
                            'color' => $config['color'] ?? '#666',
                            'base_url' => $config['base_url'] ?? '',
                            'has_base_url' => !empty($config['base_url']),
                            'client_id' => $config['client_id'] ?? '',
                            'client_secret' => !empty($config['client_secret']) ? '******' : '', // 隐藏密钥
                            'scopes' => $config['scopes'] ?? [],
                        ];
                    }
                } catch (Throwable $e) {
                    continue;
                }
            }

            return $this->json(0, 'success', $providers);
        } catch (Throwable $e) {
            return $this->json(1, $e->getMessage());
        }
    }

    /**
     * 获取单个OAuth平台配置
     *
     * @param Request $request
     *
     * @return Response
     */
    public function get(Request $request): Response
    {
        try {
            $provider = $request->get('provider', '');

            if (empty($provider)) {
                return $this->json(1, '平台标识符不能为空');
            }

            $config = blog_config('oauth_' . $provider, [], false, true);

            if (empty($config)) {
                return $this->json(1, '平台配置不存在');
            }

            return $this->json(0, 'success', [
                'key' => $provider,
                'enabled' => $config['enabled'] ?? false,
                'name' => $config['name'] ?? ucfirst($provider),
                'icon' => $config['icon'] ?? 'fab fa-' . $provider,
                'color' => $config['color'] ?? '#666',
                'base_url' => $config['base_url'] ?? '',
                'client_id' => $config['client_id'] ?? '',
                'client_secret' => $config['client_secret'] ?? '',
                'scopes' => $config['scopes'] ?? [],
                'authorize_path' => $config['authorize_path'] ?? '',
                'token_path' => $config['token_path'] ?? '',
                'userinfo_path' => $config['userinfo_path'] ?? '',
                'revoke_path' => $config['revoke_path'] ?? '',
                'user_id_field' => $config['user_id_field'] ?? '',
                'username_field' => $config['username_field'] ?? '',
                'email_field' => $config['email_field'] ?? '',
                'nickname_field' => $config['nickname_field'] ?? '',
                'avatar_field' => $config['avatar_field'] ?? '',
            ]);
        } catch (Throwable $e) {
            return $this->json(1, $e->getMessage());
        }
    }

    /**
     * 保存/更新OAuth平台配置
     *
     * @param Request $request
     *
     * @return Response
     */
    public function save(Request $request): Response
    {
        try {
            $data = json_decode($request->rawBody(), true);

            if (!$data || !is_array($data)) {
                return $this->json(1, '无效的请求数据');
            }

            $provider = $data['key'] ?? '';

            if (empty($provider)) {
                return $this->json(1, '平台标识符不能为空');
            }

            // 验证标识符格式
            if (!preg_match('/^[a-z0-9_]+$/', $provider)) {
                return $this->json(1, '平台标识符只能包含小写字母、数字和下划线');
            }

            // 读取现有配置
            $config = blog_config('oauth_' . $provider, [], true, true) ?: [];

            // 更新配置
            $config['enabled'] = isset($data['enabled']) && $data['enabled'] === true;
            $config['name'] = $data['name'] ?? '';
            $config['icon'] = $data['icon'] ?? 'fab fa-' . $provider;
            $config['color'] = $data['color'] ?? '#666';
            $config['base_url'] = $data['base_url'] ?? '';
            $config['client_id'] = $data['client_id'] ?? '';

            // 只在提供了新密钥时更新
            if (!empty($data['client_secret']) && $data['client_secret'] !== '******') {
                $config['client_secret'] = $data['client_secret'];
            }

            // Scopes
            if (isset($data['scopes'])) {
                if (is_string($data['scopes'])) {
                    // 支持逗号分隔的字符串
                    $config['scopes'] = array_filter(array_map('trim', explode(',', $data['scopes'])));
                } elseif (is_array($data['scopes'])) {
                    $config['scopes'] = $data['scopes'];
                }
            }

            // 端点配置
            $config['authorize_path'] = $data['authorize_path'] ?? '';
            $config['token_path'] = $data['token_path'] ?? '';
            $config['userinfo_path'] = $data['userinfo_path'] ?? '';
            $config['revoke_path'] = $data['revoke_path'] ?? '';

            // 字段映射
            $config['user_id_field'] = $data['user_id_field'] ?? '';
            $config['username_field'] = $data['username_field'] ?? '';
            $config['email_field'] = $data['email_field'] ?? '';
            $config['nickname_field'] = $data['nickname_field'] ?? '';
            $config['avatar_field'] = $data['avatar_field'] ?? '';

            // 保存配置
            blog_config('oauth_' . $provider, $config, false, true, true);

            return $this->json(0, '保存成功');
        } catch (Throwable $e) {
            return $this->json(1, $e->getMessage());
        }
    }

    /**
     * 删除OAuth平台配置
     *
     * @param Request $request
     *
     * @return Response
     */
    public function delete(Request $request): Response
    {
        try {
            $provider = $request->post('provider', '');

            if (empty($provider)) {
                return $this->json(1, '平台标识符不能为空');
            }

            // 删除配置
            Db::table('settings')
                ->where('key', 'oauth_' . $provider)
                ->delete();

            // 清理缓存
            $cacheKey = config('plugin.admin.app.cache_prefix', 'windblog:') . 'blog_config_oauth_' . $provider;
            Cache::delete($cacheKey);

            return $this->json(0, '删除成功');
        } catch (Throwable $e) {
            return $this->json(1, $e->getMessage());
        }
    }

    /**
     * 切换平台启用状态
     *
     * @param Request $request
     *
     * @return Response
     */
    public function toggle(Request $request): Response
    {
        try {
            $provider = $request->post('provider', '');
            $enabled = $request->post('enabled', false);

            if (empty($provider)) {
                return $this->json(1, '平台标识符不能为空');
            }

            $config = blog_config('oauth_' . $provider, [], false, true);

            if (empty($config)) {
                return $this->json(1, '平台配置不存在');
            }

            $config['enabled'] = (bool) $enabled;
            blog_config('oauth_' . $provider, $config, false, true, true);

            return $this->json(0, '操作成功');
        } catch (Throwable $e) {
            return $this->json(1, $e->getMessage());
        }
    }
}
