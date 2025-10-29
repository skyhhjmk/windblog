<?php

namespace plugin\admin\app\controller;

use app\model\Setting;
use app\service\MediaLibraryService;
use plugin\admin\app\common\Util;
use support\Db;
use support\exception\BusinessException;
use support\Request;
use support\Response;
use Throwable;

/**
 * 系统设置
 */
class ConfigController extends Base
{
    /**
     * 不需要验证权限的方法
     *
     * @var string[]
     */
    protected $noNeedAuth = ['get', 'get_url_mode', 'get_site_info'];

    /**
     * 账户设置
     *
     * @return Response
     * @throws Throwable
     */
    public function index(): Response
    {
        return raw_view('config/index');
    }

    /**
     * 获取配置
     *
     * @return Response
     */
    public function get(): Response
    {
        return json($this->getByDefault());
    }

    /**
     * 基于配置文件获取默认权限
     *
     * @return mixed
     */
    protected function getByDefault()
    {
        $name = 'system_config';
        $config = Setting::where('key', $name)->value('value');
        if (empty($config)) {
            $config = <<<EOF
                {
                	"logo": {
                		"title": "风屿岛管理页",
                		"image": "/app/admin/admin/images/logo.png"
                	},
                	"menu": {
                		"data": "/app/admin/rule/get",
                		"method": "GET",
                		"accordion": true,
                		"collapse": false,
                		"control": false,
                		"controlWidth": 2000,
                		"select": "0",
                		"async": true
                	},
                	"tab": {
                		"enable": true,
                		"keepState": true,
                		"session": true,
                		"preload": false,
                		"max": "30",
                		"index": {
                			"id": "0",
                			"href": "/app/admin/index/dashboard",
                			"title": "仪表盘"
                		}
                	},
                	"theme": {
                		"defaultColor": "2",
                		"defaultMenu": "light-theme",
                		"defaultHeader": "light-theme",
                		"allowCustom": true,
                		"banner": false
                	},
                	"colors": [
                		{
                			"id": "1",
                			"color": "#36b368",
                			"second": "#f0f9eb"
                		},
                		{
                			"id": "2",
                			"color": "#2d8cf0",
                			"second": "#ecf5ff"
                		},
                		{
                			"id": "3",
                			"color": "#f6ad55",
                			"second": "#fdf6ec"
                		},
                		{
                			"id": "4",
                			"color": "#f56c6c",
                			"second": "#fef0f0"
                		},
                		{
                			"id": "5",
                			"color": "#3963bc",
                			"second": "#ecf5ff"
                		}
                	],
                	"other": {
                		"keepLoad": "500",
                		"autoHead": false,
                		"footer": false
                	},
                	"header": {
                		"message": false
                	}
                }
                EOF;
            if ($config) {
                $option = new Setting();
                $option->key = $name;
                $option->value = $config;
                $option->save();
            }
        }

        return json_decode($config, true);
    }

    /**
     * 更改配置设置
     *
     * @param Request $request
     *
     * @return Response
     * @throws BusinessException
     */
    public function update(Request $request): Response
    {
        $post = $request->post();
        $config = $this->getByDefault();
        $data = [];
        foreach ($post as $section => $items) {
            if (!isset($config[$section])) {
                continue;
            }

            switch ($section) {
                case 'logo':
                    $data[$section]['title'] = htmlspecialchars($items['title'] ?? '');
                    $data[$section]['image'] = Util::filterUrlPath($items['image'] ?? '');
                    $data[$section]['icp'] = htmlspecialchars($items['icp'] ?? '');
                    $data[$section]['beian'] = htmlspecialchars($items['beian'] ?? '');
                    $data[$section]['footer_txt'] = htmlspecialchars($items['footer_txt'] ?? '');
                    break;

                case 'menu':
                    $data[$section]['data'] = Util::filterUrlPath($items['data'] ?? '');
                    $data[$section]['accordion'] = !empty($items['accordion']);
                    $data[$section]['collapse'] = !empty($items['collapse']);
                    $data[$section]['control'] = !empty($items['control']);
                    $data[$section]['controlWidth'] = (int) ($items['controlWidth'] ?? 2000);
                    $data[$section]['select'] = (int) ($items['select'] ?? 0);
                    $data[$section]['async'] = true;
                    break;

                case 'tab':
                    $data[$section]['enable'] = true;
                    $data[$section]['keepState'] = !empty($items['keepState']);
                    $data[$section]['preload'] = !empty($items['preload']);
                    $data[$section]['session'] = !empty($items['session']);
                    $data[$section]['max'] = Util::filterNum($items['max'] ?? '30');
                    $data[$section]['index']['id'] = Util::filterNum($items['index']['id'] ?? '0');
                    $data[$section]['index']['href'] = Util::filterUrlPath($items['index']['href'] ?? '');
                    $data[$section]['index']['title'] = htmlspecialchars($items['index']['title'] ?? '首页');
                    break;

                case 'theme':
                    $data[$section]['defaultColor'] = Util::filterNum($items['defaultColor'] ?? '2');
                    // 修复运算符优先级问题
                    $data[$section]['defaultMenu'] = ($items['defaultMenu'] ?? '') == 'dark-theme' ? 'dark-theme' : 'light-theme';
                    $data[$section]['defaultHeader'] = ($items['defaultHeader'] ?? '') == 'dark-theme' ? 'dark-theme' : 'light-theme';
                    $data[$section]['allowCustom'] = !empty($items['allowCustom']);
                    $data[$section]['banner'] = !empty($items['banner']);
                    break;

                case 'colors':
                    foreach ($config['colors'] as $index => $item) {
                        if (!isset($items[$index])) {
                            $config['colors'][$index] = $item;
                            continue;
                        }
                        $data_item = $items[$index];
                        $data[$section][$index]['id'] = $index + 1;
                        $data[$section][$index]['color'] = $this->filterColor($data_item['color'] ?? '');
                        $data[$section][$index]['second'] = $this->filterColor($data_item['second'] ?? '');
                    }
                    break;
            }
        }

        $config = array_merge($config, $data);
        $name = 'system_config';

        // 保存到数据库
        Setting::where('key', $name)->update([
            'value' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ]);

        return $this->json(0);
    }

    /**
     * 获取URL模式配置
     *
     * @return Response
     */
    public function get_url_mode(): Response
    {
        try {
            // 调用全局函数blog_config获取URL模式配置
            $urlMode = blog_config('url_mode', 'slug', false);

            return json($urlMode);
        } catch (Throwable $e) {
            // 如果出错，返回默认值
            return json('slug');
        }
    }

    /**
     * 设置URL模式配置
     *
     * @param Request $request
     *
     * @return Response
     */
    public function set_url_mode(Request $request): Response
    {
        try {
            $urlMode = $request->post('url_mode', 'slug');

            // 验证URL模式值是否有效
            if (!in_array($urlMode, ['slug', 'id', 'mix'])) {
                throw new BusinessException('无效的URL模式值');
            }

            // 调用全局函数blog_config保存URL模式配置
            blog_config('url_mode', $urlMode, true, true, true);

            return $this->json(0);
        } catch (Throwable $e) {
            return $this->json(1, $e->getMessage());
        }
    }

    /**
     * 获取网站基本信息配置
     *
     * @return Response
     */
    public function get_site_info(): Response
    {
        try {
            $siteInfo = [
                'title' => blog_config('title', 'WindBlog', true),
                'site_url' => blog_config('site_url', '', true),
                'description' => blog_config('description', '', true),
                'favicon' => blog_config('favicon', '', true),
                'icp' => blog_config('icp', '', true),
                'beian' => blog_config('beian', '', true),
                'footer_txt' => blog_config('footer_txt', '', true),
            ];

            return json($siteInfo);
        } catch (Throwable $e) {
            return $this->json(1, $e->getMessage());
        }
    }

    /**
     * 设置网站基本信息配置
     *
     * @param Request $request
     *
     * @return Response
     */
    public function set_site_info(Request $request): Response
    {
        try {
            // 获取表单数据
            $siteInfo = $request->post();

            // 保存网站基本信息到blog_config
            blog_config('title', $siteInfo['title'] ?? 'WindBlog', true, true, true);
            blog_config('site_url', $siteInfo['site_url'] ?? '', true, true, true);
            blog_config('description', $siteInfo['description'] ?? '', true, true, true);
            blog_config('favicon', $siteInfo['favicon'] ?? '', true, true, true);
            blog_config('icp', $siteInfo['icp'] ?? '', true, true, true);
            blog_config('beian', $siteInfo['beian'] ?? '', true, true, true);
            blog_config('footer_txt', $siteInfo['footer_txt'] ?? '', true, true, true);

            // 如果favicon有更新，则生成新的favicon.ico文件
            if (isset($siteInfo['favicon']) && !empty($siteInfo['favicon'])) {
                $this->generateFavicon($siteInfo['favicon']);
            }

            return $this->json(0);
        } catch (Throwable $e) {
            return $this->json(1, $e->getMessage());
        }
    }

    /**
     * 生成favicon.ico文件
     *
     * @param string $faviconUrl favicon图片URL
     * @return void
     */
    private function generateFavicon(string $faviconUrl): void
    {
        try {
            // 获取public目录路径
            $publicPath = base_path() . DIRECTORY_SEPARATOR . 'public';
            $faviconPath = $publicPath . DIRECTORY_SEPARATOR . 'favicon.ico';

            // 如果已存在favicon.ico文件，则重命名为带时间戳的备份文件
            if (file_exists($faviconPath)) {
                $backupPath = $publicPath . DIRECTORY_SEPARATOR . 'favicon_' . time() . '.ico.bak';
                rename($faviconPath, $backupPath);
            }

            // 获取图片内容
            $imageUrl = $faviconUrl;
            if (strpos($imageUrl, '/uploads/') === 0) {
                // 如果是相对路径，转换为绝对路径
                $imageUrl = base_path() . DIRECTORY_SEPARATOR . 'public' . $imageUrl;
            }

            if (!file_exists($imageUrl)) {
                // 如果文件不存在，尝试作为URL处理
                $imageContent = @file_get_contents($faviconUrl);
                if ($imageContent === false) {
                    return; // 无法获取图片内容
                }

                // 将内容保存到临时文件
                $tempPath = tempnam(sys_get_temp_dir(), 'favicon');
                file_put_contents($tempPath, $imageContent);
                $imageUrl = $tempPath;
                $isTemp = true;
            } else {
                $isTemp = false;
            }

            // 使用媒体库服务生成 favicon
            $mediaService = new MediaLibraryService();
            $result = $mediaService->generateFavicon($imageUrl, $faviconPath);

            // 如果使用了临时文件，删除它
            if (isset($isTemp) && $isTemp) {
                unlink($imageUrl);
            }
        } catch (Throwable $e) {
            // 静默处理错误，不中断主流程
            // 可以记录日志以便调试
        }
    }

    /**
     * 获取OAuth配置
     *
     * @return Response
     */
    public function get_oauth_config(): Response
    {
        try {
            $oauthConfig = [];

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
                        // 构建返回数据，包含所有字段
                        $oauthConfig[$providerKey] = [
                            'enabled' => $config['enabled'] ?? false,
                            'name' => $config['name'] ?? ucfirst($providerKey),
                            'icon' => $config['icon'] ?? 'fab fa-' . $providerKey,
                            'color' => $config['color'] ?? '#666',
                            'base_url' => $config['base_url'] ?? '',
                            'client_id' => $config['client_id'] ?? '',
                            'client_secret' => $config['client_secret'] ?? '',
                            'scopes' => $config['scopes'] ?? [],

                            // 可选端点配置
                            'authorize_path' => $config['authorize_path'] ?? '',
                            'token_path' => $config['token_path'] ?? '',
                            'userinfo_path' => $config['userinfo_path'] ?? '',
                            'revoke_path' => $config['revoke_path'] ?? '',

                            // 字段映射配置
                            'user_id_field' => $config['user_id_field'] ?? '',
                            'username_field' => $config['username_field'] ?? '',
                            'email_field' => $config['email_field'] ?? '',
                            'nickname_field' => $config['nickname_field'] ?? '',
                            'avatar_field' => $config['avatar_field'] ?? '',
                        ];
                    }
                } catch (Throwable $e) {
                    // 忽略错误，继续处理下一个
                    continue;
                }
            }

            return json($oauthConfig);
        } catch (Throwable $e) {
            return $this->json(1, $e->getMessage());
        }
    }

    /**
     * 设置OAuth配置
     *
     * @param Request $request
     *
     * @return Response
     */
    public function set_oauth_config(Request $request): Response
    {
        try {
            // 接收JSON数据
            $body = $request->rawBody();
            $oauthConfig = json_decode($body, true);

            if (!$oauthConfig || !is_array($oauthConfig)) {
                return $this->json(1, '无效的请求数据');
            }

            // 动态保存所有平台配置
            foreach ($oauthConfig as $provider => $config) {
                if (!is_array($config)) {
                    continue;
                }

                // 读取现有配置
                $currentConfig = blog_config('oauth_' . $provider, [], true, true) ?: [];

                // 更新配置字段
                $currentConfig['enabled'] = isset($config['enabled']) && $config['enabled'] === true;
                $currentConfig['name'] = $config['name'] ?? $currentConfig['name'] ?? ucfirst($provider);
                $currentConfig['icon'] = $config['icon'] ?? $currentConfig['icon'] ?? 'fab fa-' . $provider;
                $currentConfig['color'] = $config['color'] ?? $currentConfig['color'] ?? '#666';
                $currentConfig['client_id'] = $config['client_id'] ?? '';
                $currentConfig['client_secret'] = $config['client_secret'] ?? '';

                // base_url（通用平台必须）
                if (isset($config['base_url'])) {
                    $currentConfig['base_url'] = $config['base_url'];
                }

                // scopes
                if (isset($config['scopes'])) {
                    $currentConfig['scopes'] = is_array($config['scopes']) ? $config['scopes'] : [];
                }

                // 端点配置
                foreach (['authorize_path', 'token_path', 'userinfo_path', 'revoke_path'] as $field) {
                    if (isset($config[$field])) {
                        $currentConfig[$field] = $config[$field];
                    }
                }

                // 字段映射配置
                foreach (['user_id_field', 'username_field', 'email_field', 'nickname_field', 'avatar_field'] as $field) {
                    if (isset($config[$field])) {
                        $currentConfig[$field] = $config[$field];
                    }
                }

                // 保存配置
                blog_config('oauth_' . $provider, $currentConfig, false, true, true);
            }

            return $this->json(0);
        } catch (Throwable $e) {
            return $this->json(1, $e->getMessage());
        }
    }

    /**
     * 获取Session配置
     *
     * @return Response
     */
    public function get_session_config(): Response
    {
        try {
            // 从数据库读取session配置
            $sessionConfig = blog_config('session_config', null, false);

            // 如果数据库没有配置，返回默认配置
            if (!$sessionConfig) {
                $sessionConfig = [
                    'handler' => 'FileSessionHandler',
                    'type' => 'file',
                    'session_name' => 'PHPSID',
                    'auto_update_timestamp' => false,
                    'lifetime' => 7 * 24 * 60 * 60,
                    'cookie_lifetime' => 365 * 24 * 60 * 60,
                    'cookie_path' => '/',
                    'domain' => '',
                    'http_only' => true,
                    'secure' => false,
                    'same_site' => 'strict',
                    // File配置
                    'file_save_path' => '',
                    // Redis配置
                    'redis_host' => '127.0.0.1',
                    'redis_port' => 6379,
                    'redis_auth' => '',
                    'redis_timeout' => 2,
                    'redis_database' => '',
                    'redis_prefix' => 'redis_session_',
                ];
            }

            return json($sessionConfig);
        } catch (Throwable $e) {
            return $this->json(1, $e->getMessage());
        }
    }

    /**
     * 设置Session配置
     *
     * @param Request $request
     *
     * @return Response
     */
    public function set_session_config(Request $request): Response
    {
        try {
            $sessionConfig = $request->post();

            // 验证必要的配置项
            if (!isset($sessionConfig['handler']) || !isset($sessionConfig['type'])) {
                return $this->json(1, '缺少必要的配置项');
            }

            // 验证handler类型
            $validHandlers = ['FileSessionHandler', 'RedisSessionHandler', 'RedisClusterSessionHandler'];
            if (!in_array($sessionConfig['handler'], $validHandlers)) {
                return $this->json(1, '无效的Handler类型');
            }

            // 验证type类型
            $validTypes = ['file', 'redis', 'redis_cluster'];
            if (!in_array($sessionConfig['type'], $validTypes)) {
                return $this->json(1, '无效的Session类型');
            }

            // 保存到数据库
            blog_config('session_config', $sessionConfig, false, true, true);

            return $this->json(0, '保存成功，请重启服务以使配置生效');
        } catch (Throwable $e) {
            return $this->json(1, $e->getMessage());
        }
    }

    /**
     * 颜色格式验证
     *
     * @param string $color 颜色值
     *
     * @return string 验证后的颜色值
     * @throws BusinessException 当颜色格式不正确时抛出
     */
    protected function filterColor(string $color): string
    {
        // 改进正则表达式，正确验证16进制颜色值格式
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            throw new BusinessException('颜色格式错误，请使用标准的16进制颜色值（如 #FFFFFF）');
        }

        return $color;
    }
}
