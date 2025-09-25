<?php

namespace plugin\admin\app\controller;

use plugin\admin\app\common\Util;
use app\model\Setting;
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
    protected $noNeedAuth = ['get', 'get_url_mode'];

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
EOF;;
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
                    $data[$section]['controlWidth'] = (int)($items['controlWidth'] ?? 2000);
                    $data[$section]['select'] = (int)($items['select'] ?? 0);
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
            'value' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
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
