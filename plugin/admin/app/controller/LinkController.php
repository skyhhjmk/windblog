<?php

namespace plugin\admin\app\controller;

use app\model\Link;
use app\service\CacheService;
use app\service\LinkPriorityService;
use app\service\LinkPushQueueService;
use app\service\MQService;
use DOMDocument;
use DOMXPath;
use Exception;
use PhpAmqpLib\Message\AMQPMessage;
use support\Log;
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
        $page = (int) $request->get('page', 1);
        $limit = (int) $request->get('limit', 15);
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

        // 返回列表数据（无缓存）
        return $this->success('成功', $list, $total)
            ->withHeaders([
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ]);
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
            !preg_match('/^https?:\/\/[a-zA-Z0-9][-a-zA-Z0-9]{0,62}(\.[a-zA-Z0-9][-a-zA-Z0-9]{0,62})+(:[0-9]{1,5})?(\/[-a-zA-Z0-9()@:%_\+.~#\?&\/=]*)?$/', $data['url'])) {
            return $this->fail('请输入有效的URL地址');
        }

        // 图标URL验证（如果提供）
        if (!empty($data['icon'])) {
            if (!filter_var($data['icon'], FILTER_VALIDATE_URL) ||
                !preg_match('/^https?:\/\/[a-zA-Z0-9][-a-zA-Z0-9]{0,62}(\.[a-zA-Z0-9][-a-zA-Z0-9]{0,62})+(:[0-9]{1,5})?(\/[-a-zA-Z0-9()@:%_\+.~#\?&\/=]*)?$/', $data['icon'])) {
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
                $existing = Link::where('url', $data['url'])
                    ->where('id', '!=', $id)
                    ->first();
                if ($existing) {
                    return $this->fail('该URL已存在');
                }

                // 检查是否为审核操作
                $isApproval = !$link->status && isset($data['status']) && (bool) $data['status'] === true;
            } else {
                // 创建新链接
                $link = new Link();
                $isApproval = false;

                // 检查URL是否重复
                $existing = Link::where('url', $data['url'])->first();
                if ($existing) {
                    return $this->fail('该URL已存在');
                }
            }

            // 处理status字段 - PostgreSQL兼容的布尔值转换
            $status = $this->parseBooleanForPostgres($data['status'] ?? false);

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

            // 处理管理员笔记（存储到自定义字段中）
            if (isset($data['note'])) {
                $customFields['admin_note'] = $data['note'];
            } elseif (isset($data['admin_note'])) {
                $customFields['admin_note'] = $data['admin_note'];
            }

            // 填充数据
            $link->fill([
                'name' => htmlspecialchars($data['name'], ENT_QUOTES, 'UTF-8'),
                'url' => $data['url'],
                'description' => htmlspecialchars($data['description'] ?? '', ENT_QUOTES, 'UTF-8'),
                'icon' => $data['icon'] ?? '',
                'image' => $data['image'] ?? '',
                'email' => $data['email'] ?? '',
                'sort_order' => (int) ($data['sort_order'] ?? 999),
                'status' => $status,
                'target' => $data['target'] ?? '_blank',
                'redirect_type' => $data['redirect_type'] ?? 'direct',
                'show_url' => (bool) ($data['show_url'] ?? true),
                'content' => $data['content'] ?? '',
                'note' => $data['note'] ?? '',
                'seo_title' => $data['seo_title'] ?? '',
                'seo_keywords' => $data['seo_keywords'] ?? '',
                'seo_description' => $data['seo_description'] ?? '',
                'custom_fields' => $customFields,
            ]);

            // 调试信息
            Log::info('Link save data: ', [
                'id' => $link->id ?? 'new',
                'name' => $link->name,
                'status' => $link->status,
                'status_type' => gettype($link->status),
                'original_status' => $data['status'] ?? 'not_set',
            ]);

            // 如果是审核通过操作，添加审核记录
            if ($isApproval && strpos($link->content, '## 申请信息') !== false) {
                $adminUser = $request->session()->get('admin_user');
                $adminName = $adminUser['name'] ?? '管理员';

                // 更新审核记录
                $link->content = str_replace(
                    '### 审核记录',
                    "### 审核记录\n\n> 已审核通过 - {$adminName} - " . utc_now_string('Y-m-d H:i:s'),
                    $link->content
                );
            }

            // 保存数据
            $saved = $link->save();

            // 调试保存结果
            Log::info('Link save result: ', [
                'saved' => $saved,
                'id' => $link->id,
                'status_after_save' => $link->status,
                'dirty' => $link->getDirty(),
                'changes' => $link->getChanges(),
            ]);

            if ($saved) {
                // 清除相关缓存
                $this->clearLinkCache();

                // === CAT4* + CAT5* 集成 ===
                if ($link->status) {
                    $source = $link->getCustomField('source', '');

                    // 如果是审核通过操作
                    if ($isApproval && in_array($source, ['wind_connect', 'wind_connect_backlink'])) {
                        // CAT4* 自动排序
                        try {
                            $updated = LinkPriorityService::updateSortOrder([$link->id]);
                            Log::info("友链审核通过，触发自动排序 - Link ID: {$link->id}, Updated: {$updated}");
                        } catch (Throwable $e) {
                            Log::error("自动排序失败 - Link ID: {$link->id}, Error: " . $e->getMessage());
                        }

                        // CAT5* 双向确认后推送
                        if ($source === 'wind_connect') {
                            $this->checkAndEnqueuePush($link);
                        }
                    }

                    Log::info("链接保存成功（已启用） - ID: {$link->id}, Name: {$link->name}, Source: {$source}");
                }

                // 返回更新后的数据
                $responseData = [
                    'id' => $link->id,
                    'name' => $link->name,
                    'url' => $link->url,
                    'status' => $link->status,
                    'is_pending' => !$link->status,
                    'updated_at' => $link->updated_at->format('Y-m-d H:i:s'),
                ];

                // 返回成功响应（无缓存）
                return $this->success($id ? '链接更新成功' : '链接添加成功', $responseData)
                    ->withHeaders([
                        'Cache-Control' => 'no-cache, no-store, must-revalidate',
                        'Pragma' => 'no-cache',
                        'Expires' => '0',
                    ]);
            }

            return $this->fail($id ? '链接更新失败' : '链接添加失败');
        } catch (Exception $e) {
            Log::error('链接保存失败: ' . $e->getMessage());

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
        } catch (Exception $e) {
            Log::error('链接删除失败: ' . $e->getMessage());

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
        } catch (Exception $e) {
            Log::error('链接恢复失败: ' . $e->getMessage());

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
        } catch (Exception $e) {
            Log::error('链接永久删除失败: ' . $e->getMessage());

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
        } catch (Exception $e) {
            Log::error('批量恢复链接失败: ' . $e->getMessage());

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
        } catch (Exception $e) {
            Log::error('批量永久删除链接失败: ' . $e->getMessage());

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
        } catch (Exception $e) {
            Log::error('批量删除链接失败: ' . $e->getMessage());

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

        // 返回链接数据（无缓存）
        return $this->success('Success', $linkData)
            ->withHeaders([
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ]);
    }

    /**
     * 批量审核链接
     *
     * @param Request $request
     * @param string  $ids
     *
     * @return Response
     * @throws Exception
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
                    if ($link->note && str_contains($link->note, '## 申请信息')) {
                        $link->note = str_replace(
                            '### 审核记录',
                            "### 审核记录\n\n> 已审核通过 - {$adminName} - " . utc_now_string('Y-m-d H:i:s'),
                            $link->note
                        );
                    }

                    $link->status = $this->parseBooleanForPostgres(true);
                    if ($link->save()) {
                        $count++;
                        $source = $link->getCustomField('source', '');

                        // === CAT4* + CAT5* 集成 ===
                        if (in_array($source, ['wind_connect', 'wind_connect_backlink'])) {
                            // CAT4* 自动排序
                            try {
                                $updated = LinkPriorityService::updateSortOrder([$link->id]);
                                Log::info("友链审核通过，触发自动排序 - Link ID: {$link->id}, Updated: {$updated}");
                            } catch (Throwable $e) {
                                Log::error("自动排序失败 - Link ID: {$link->id}, Error: " . $e->getMessage());
                            }

                            // CAT5* 双向确认后推送
                            if ($source === 'wind_connect') {
                                $this->checkAndEnqueuePush($link);
                            }
                        }

                        Log::info("链接审核通过 - ID: {$link->id}, Name: {$link->name}, Source: {$source}");
                    }
                }
            }

            if ($count > 0) {
                // 清除前台链接列表缓存
                $this->clearLinkCache();
            }
        } catch (Exception $e) {
            Log::error('批量审核链接失败: ' . $e->getMessage());

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
                    if ($link->note && str_contains($link->note, '## 申请信息')) {
                        $link->note = str_replace(
                            '### 审核记录',
                            "### 审核记录\n\n> 已拒绝 - {$adminName} - " . utc_now_string('Y-m-d H:i:s') . "\n> 原因：{$reason}",
                            $link->note
                        );
                        $link->save();
                    }

                    // 软删除链接
                    if ($link->softDelete() !== false) {
                        $count++;
                    }
                }
            }
        } catch (Exception $e) {
            Log::error('批量拒绝链接失败: ' . $e->getMessage());

            return $this->fail('系统错误，请稍后再试');
        }

        return $this->success("成功拒绝 {$count} 个链接申请");
    }

    /**
     * 友链审核工作台
     *
     * @param Request $request
     * @param int     $id
     *
     * @return Response
     */
    public function audit(Request $request, int $id): Response
    {
        $link = Link::find($id);
        if (!$link) {
            return $this->fail('链接不存在');
        }

        return view('link/audit', ['link' => $link]);
    }

    /**
     * 检测目标网站信息
     *
     * @param Request $request
     *
     * @return Response
     */
    public function detectSite(Request $request): Response
    {
        $url = $request->post('url');
        $myDomain = $request->post('my_domain', ''); // 本站域名，用于反向链接检查
        $linkPosition = $request->post('link_position', ''); // 友链位置
        $pageLink = $request->post('page_link', ''); // 友链页面链接

        if (empty($url)) {
            return $this->fail('URL不能为空');
        }

        // 初始化结果数组
        $result = [
            'url' => $url,
            'status' => 'success',
            'site_info' => [],
            'backlink_check' => [],
            'performance' => [],
            'security' => [],
            'seo' => [],
            'errors' => [],
        ];

        // 获取网页内容
        $fetchResult = $this->fetchWebContent($url);
        if (!$fetchResult['success']) {
            $result['status'] = 'error';
            $result['errors'][] = $fetchResult['error'];

            return $this->success('检测完成', $result)
                ->withHeaders([
                    'Cache-Control' => 'no-cache, no-store, must-revalidate',
                    'Pragma' => 'no-cache',
                    'Expires' => '0',
                ]);
        }

        $html = $fetchResult['html'];
        $loadTime = $fetchResult['load_time'];

        // 解析HTML
        $parseResult = $this->parseHtmlContent($html);
        if (!$parseResult['success']) {
            $result['errors'][] = $parseResult['error'];
        }

        $dom = $parseResult['dom'] ?? null;
        $xpath = $parseResult['xpath'] ?? null;

        // 各个检测器独立运行，互不影响
        $result['site_info'] = $this->runSiteInfoDetector($dom, $xpath, $url);
        $result['performance'] = $this->runPerformanceDetector($url, $html, $loadTime);
        $result['seo'] = $this->runSeoDetector($dom, $xpath);
        $result['security'] = $this->runSecurityDetector($url, $html);

        // 反向链接检查：如果有友链页面且不是首页，同时检测首页和友链页
        if (!empty($myDomain)) {
            $backlinkResult = $this->runBacklinkDetector($html, $myDomain, $url);

            // 如果有友链页面且不是首页，需要额外检测友链页面
            if (!empty($pageLink) && $linkPosition !== 'homepage') {
                try {
                    $pageFetch = $this->fetchWebContent($pageLink);
                    if ($pageFetch['success']) {
                        $pageBacklink = $this->runBacklinkDetector($pageFetch['html'], $myDomain, $pageLink);

                        // 合并反链结果：只要其中一个页面找到反链就认为找到了
                        if ($pageBacklink['found'] ?? false) {
                            $backlinkResult['found'] = true;
                            $backlinkResult['link_count'] = ($backlinkResult['link_count'] ?? 0) + ($pageBacklink['link_count'] ?? 0);
                            $backlinkResult['links'] = array_merge(
                                $backlinkResult['links'] ?? [],
                                $pageBacklink['links'] ?? []
                            );
                            $backlinkResult['page_link_checked'] = true;
                            $backlinkResult['page_link_url'] = $pageLink;
                        } else {
                            $backlinkResult['page_link_checked'] = true;
                            $backlinkResult['page_link_url'] = $pageLink;
                            $backlinkResult['page_link_found'] = false;
                        }
                    } else {
                        $result['errors'][] = '无法访问友链页面：' . $pageFetch['error'];
                    }
                } catch (Exception $e) {
                    $result['errors'][] = '检测友链页面异常：' . $e->getMessage();
                }
            }

            $result['backlink_check'] = $backlinkResult;
        }

        return $this->success('检测完成', $result)
            ->withHeaders([
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ]);
    }

    /**
     * 获取网页内容
     */
    private function fetchWebContent(string $url): array
    {
        try {
            // 设置HTTP上下文选项
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 30,
                    'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                    'follow_location' => true,
                    'max_redirects' => 5,
                    'ignore_errors' => true,
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ]);

            $startTime = microtime(true);
            $html = @file_get_contents($url, false, $context);
            $loadTime = round((microtime(true) - $startTime) * 1000, 2);

            if ($html === false) {
                return [
                    'success' => false,
                    'error' => '无法访问目标网站',
                ];
            }

            return [
                'success' => true,
                'html' => $html,
                'load_time' => $loadTime,
            ];
        } catch (Exception $e) {
            Log::error('获取网页内容失败: ' . $e->getMessage());

            return [
                'success' => false,
                'error' => '网络请求失败: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * 解析HTML内容
     */
    private function parseHtmlContent(string $html): array
    {
        try {
            $dom = new DOMDocument();
            @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            $xpath = new DOMXPath($dom);

            return [
                'success' => true,
                'dom' => $dom,
                'xpath' => $xpath,
            ];
        } catch (Exception $e) {
            Log::error('HTML解析失败: ' . $e->getMessage());

            return [
                'success' => false,
                'error' => 'HTML解析失败: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * 网站信息检测器
     */
    private function runSiteInfoDetector($dom, $xpath, string $url): array
    {
        try {
            if (!$dom || !$xpath) {
                return ['error' => 'HTML解析失败，无法获取网站信息'];
            }

            return $this->extractSiteInfo($dom, $xpath, $url);
        } catch (Exception $e) {
            Log::error('网站信息检测失败: ' . $e->getMessage());

            return ['error' => '网站信息检测失败: ' . $e->getMessage()];
        }
    }

    /**
     * 性能检测器
     */
    private function runPerformanceDetector(string $url, string $html, float $loadTime): array
    {
        try {
            return [
                'load_time' => $loadTime . 'ms',
                'content_size' => strlen($html) . ' bytes',
                'response_headers' => $this->getResponseHeaders($url),
            ];
        } catch (Exception $e) {
            Log::error('性能检测失败: ' . $e->getMessage());

            return ['error' => '性能检测失败: ' . $e->getMessage()];
        }
    }

    /**
     * SEO检测器
     */
    private function runSeoDetector($dom, $xpath): array
    {
        try {
            if (!$dom || !$xpath) {
                return ['error' => 'HTML解析失败，无法进行SEO检测'];
            }

            return $this->extractSeoInfo($dom, $xpath);
        } catch (Exception $e) {
            Log::error('SEO检测失败: ' . $e->getMessage());

            return ['error' => 'SEO检测失败: ' . $e->getMessage()];
        }
    }

    /**
     * 安全检测器
     */
    private function runSecurityDetector(string $url, string $html): array
    {
        try {
            return $this->checkSecurity($url, $html);
        } catch (Exception $e) {
            Log::error('安全检测失败: ' . $e->getMessage());

            return ['error' => '安全检测失败: ' . $e->getMessage()];
        }
    }

    /**
     * 反向链接检测器
     */
    private function runBacklinkDetector(string $html, string $myDomain, string $url): array
    {
        try {
            return $this->checkBacklink($html, $myDomain, $url);
        } catch (Exception $e) {
            Log::error('反向链接检测失败: ' . $e->getMessage());

            return ['error' => '反向链接检测失败: ' . $e->getMessage()];
        }
    }

    /**
     * 提取网站基本信息
     */
    private function extractSiteInfo(DOMDocument $dom, DOMXPath $xpath, string $url): array
    {
        $info = [];

        // 标题
        $titleNodes = $xpath->query('//title');
        $info['title'] = $titleNodes->length > 0 ? trim($titleNodes->item(0)->textContent) : '';

        // 描述
        $descNodes = $xpath->query('//meta[@name="description"]/@content');
        $info['description'] = $descNodes->length > 0 ? $descNodes->item(0)->value : '';

        // 关键词
        $keywordNodes = $xpath->query('//meta[@name="keywords"]/@content');
        $info['keywords'] = $keywordNodes->length > 0 ? $keywordNodes->item(0)->value : '';

        // 图标
        $iconNodes = $xpath->query('//link[@rel="icon" or @rel="shortcut icon"]/@href');
        if ($iconNodes->length > 0) {
            $iconUrl = $iconNodes->item(0)->value;
            $info['favicon'] = $this->resolveUrl($iconUrl, $url);
        } else {
            $info['favicon'] = $this->resolveUrl('/favicon.ico', $url);
        }

        // 语言
        $langNodes = $xpath->query('//html/@lang');
        $info['language'] = $langNodes->length > 0 ? $langNodes->item(0)->value : '';

        // 字符集
        $charsetNodes = $xpath->query('//meta[@charset]/@charset');
        if ($charsetNodes->length === 0) {
            $charsetNodes = $xpath->query('//meta[@http-equiv="Content-Type"]/@content');
            if ($charsetNodes->length > 0) {
                preg_match('/charset=([^;]+)/i', $charsetNodes->item(0)->value, $matches);
                $info['charset'] = $matches[1] ?? '';
            }
        } else {
            $info['charset'] = $charsetNodes->item(0)->value;
        }

        return $info;
    }

    /**
     * 提取SEO信息
     */
    private function extractSeoInfo(DOMDocument $dom, DOMXPath $xpath): array
    {
        $seo = [];

        // H1标签
        $h1Nodes = $xpath->query('//h1');
        $seo['h1_count'] = $h1Nodes->length;
        $seo['h1_texts'] = [];
        for ($i = 0; $i < min(3, $h1Nodes->length); $i++) {
            $seo['h1_texts'][] = trim($h1Nodes->item($i)->textContent);
        }

        // 图片alt属性
        $imgNodes = $xpath->query('//img');
        $seo['img_total'] = $imgNodes->length;
        $seo['img_without_alt'] = $xpath->query('//img[not(@alt) or @alt=""]')->length;

        // 链接
        $linkNodes = $xpath->query('//a[@href]');
        $seo['link_total'] = $linkNodes->length;
        $seo['external_links'] = 0;
        $seo['internal_links'] = 0;

        for ($i = 0; $i < $linkNodes->length; $i++) {
            $href = $linkNodes->item($i)->getAttribute('href');
            if (preg_match('/^https?:\/\//', $href)) {
                $seo['external_links']++;
            } else {
                $seo['internal_links']++;
            }
        }

        return $seo;
    }

    /**
     * 检查反向链接
     */
    private function checkBacklink(string $html, string $myDomain, string $targetUrl): array
    {
        $result = [
            'found' => false,
            'links' => [],
            'domain_mentioned' => false,
            'link_count' => 0,
        ];

        // 清理域名（移除协议和www）
        $cleanDomain = preg_replace('/^(https?:\/\/)?(www\.)?/', '', $myDomain);
        $cleanDomain = rtrim($cleanDomain, '/');

        // 检查是否提到了域名
        if (stripos($html, $cleanDomain) !== false) {
            $result['domain_mentioned'] = true;
        }

        // 使用正则表达式查找链接
        preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>([^<]*)<\/a>/i', $html, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $href = $match[1];
            $text = trim($match[2]);

            // 检查链接是否指向我们的域名
            if (stripos($href, $cleanDomain) !== false) {
                $result['found'] = true;
                $result['links'][] = [
                    'url' => $href,
                    'text' => $text,
                    'full_tag' => $match[0],
                ];
                $result['link_count']++;
            }
        }

        return $result;
    }

    /**
     * 安全检查
     */
    private function checkSecurity(string $url, string $html): array
    {
        $security = [];

        // 检查HTTPS
        $security['https'] = strpos($url, 'https://') === 0;

        // 检查CSP头
        $headers = $this->getResponseHeaders($url);
        $security['csp'] = isset($headers['content-security-policy']);

        // 检查X-Frame-Options
        $security['x_frame_options'] = isset($headers['x-frame-options']);

        // 检查可疑内容
        $suspiciousPatterns = [
            'eval\\s*\\(',
            'document\\.write\\s*\\(',
            'innerHTML\\s*=',
            // 修复：简化正则表达式，检查 script src 中是否有非标准 URL 字符
            '<script[^>]*src=["\'][^"\']*(javascript:|data:)[^"\']["\']',
        ];

        $security['suspicious_content'] = [];
        foreach ($suspiciousPatterns as $pattern) {
            // 由于 $pattern 已经是转义后的字符串，使用 @ 抑制错误并检查结果
            $result = @preg_match('/' . $pattern . '/i', $html);
            if ($result !== false && $result > 0) {
                $security['suspicious_content'][] = $pattern;
            }
        }

        return $security;
    }

    /**
     * 获取响应头
     */
    private function getResponseHeaders(string $url): array
    {
        $headers = [];
        try {
            $context = stream_context_create([
                'http' => [
                    'method' => 'HEAD',
                    'timeout' => 10,
                    'user_agent' => 'Mozilla/5.0 (compatible; LinkChecker/1.0)',
                ],
            ]);

            $result = @get_headers($url, 1, $context);
            if ($result) {
                foreach ($result as $key => $value) {
                    if (is_string($key)) {
                        $headers[strtolower($key)] = $value;
                    }
                }
            }
        } catch (Exception $e) {
            // 忽略错误
        }

        return $headers;
    }

    /**
     * 解析相对URL为绝对URL
     */
    private function resolveUrl(string $relativeUrl, string $baseUrl): string
    {
        if (preg_match('/^https?:\/\//', $relativeUrl)) {
            return $relativeUrl;
        }

        $parsedBase = parse_url($baseUrl);
        $scheme = $parsedBase['scheme'] ?? 'http';
        $host = $parsedBase['host'] ?? '';

        if (strpos($relativeUrl, '/') === 0) {
            return $scheme . '://' . $host . $relativeUrl;
        }

        $path = dirname($parsedBase['path'] ?? '/');

        return $scheme . '://' . $host . rtrim($path, '/') . '/' . $relativeUrl;
    }

    /**
     * 清除前台链接列表缓存
     */
    private function clearLinkCache(): void
    {
        try {
            // 针对不同缓存驱动的兼容处理
            if (method_exists(CacheService::class, 'clearCache')) {
                CacheService::clearCache('blog_links_page_*');
            }
        } catch (Exception $e) {
            Log::warning('清除链接缓存失败: ' . $e->getMessage());
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

    /**
     * CAT2: 友链监控（按ID或URL检测）
     * POST: ids[]=1&ids[]=2 或 urls[]=https://...
     */
    public function monitor(Request $request): Response
    {
        $ids = (array) $request->post('ids', []);
        $urls = (array) $request->post('urls', []);
        $myDomain = blog_config('site_url', '', true);

        $targets = [];
        if (!empty($ids)) {
            $links = Link::whereIn('id', $ids)->get();
            foreach ($links as $l) {
                $targets[] = ['id' => $l->id, 'url' => $l->url, 'name' => $l->name];
            }
        }
        foreach ($urls as $u) {
            if (is_string($u) && $u !== '') {
                $targets[] = ['id' => null, 'url' => $u, 'name' => ''];
            }
        }

        $accepted = 0;
        foreach ($targets as $t) {
            if (!filter_var($t['url'], FILTER_VALIDATE_URL)) {
                continue;
            }
            $payload = [
                'link_id' => $t['id'],
                'url' => $t['url'],
                'name' => $t['name'],
                'my_domain' => $myDomain,
                'trigger' => 'admin_monitor',
                'timestamp' => time(),
            ];
            try {
                // 发布到 link_monitor 队列
                $exchange = (string) blog_config('rabbitmq_link_monitor_exchange', 'link_monitor_exchange', true);
                $routingKey = (string) blog_config('rabbitmq_link_monitor_routing_key', 'link_monitor', true);
                $channel = MQService::getChannel();
                $msg = new AMQPMessage(json_encode($payload, JSON_UNESCAPED_UNICODE), [
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    'content_type' => 'application/json',
                ]);
                $channel->basic_publish($msg, $exchange, $routingKey);
                $accepted++;
            } catch (Throwable $e) {
                Log::warning('enqueue link monitor failed: ' . $e->getMessage());
            }
        }

        return $this->success('任务已入队', [
            'accepted' => $accepted,
            'total' => count($targets),
        ])->withHeaders([
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    /**
     * CAT4: 自动审核并计算优先级
     * 根据反链与页面语义评分，达阈值则通过并设置排序
     */
    public function autoAudit(Request $request, int $id): Response
    {
        $link = Link::find($id);
        if (!$link) {
            return $this->fail('链接不存在');
        }

        $myDomain = blog_config('site_url', '', true);
        if (empty($myDomain)) {
            return $this->fail('未配置本站域名(site_url)');
        }

        $fetch = $this->fetchWebContent($link->url);
        if (!$fetch['success']) {
            return $this->fail('目标不可访问：' . $fetch['error']);
        }
        $html = $fetch['html'];
        $backlink = $this->checkBacklink($html, $myDomain, $link->url);

        $score = $this->scoreBacklink($backlink, $html);
        // 阈值可配置：link_auto_audit_threshold（默认50）；排序=1000-得分（越前）
        $threshold = (int) blog_config('link_auto_audit_threshold', 50, true);
        $pass = $score >= $threshold;

        if ($pass) {
            $link->status = true;
            // 只在默认排序时根据评分前置
            if ((int) $link->sort_order === 999) {
                $link->sort_order = max(1, 1000 - $score);
            }
            $link->setCustomField('auto_audit', [
                'score' => $score,
                'time' => utc_now_string('Y-m-d H:i:s'),
            ]);
            $link->save();
            $this->clearLinkCache();
        }

        return $this->success('自动审核完成', [
            'id' => $link->id,
            'score' => $score,
            'approved' => $pass,
            'sort_order' => $link->sort_order,
            'status' => $link->status,
        ]);
    }

    /**
     * CAT5: 已建立关系后静默推送扩展信息
     * 若存在 custom_fields.peer_api 则POST推送
     */
    public function pushExtendedInfo(Request $request, int $id): Response
    {
        $link = Link::find($id);
        if (!$link) {
            return $this->fail('链接不存在');
        }
        if (!$link->status) {
            return $this->fail('尚未建立友链关系');
        }
        try {
            $ok = $this->pushExtendedInfoInternal($link);
            if (!$ok['success']) {
                return $this->fail('推送失败：' . $ok['error']);
            }

            return $this->success('推送完成', [
                'peer_api' => $ok['peer_api'] ?? '',
                'status' => 'ok',
            ]);
        } catch (Throwable $e) {
            return $this->fail('推送异常：' . $e->getMessage());
        }
    }

    /**
     * 内部静默推送（供审核通过后自动触发）
     */
    private function pushExtendedInfoInternal(Link $link): array
    {
        $peerApi = $link->getCustomField('peer_api', '');
        if (empty($peerApi)) {
            return ['success' => false, 'error' => '未配置对方接收API'];
        }
        $payload = [
            'type' => 'wind_connect_push',
            'site' => [
                'name' => blog_config('title', 'WindBlog', true),
                'url' => blog_config('site_url', '', true),
                'description' => blog_config('description', '', true),
                'icon' => blog_config('favicon', '', true),
                'protocol' => 'CAT5',
                'version' => '1.0',
            ],
            'link' => [
                'name' => $link->name,
                'url' => $link->url,
                'icon' => $link->icon,
                'description' => $link->description,
                'tags' => $link->getCustomField('tags', []),
            ],
            'timestamp' => time(),
        ];
        $res = $this->httpPostJson($peerApi, $payload);
        if ($res['success']) {
            $link->setCustomField('peer_last_push', utc_now_string('Y-m-d H:i:s'));
            $link->save();

            return ['success' => true, 'peer_api' => $peerApi];
        }

        return ['success' => false, 'error' => $res['error'] ?? 'unknown'];
    }

    /**
     * 反链评分：存在反链基础分；包含“友链/links/friend”等语义包裹再加分；出现多次再加分
     */
    private function scoreBacklink(array $backlink, string $html): int
    {
        $score = 0;
        if ($backlink['found'] ?? false) {
            $score += 40;
            $count = (int) ($backlink['link_count'] ?? 1);
            $score += min(20, $count * 5);
        }
        // 语义加权：section/container含friend/links等
        if (preg_match('/(friend|links|友情链接|友链)/i', $html)) {
            $score += 20;
        }

        // 主页加载速度（可选，已有性能检测返回给前端展示，不直接计入）
        return min(100, $score);
    }

    /**
     * 简易JSON POST（禁用SSL验证，遵循项目记忆）
     */
    private function httpPostJson(string $url, array $payload): array
    {
        try {
            $opts = [
                'http' => [
                    'method' => 'POST',
                    'timeout' => 30,
                    'header' => 'Content-Type: application/json

',
                    'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ];
            $context = stream_context_create($opts);
            $result = @file_get_contents($url, false, $context);
            if ($result === false) {
                return ['success' => false, 'error' => '请求失败'];
            }

            return ['success' => true, 'body' => (string) $result];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 将推送任务入队
     * 只对 wind_connect 来源的友链进行异步推送
     */
    private function enqueuePushTask(Link $link): void
    {
        try {
            $peerApi = $link->getCustomField('peer_api', '');
            if (empty($peerApi)) {
                Log::warning("无法入队推送任务 - Link ID: {$link->id}, 原因: 未配置 peer_api");

                return;
            }

            $payload = [
                'type' => 'wind_connect_push',
                'site' => [
                    'name' => blog_config('title', 'WindBlog', true),
                    'url' => blog_config('site_url', '', true),
                    'description' => blog_config('description', '', true),
                    'icon' => blog_config('favicon', '', true),
                    'protocol' => 'CAT5',
                    'version' => '1.0',
                ],
                'link' => [
                    'name' => $link->name,
                    'url' => $link->url,
                    'icon' => $link->icon,
                    'description' => $link->description,
                    'tags' => $link->getCustomField('tags', []),
                ],
                'timestamp' => time(),
            ];

            $result = LinkPushQueueService::enqueue($link->id, $peerApi, $payload);

            if ($result['code'] === 0) {
                Log::info("推送任务已入队 - Link ID: {$link->id}, Task ID: {$result['task_id']}");
            } else {
                Log::error("推送任务入队失败 - Link ID: {$link->id}, 错误: {$result['msg']}");
            }
        } catch (Throwable $e) {
            Log::error("推送任务入队异常 - Link ID: {$link->id}, 错误: " . $e->getMessage());
        }
    }

    /**
     * CAT5* 检查并推送（只在双方都审核通过后触发）
     *
     * @param Link $link 本站的友链记录
     */
    private function checkAndEnqueuePush(Link $link): void
    {
        try {
            // 获取对方的回链ID
            $peerBacklinkId = $link->getCustomField('peer_backlink_id');
            $peerApi = $link->getCustomField('peer_api', '');

            if (empty($peerApi)) {
                Log::info("跳过 CAT5 推送 - Link ID: {$link->id}, 原因: 未配置 peer_api");

                return;
            }

            // 如果没有 peer_backlink_id，说明对方还未创建回链，不推送
            if (empty($peerBacklinkId)) {
                Log::info("跳过 CAT5 推送 - Link ID: {$link->id}, 原因: 对方未创建回链，关系未建立");

                return;
            }

            // TODO: 可以进一步检查对方回链的状态（通过 API 查询）
            // 但这里先假设对方已经创建了回链（peer_backlink_id 存在）

            // 检查是否已经推送过
            $lastPush = $link->getCustomField('peer_last_push');
            if (!empty($lastPush)) {
                Log::info("跳过 CAT5 推送 - Link ID: {$link->id}, 原因: 已经推送过，上次推送: {$lastPush}");

                return;
            }

            // 双方关系已建立，触发推送
            Log::info("双向友链关系已建立，触发 CAT5 推送 - Link ID: {$link->id}, Peer Backlink ID: {$peerBacklinkId}");
            $this->enqueuePushTask($link);

        } catch (Throwable $e) {
            Log::error("检查并推送异常 - Link ID: {$link->id}, Error: " . $e->getMessage());
        }
    }
}
