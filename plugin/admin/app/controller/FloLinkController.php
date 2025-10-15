<?php

namespace plugin\admin\app\controller;

use app\model\FloLink;
use app\service\FloLinkService;
use Exception;
use support\Request;
use support\Response;
use Throwable;

/**
 * FloLink 浮动链接管理控制器
 */
class FloLinkController extends Base
{
    /**
     * FloLink列表页面
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request): Response
    {
        return view('flolink/index');
    }

    /**
     * 获取FloLink列表数据
     *
     * @param Request $request
     * @return Response
     */
    public function list(Request $request): Response
    {
        // 获取请求参数
        $keyword = $request->get('keyword', '');
        $url = $request->get('url', '');
        $status = $request->get('status', '');
        $isTrashed = $request->get('isTrashed', 'false');
        $page = (int) $request->get('page', 1);
        $limit = (int) $request->get('limit', 15);
        $order = $request->get('order', 'priority');
        $sort = $request->get('sort', 'asc');

        // 构建查询
        if ($isTrashed === 'true') {
            $query = FloLink::onlyTrashed();
        } else {
            $query = FloLink::query();
        }

        // 状态筛选
        if ($status !== '') {
            $query->where('status', $status === '1' || $status === 'true');
        }

        // 搜索条件
        if ($keyword) {
            $query->where('keyword', 'like', "%{$keyword}%");
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

        // 返回列表数据（无缓存）
        return $this->success(trans('Success'), $list, $total)
            ->withHeaders([
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ]);
    }

    /**
     * 添加FloLink页面
     *
     * @param Request $request
     * @return Response
     */
    public function add(Request $request): Response
    {
        if ($request->method() === 'POST') {
            return $this->save($request);
        }

        return view('flolink/add');
    }

    /**
     * 编辑FloLink页面
     *
     * @param Request $request
     * @param int $id
     * @return Response
     */
    public function edit(Request $request, int $id): Response
    {
        $floLink = FloLink::find($id);
        if (!$floLink) {
            return $this->fail('浮动链接不存在');
        }

        if ($request->method() === 'POST') {
            return $this->save($request, $id);
        }

        return view('flolink/edit', ['floLink' => $floLink]);
    }

    /**
     * 保存FloLink数据
     *
     * @param Request $request
     * @param int|null $id
     * @return Response
     */
    private function save(Request $request, ?int $id = null): Response
    {
        $data = $request->post();

        // 验证必填字段
        if (empty($data['keyword']) || empty($data['url'])) {
            return $this->fail('关键词和链接地址为必填字段');
        }

        // URL验证
        if (!filter_var($data['url'], FILTER_VALIDATE_URL)) {
            return $this->fail('请输入有效的URL地址');
        }

        try {
            if ($id) {
                // 更新现有FloLink
                $floLink = FloLink::find($id);
                if (!$floLink) {
                    return $this->fail('浮动链接不存在');
                }

                // 检查关键词是否重复（排除当前记录）
                $existing = FloLink::where('keyword', $data['keyword'])
                    ->where('id', '!=', $id)
                    ->first();
                if ($existing) {
                    return $this->fail('该关键词已存在');
                }
            } else {
                // 创建新FloLink
                $floLink = new FloLink();

                // 检查关键词是否重复
                $existing = FloLink::where('keyword', $data['keyword'])->first();
                if ($existing) {
                    return $this->fail('该关键词已存在');
                }
            }

            // 处理布尔值字段
            $status = $this->parseBooleanForPostgres($data['status'] ?? true);
            $caseSensitive = $this->parseBooleanForPostgres($data['case_sensitive'] ?? false);
            $replaceExisting = $this->parseBooleanForPostgres($data['replace_existing'] ?? true);
            $enableHover = $this->parseBooleanForPostgres($data['enable_hover'] ?? true);

            // 处理自定义字段
            $customFields = [];
            if (!empty($data['custom_fields'])) {
                if (is_string($data['custom_fields'])) {
                    try {
                        $customFields = json_decode($data['custom_fields'], true) ?: [];
                    } catch (Exception $e) {
                        return $this->fail('自定义字段格式错误：' . $e->getMessage());
                    }
                } elseif (is_array($data['custom_fields'])) {
                    $customFields = $data['custom_fields'];
                }
            }

            // 填充数据
            $floLink->fill([
                'keyword' => htmlspecialchars($data['keyword'], ENT_QUOTES, 'UTF-8'),
                'url' => $data['url'],
                'title' => htmlspecialchars($data['title'] ?? '', ENT_QUOTES, 'UTF-8'),
                'description' => htmlspecialchars($data['description'] ?? '', ENT_QUOTES, 'UTF-8'),
                'image' => $data['image'] ?? '',
                'priority' => (int) ($data['priority'] ?? 100),
                'match_mode' => in_array($data['match_mode'] ?? 'first', ['first', 'all']) ? $data['match_mode'] : 'first',
                'case_sensitive' => $caseSensitive,
                'replace_existing' => $replaceExisting,
                'target' => $data['target'] ?? '_blank',
                'rel' => $data['rel'] ?? 'noopener noreferrer',
                'css_class' => $data['css_class'] ?? 'flo-link',
                'enable_hover' => $enableHover,
                'hover_delay' => (int) ($data['hover_delay'] ?? 200),
                'status' => $status,
                'sort_order' => (int) ($data['sort_order'] ?? 999),
                'custom_fields' => $customFields,
            ]);

            // 保存数据
            $saved = $floLink->save();

            if ($saved) {
                // 清除FloLink缓存
                FloLinkService::clearCache();

                // 返回更新后的数据
                $responseData = [
                    'id' => $floLink->id,
                    'keyword' => $floLink->keyword,
                    'url' => $floLink->url,
                    'status' => $floLink->status,
                    'updated_at' => $floLink->updated_at->format('Y-m-d H:i:s'),
                ];

                return $this->success($id ? 'FloLink更新成功' : 'FloLink添加成功', $responseData)
                    ->withHeaders([
                        'Cache-Control' => 'no-cache, no-store, must-revalidate',
                        'Pragma' => 'no-cache',
                        'Expires' => '0',
                    ]);
            }

            return $this->fail($id ? 'FloLink更新失败' : 'FloLink添加失败');
        } catch (Exception $e) {
            \support\Log::error('FloLink保存失败: ' . $e->getMessage());

            return $this->fail('系统错误，请稍后再试');
        }
    }

    /**
     * 软删除FloLink
     *
     * @param Request $request
     * @param int $id
     * @return Response
     * @throws Throwable
     */
    public function remove(Request $request, int $id): Response
    {
        $floLink = FloLink::find($id);
        if (!$floLink) {
            return $this->fail('FloLink不存在');
        }

        try {
            if ($floLink->softDelete() !== false) {
                FloLinkService::clearCache();

                return $this->success('FloLink已移至垃圾箱');
            }
        } catch (Exception $e) {
            \support\Log::error('FloLink删除失败: ' . $e->getMessage());

            return $this->fail('系统错误，请稍后再试');
        }

        return $this->fail('删除失败');
    }

    /**
     * 恢复软删除的FloLink
     *
     * @param Request $request
     * @param int $id
     * @return Response
     */
    public function restore(Request $request, int $id): Response
    {
        $floLink = FloLink::withTrashed()->find($id);
        if (!$floLink) {
            return $this->fail('FloLink不存在');
        }

        try {
            if ($floLink->restore()) {
                FloLinkService::clearCache();

                return $this->success('FloLink已恢复');
            }
        } catch (Exception $e) {
            \support\Log::error('FloLink恢复失败: ' . $e->getMessage());

            return $this->fail('系统错误，请稍后再试');
        }

        return $this->fail('恢复失败');
    }

    /**
     * 永久删除FloLink
     *
     * @param Request $request
     * @param int $id
     * @return Response
     * @throws Throwable
     */
    public function forceDelete(Request $request, int $id): Response
    {
        $floLink = FloLink::withTrashed()->find($id);
        if (!$floLink) {
            return $this->fail('FloLink不存在');
        }

        try {
            if ($floLink->softDelete(true) === true) {
                FloLinkService::clearCache();

                return $this->success('FloLink已永久删除');
            }
        } catch (Exception $e) {
            \support\Log::error('FloLink永久删除失败: ' . $e->getMessage());

            return $this->fail('系统错误，请稍后再试');
        }

        return $this->fail('删除失败');
    }

    /**
     * 批量恢复FloLink
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

        try {
            foreach ($idArray as $id) {
                $floLink = FloLink::withTrashed()->find($id);
                if ($floLink && $floLink->restore()) {
                    $count++;
                }
            }

            if ($count > 0) {
                FloLinkService::clearCache();
            }
        } catch (Exception $e) {
            \support\Log::error('批量恢复FloLink失败: ' . $e->getMessage());

            return $this->fail('系统错误，请稍后再试');
        }

        return $this->success("成功恢复 {$count} 个FloLink");
    }

    /**
     * 批量永久删除FloLink
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

        try {
            foreach ($idArray as $id) {
                $floLink = FloLink::withTrashed()->find($id);
                if ($floLink && $floLink->softDelete(true) === true) {
                    $count++;
                }
            }

            if ($count > 0) {
                FloLinkService::clearCache();
            }
        } catch (Exception $e) {
            \support\Log::error('批量永久删除FloLink失败: ' . $e->getMessage());

            return $this->fail('系统错误，请稍后再试');
        }

        return $this->success("成功永久删除 {$count} 个FloLink");
    }

    /**
     * 批量软删除FloLink
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

        try {
            foreach ($idArray as $id) {
                $floLink = FloLink::find($id);
                if ($floLink && $floLink->softDelete() !== false) {
                    $count++;
                }
            }

            if ($count > 0) {
                FloLinkService::clearCache();
            }
        } catch (Exception $e) {
            \support\Log::error('批量删除FloLink失败: ' . $e->getMessage());

            return $this->fail('系统错误，请稍后再试');
        }

        return $this->success("成功删除 {$count} 个FloLink");
    }

    /**
     * 获取单个FloLink信息
     *
     * @param Request $request
     * @param int $id
     * @return Response
     */
    public function get(Request $request, int $id): Response
    {
        $floLink = FloLink::find($id);
        if (!$floLink) {
            return $this->fail('FloLink不存在');
        }

        return $this->success('Success', $floLink->toArray())
            ->withHeaders([
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ]);
    }

    /**
     * 切换FloLink状态
     *
     * @param Request $request
     * @param int $id
     * @return Response
     */
    public function toggleStatus(Request $request, int $id): Response
    {
        $floLink = FloLink::find($id);
        if (!$floLink) {
            return $this->fail('FloLink不存在');
        }

        try {
            $floLink->status = !$floLink->status;
            if ($floLink->save()) {
                FloLinkService::clearCache();

                return $this->success('状态更新成功', [
                    'id' => $floLink->id,
                    'status' => $floLink->status,
                ]);
            }
        } catch (Exception $e) {
            \support\Log::error('FloLink状态切换失败: ' . $e->getMessage());

            return $this->fail('系统错误，请稍后再试');
        }

        return $this->fail('状态更新失败');
    }

    /**
     * 清除FloLink缓存
     *
     * @param Request $request
     * @return Response
     */
    public function clearCache(Request $request): Response
    {
        try {
            FloLinkService::clearCache();

            return $this->success('缓存清除成功');
        } catch (Exception $e) {
            \support\Log::error('FloLink缓存清除失败: ' . $e->getMessage());

            return $this->fail('缓存清除失败');
        }
    }

    /**
     * PostgreSQL布尔值处理
     */
    private function parseBooleanForPostgres($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = strtolower(trim($value));

            return in_array($value, ['1', 'true', 'on', 'yes', 't']);
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        return false;
    }
}
