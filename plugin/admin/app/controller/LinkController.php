<?php

namespace plugin\admin\app\controller;

use app\model\Link;
use support\Request;
use support\Response;
use Throwable;

class LinkController extends Base
{
    /**
     * 链接列表页面
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request): Response
    {
        return view('link/index');
    }

    /**
     * 获取链接列表数据
     *
     * @param Request $request
     * @return Response
     */
    public function list(Request $request): Response
    {
        // 获取请求参数
        $name = $request->get('name', '');
        $url = $request->get('url', '');
        $status = $request->get('status', '');
        $isTrashed = $request->get('isTrashed', 'false');
        $page = (int)$request->get('page', 1);
        $limit = (int)$request->get('limit', 15);
        $order = $request->get('order', 'id');
        $sort = $request->get('sort', 'desc');

        // 构建查询
        if ($isTrashed === 'true') {
            $query = Link::onlyTrashed();
        } else {
            $query = Link::query();
        }

        // 状态筛选
        if ($status !== '') {
            $query->where('status', $status);
        }

        // 搜索条件
        if ($name) {
            $query->where('name', 'like', "%{$name}%");
        }

        if ($url) {
            $query->where('url', 'like', "%{$url}%");
        }

        // 获取总数
        $total = $query->count();

        // 排序和分页
        $list = $query->orderBy($order, $sort)
            ->forPage($page, $limit)
            ->get()
            ->toArray();

        return $this->success(trans('Success'), $list, $total);
    }

    /**
     * 添加链接页面
     *
     * @param Request $request
     * @return Response
     */
    public function add(Request $request): Response
    {
        if ($request->method() === 'POST') {
            return $this->save($request);
        }
        return view('link/add');
    }

    /**
     * 编辑链接页面
     *
     * @param Request $request
     * @param int $id
     * @return Response
     */
    public function edit(Request $request, int $id): Response
    {
        $link = Link::find($id);
        if (!$link) {
            return $this->fail('链接不存在');
        }

        if ($request->method() === 'POST') {
            return $this->save($request, $id);
        }

        return view('link/edit', ['link' => $link]);
    }

    /**
     * 保存链接数据
     *
     * @param Request $request
     * @param int|null $id
     * @return Response
     */
    private function save(Request $request, ?int $id = null): Response
    {
        $data = $request->post();
        
        // 验证必填字段
        if (empty($data['name']) || empty($data['url'])) {
            return $this->fail('名称和URL为必填字段');
        }

        // URL验证
        if (!filter_var($data['url'], FILTER_VALIDATE_URL)) {
            return $this->fail('请输入有效的URL地址');
        }

        // 图标URL验证（如果提供）
        if (!empty($data['icon']) && !filter_var($data['icon'], FILTER_VALIDATE_URL)) {
            return $this->fail('请输入有效的图标URL地址');
        }

        if ($id) {
            // 更新现有链接
            $link = Link::find($id);
            if (!$link) {
                return $this->fail('链接不存在');
            }
            
            // 检查URL是否重复（排除当前链接）
            $existing = Link::where('url', $data['url'])
                ->where('id', '!=', $id)
                ->first();
            if ($existing) {
                return $this->fail('该URL已存在');
            }
        } else {
            // 创建新链接
            $link = new Link();
            
            // 检查URL是否重复
            $existing = Link::where('url', $data['url'])->first();
            if ($existing) {
                return $this->fail('该URL已存在');
            }
        }

        // 填充数据
        $link->fill([
            'name' => $data['name'],
            'url' => $data['url'],
            'description' => $data['description'] ?? '',
            'icon' => $data['icon'] ?? '',
            'image' => $data['image'] ?? '',
            'sort_order' => (int)($data['sort_order'] ?? 999),
            'status' => (bool)($data['status'] ?? false),
            'target' => $data['target'] ?? '_blank',
            'redirect_type' => $data['redirect_type'] ?? 'goto',
            'show_url' => (bool)($data['show_url'] ?? true),
            'content' => $data['content'] ?? ''
        ]);

        if ($link->save()) {
            return $this->success($id ? '链接更新成功' : '链接添加成功');
        }

        return $this->fail($id ? '链接更新失败' : '链接添加失败');
    }

    /**
     * 软删除链接
     *
     * @param Request $request
     * @param int $id
     * @return Response
     * @throws Throwable
     */
    public function remove(Request $request, int $id): Response
    {
        $link = Link::find($id);
        if (!$link) {
            return $this->fail('链接不存在');
        }

        if ($link->softDelete() !== false) {
            return $this->success('链接已移至垃圾箱');
        }

        return $this->fail('删除失败');
    }

    /**
     * 恢复软删除的链接
     *
     * @param Request $request
     * @param int $id
     * @return Response
     */
    public function restore(Request $request, int $id): Response
    {
        $link = Link::withTrashed()->find($id);
        if (!$link) {
            return $this->fail('链接不存在');
        }

        if ($link->restore()) {
            return $this->success('链接已恢复');
        }

        return $this->fail('恢复失败');
    }

    /**
     * 永久删除链接
     *
     * @param Request $request
     * @param int $id
     * @return Response
     * @throws Throwable
     */
    public function forceDelete(Request $request, int $id): Response
    {
        $link = Link::withTrashed()->find($id);
        if (!$link) {
            return $this->fail('链接不存在');
        }

        if ($link->softDelete(true) === true) {
            return $this->success('链接已永久删除');
        }

        return $this->fail('删除失败');
    }

    /**
     * 批量恢复链接
     *
     * @param Request $request
     * @param string $ids
     * @return Response
     */
    public function batchRestore(Request $request, string $ids): Response
    {
        if (empty($ids)) {
            return $this->fail('参数错误');
        }

        $idArray = explode(',', $ids);
        $count = 0;

        foreach ($idArray as $id) {
            $link = Link::withTrashed()->find($id);
            if ($link && $link->restore()) {
                $count++;
            }
        }

        return $this->success("成功恢复 {$count} 个链接");
    }

    /**
     * 批量永久删除链接
     *
     * @param Request $request
     * @param string $ids
     * @return Response
     * @throws Throwable
     */
    public function batchForceDelete(Request $request, string $ids): Response
    {
        if (empty($ids)) {
            return $this->fail('参数错误');
        }

        $idArray = explode(',', $ids);
        $count = 0;

        foreach ($idArray as $id) {
            $link = Link::withTrashed()->find($id);
            if ($link && $link->softDelete(true) === true) {
                $count++;
            }
        }

        return $this->success("成功永久删除 {$count} 个链接");
    }

    /**
     * 批量软删除链接
     *
     * @param Request $request
     * @param string $ids
     * @return Response
     * @throws Throwable
     */
    public function batchRemove(Request $request, string $ids): Response
    {
        if (empty($ids)) {
            return $this->fail('参数错误');
        }

        $idArray = explode(',', $ids);
        $count = 0;

        foreach ($idArray as $id) {
            $link = Link::find($id);
            if ($link && $link->softDelete() !== false) {
                $count++;
            }
        }

        return $this->success("成功删除 {$count} 个链接");
    }

    /**
     * 查看链接详情
     *
     * @param Request $request
     * @param int $id
     * @return Response
     */
    public function view(Request $request, int $id): Response
    {
        $link = Link::find($id);
        if (!$link) {
            return $this->fail('链接不存在');
        }

        return view('link/view', ['link' => $link]);
    }
}