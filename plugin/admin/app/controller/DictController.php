<?php

namespace plugin\admin\app\controller;

use app\model\Setting;
use app\service\CacheService;
use plugin\admin\app\model\Dict;
use support\exception\BusinessException;
use support\Request;
use support\Response;
use Throwable;

/**
 * 字典管理
 */
class DictController extends Base
{
    /**
     * 不需要授权的方法
     */
    protected $noNeedAuth = ['get'];

    /**
     * 浏览
     *
     * @return Response
     * @throws Throwable
     */
    public function index(): Response
    {
        return raw_view('dict/index');
    }

    /**
     * 查询
     *
     * @param Request $request
     *
     * @return Response
     */
    public function select(Request $request): Response
    {
        $name = $request->get('name', '');
        $page = $request->get('page', 1);
        $limit = $request->get('limit', 10);
        $offset = ($page - 1) * $limit;
        $query = Setting::query();
        if (!empty($name) && is_string($name)) {
            $keyPrefix = "dict_{$name}%";
            $query->where('key', 'like', $keyPrefix);
        } else {
            $query->where('key', 'like', 'dict_%');
        }

        $count = $query->count();

        $items = $query->limit($limit)->offset($offset)->get()->toArray();
        foreach ($items as &$item) {
            $itemName = $item['key'];
            $item['name'] = Dict::optionNameToDictName($itemName);
        }

        return json(['code' => 0, 'msg' => 'ok', 'count' => $count, 'data' => $items]);
    }

    /**
     * 插入
     *
     * @param Request $request
     *
     * @return Response
     * @throws BusinessException|Throwable
     */
    public function insert(Request $request): Response
    {
        if ($request->method() === 'POST') {
            $name = $request->post('name');
            if (Dict::get($name)) {
                return $this->json(1, '字典已经存在');
            }
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
                return $this->json(2, '字典名称只能是字母数字下划线的组合');
            }
            $values = (array) $request->post('value', []);
            Dict::save($name, $values);
        }

        return raw_view('dict/insert');
    }

    /**
     * 更新
     *
     * @param Request $request
     *
     * @return Response
     * @throws BusinessException|Throwable
     */
    public function update(Request $request): Response
    {
        if ($request->method() === 'POST') {
            $name = $request->post('name');
            if (!Dict::get($name)) {
                return $this->json(1, '字典不存在');
            }
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
                return $this->json(2, '字典名称只能是字母数字下划线的组合');
            }
            Dict::save($name, $request->post('value'));
        }

        return raw_view('dict/update');
    }

    /**
     * 删除
     *
     * @param Request $request
     *
     * @return Response
     */
    public function delete(Request $request): Response
    {
        $names = (array) $request->post('name');
        Dict::delete($names);
        CacheService::clearCache('blog_config_dict_*');

        return $this->json(0);
    }

    /**
     * 获取
     *
     * @param Request $request
     * @param         $name
     *
     * @return Response
     */
    public function get(Request $request, $name): Response
    {
        return $this->json(0, 'ok', (array) Dict::get($name));
    }
}
