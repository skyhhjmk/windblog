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
     *
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
     *
     * @return Response
     */
    public function list(Request $request): Response
    {
        // 获取请求参数
        $name = $request->get('name', '');
        $url = $request->get('url', '');
        $status = $request->get('status', '');
        $isTrashed = $request->get('isTrashed', 'false');
        $isPending = $request->get('isPending', 'false'); // 新增：是否只显示待审核
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

        // 待审核筛选（新增）
        if ($isPending === 'true') {
            $query->where('status', false);
            // 按创建时间倒序排列，最新申请的排在前面
            $order = 'created_at';
            $sort = 'desc';
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

        // 处理列表数据，添加额外信息
        foreach ($list as &$item) {
            // 检查是否为待审核的友链申请
            $item['is_pending'] = !$item['status'];
        }


        return $this->success(trans('Success'), $list, $total);
    }

    /**
     * 添加链接页面
     *
     * @param Request $request
     *
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
     * @param int     $id
     *
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
     * @param Request  $request
     * @param int|null $id
     *
     * @return Response
     */
    private function save(Request $request, ?int $id = null): Response
    {
        $data = $request->post();

        // 验证必填字段
        if (empty($data['name']) || empty($data['url'])) {
            return $this->fail('名称和URL为必填字段');
        }

        // 增强URL验证
        if (!filter_var($data['url'], FILTER_VALIDATE_URL) ||
            !preg_match('/^https?:\/\/[a-zA-Z0-9][-a-zA-Z0-9]{0,62}(\.[a-zA-Z0-9][-a-zA-Z0-9]{0,62})+(:[0-9]{1,5})?(\/[-a-zA-Z0-9()@:%_\+.~#?&\/=]*)?$/', $data['url'])) {
            return $this->fail('请输入有效的URL地址');
        }

        // 图标URL验证（如果提供）
        if (!empty($data['icon'])) {
            if (!filter_var($data['icon'], FILTER_VALIDATE_URL) ||
                !preg_match('/^https?:\/\/[a-zA-Z0-9][-a-zA-Z0-9]{0,62}(\.[a-zA-Z0-9][-a-zA-Z0-9]{0,62})+(:[0-9]{1,5})?(\/[-a-zA-Z0-9()@:%_\+.~#?&\/=]*)?$/', $data['icon'])) {
                return $this->fail('请输入有效的图标URL地址');
            }
        }

        try {
            if ($id) {
                // 更新现有链接
                $link = Link::find($id);
                if (!$link) {
                    return $this->fail('链接不存在');
                }

                // 检查URL是否重复（排除当前链接）
                $existing = Link::where('url', 'like', "%{$data['url']}%")
                    ->where('id', '!=', $id)
                    ->first();
                if ($existing) {
                    return $this->fail('该URL已存在');
                }

                // 检查是否为审核操作
                $isApproval = !$link->status && isset($data['status']) && (bool)$data['status'] === true;
            } else {
                // 创建新链接
                $link = new Link();
                $isApproval = false;

                // 检查URL是否重复
                $existing = Link::where('url', 'like', "%{$data['url']}%")->first();
                if ($existing) {
                    return $this->fail('该URL已存在');
                }
            }

            // 填充数据
            $link->fill([
                'name' => htmlspecialchars($data['name'], ENT_QUOTES, 'UTF-8'),
                'url' => $data['url'],
                'description' => htmlspecialchars($data['description'] ?? '', ENT_QUOTES, 'UTF-8'),
                'icon' => $data['icon'] ?? '',
                'image' => $data['image'] ?? '',
                'sort_order' => (int)($data['sort_order'] ?? 999),
                'status' => (bool)($data['status'] ?? false),
                'target' => $data['target'] ?? 'unknow',
                'redirect_type' => $data['redirect_type'] ?? 'unknow',
                'show_url' => (bool)($data['show_url'] ?? true),
                'content' => $data['content'] ?? '',
                'note' => $data['note'] ?? '',
            ]);

            // 如果是审核通过操作，添加审核记录
            if ($isApproval && strpos($link->content, '## 申请信息') !== false) {
                $adminUser = $request->session()->get('admin_user');
                $adminName = $adminUser['name'] ?? '管理员';

                // 更新审核记录
                $link->content = str_replace(
                    '### 审核记录',
                    "### 审核记录\n\n> 已审核通过 - {$adminName} - " . date('Y-m-d H:i:s'),
                    $link->content
                );
            }

            if ($link->save()) {
                // 如果是审核通过操作，清除相关缓存
                if ($isApproval) {
                    // 清除前台链接列表缓存
                    $this->clearLinkCache();
                }

                return $this->success($id ? '链接更新成功' : '链接添加成功');
            }

            return $this->fail($id ? '链接更新失败' : '链接添加失败');
        } catch (\Exception $e) {
            \support\Log::error('链接保存失败: ' . $e->getMessage());
            return $this->fail('系统错误，请稍后再试');
        }
    }

    /**
     * 软删除链接
     *
     * @param Request $request
     * @param int     $id
     *
     * @return Response
     * @throws Throwable
     */
    public function remove(Request $request, int $id): Response
    {
        $link = Link::find($id);
        if (!$link) {
            return $this->fail('链接不存在');
        }

        try {
            if ($link->softDelete() !== false) {
                // 清除前台链接列表缓存
                $this->clearLinkCache();
                return $this->success('链接已移至垃圾箱');
            }
        } catch (\Exception $e) {
            \support\Log::error('链接删除失败: ' . $e->getMessage());
            return $this->fail('系统错误，请稍后再试');
        }

        return $this->fail('删除失败');
    }

    /**
     * 恢复软删除的链接
     *
     * @param Request $request
     * @param int     $id
     *
     * @return Response
     */
    public function restore(Request $request, int $id): Response
    {
        $link = Link::withTrashed()->find($id);
        if (!$link) {
            return $this->fail('链接不存在');
        }

        try {
            if ($link->restore()) {
                // 清除前台链接列表缓存
                $this->clearLinkCache();
                return $this->success('链接已恢复');
            }
        } catch (\Exception $e) {
            \support\Log::error('链接恢复失败: ' . $e->getMessage());
            return $this->fail('系统错误，请稍后再试');
        }

        return $this->fail('恢复失败');
    }

    /**
     * 永久删除链接
     *
     * @param Request $request
     * @param int     $id
     *
     * @return Response
     * @throws Throwable
     */
    public function forceDelete(Request $request, int $id): Response
    {
        $link = Link::withTrashed()->find($id);
        if (!$link) {
            return $this->fail('链接不存在');
        }

        try {
            if ($link->softDelete(true) === true) {
                // 清除前台链接列表缓存
                $this->clearLinkCache();
                return $this->success('链接已永久删除');
            }
        } catch (\Exception $e) {
            \support\Log::error('链接永久删除失败: ' . $e->getMessage());
            return $this->fail('系统错误，请稍后再试');
        }

        return $this->fail('删除失败');
    }

    /**
     * 批量恢复链接
     *
     * @param Request $request
     * @param string  $ids
     *
     * @return Response
     */
    public function batchRestore(Request $request, string $ids): Response
    {
        if (empty($ids)) {
            return $this->fail('参数错误');
        }

        $idArray = explode(',', $ids);
        $count = 0;

        try {
            foreach ($idArray as $id) {
                $link = Link::withTrashed()->find($id);
                if ($link && $link->restore()) {
                    $count++;
                }
            }

            if ($count > 0) {
                // 清除前台链接列表缓存
                $this->clearLinkCache();
            }
        } catch (\Exception $e) {
            \support\Log::error('批量恢复链接失败: ' . $e->getMessage());
            return $this->fail('系统错误，请稍后再试');
        }

        return $this->success("成功恢复 {$count} 个链接");
    }

    /**
     * 批量永久删除链接
     *
     * @param Request $request
     * @param string  $ids
     *
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

        try {
            foreach ($idArray as $id) {
                $link = Link::withTrashed()->find($id);
                if ($link && $link->softDelete(true) === true) {
                    $count++;
                }
            }

            if ($count > 0) {
                // 清除前台链接列表缓存
                $this->clearLinkCache();
            }
        } catch (\Exception $e) {
            \support\Log::error('批量永久删除链接失败: ' . $e->getMessage());
            return $this->fail('系统错误，请稍后再试');
        }

        return $this->success("成功永久删除 {$count} 个链接");
    }

    /**
     * 批量软删除链接
     *
     * @param Request $request
     * @param string  $ids
     *
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

        try {
            foreach ($idArray as $id) {
                $link = Link::find($id);
                if ($link && $link->softDelete() !== false) {
                    $count++;
                }
            }

            if ($count > 0) {
                // 清除前台链接列表缓存
                $this->clearLinkCache();
            }
        } catch (\Exception $e) {
            \support\Log::error('批量删除链接失败: ' . $e->getMessage());
            return $this->fail('系统错误，请稍后再试');
        }

        return $this->success("成功删除 {$count} 个链接");
    }

    /**
     * 查看链接详情
     *
     * @param Request $request
     * @param int     $id
     *
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

    /**
     * 获取单个链接信息
     *
     * @param Request $request
     * @param int     $id
     *
     * @return Response
     */
    public function get(Request $request, int $id): Response
    {
        $link = Link::find($id);
        if (!$link) {
            return $this->fail('链接不存在');
        }

        // 仅返回基本链接信息
        $linkData = $link->toArray();
        $linkData['is_pending'] = !$link->status;

        return $this->success('Success', $linkData);
    }

    /**
     * 批量审核链接
     *
     * @param Request $request
     * @param string  $ids
     *
     * @return Response
     */
    public function batchApprove(Request $request, string $ids): Response
    {
        if (empty($ids)) {
            return $this->fail('参数错误');
        }

        $idArray = explode(',', $ids);
        $count = 0;
        $adminUser = $request->session()->get('admin_user');
        $adminName = $adminUser['name'] ?? '管理员';

        try {
            foreach ($idArray as $id) {
                $link = Link::find($id);
                if ($link && !$link->status) {
                    // 更新审核记录
                    if (strpos($link->content, '## 申请信息') !== false) {
                        $link->content = str_replace(
                            '### 审核记录',
                            "### 审核记录\n\n> 已审核通过 - {$adminName} - " . date('Y-m-d H:i:s'),
                            $link->content
                        );
                    }

                    $link->status = true;
                    if ($link->save()) {
                        $count++;
                    }
                }
            }

            if ($count > 0) {
                // 清除前台链接列表缓存
                $this->clearLinkCache();
            }
        } catch (\Exception $e) {
            \support\Log::error('批量审核链接失败: ' . $e->getMessage());
            return $this->fail('系统错误，请稍后再试');
        }

        return $this->success("成功审核通过 {$count} 个链接");
    }

    /**
     * 批量拒绝链接
     *
     * @param Request $request
     * @param string  $ids
     *
     * @return Response
     * @throws Throwable
     */
    public function batchReject(Request $request, string $ids): Response
    {
        if (empty($ids)) {
            return $this->fail('参数错误');
        }

        $idArray = explode(',', $ids);
        $count = 0;
        $adminUser = $request->session()->get('admin_user');
        $adminName = $adminUser['name'] ?? '管理员';
        $reason = $request->post('reason', '不符合申请条件');

        try {
            foreach ($idArray as $id) {
                $link = Link::find($id);
                if ($link && !$link->status) {
                    // 更新审核记录
                    if (strpos($link->content, '## 申请信息') !== false) {
                        $link->content = str_replace(
                            '### 审核记录',
                            "### 审核记录\n\n> 已拒绝 - {$adminName} - " . date('Y-m-d H:i:s') . "\n> 原因：{$reason}",
                            $link->content
                        );
                        $link->save();
                    }

                    // 软删除链接
                    if ($link->softDelete() !== false) {
                        $count++;
                    }
                }
            }
        } catch (\Exception $e) {
            \support\Log::error('批量拒绝链接失败: ' . $e->getMessage());
            return $this->fail('系统错误，请稍后再试');
        }

        return $this->success("成功拒绝 {$count} 个链接申请");
    }

    /**
     * 清除前台链接列表缓存
     */
    private function clearLinkCache(): void
    {
        try {
            // 清除所有以 blog_links_page_ 开头的缓存键
            clear_cache('blog_links_page_*');
        } catch (\Exception $e) {
            \support\Log::error('清除链接缓存失败: ' . $e->getMessage());
        }
    }
}