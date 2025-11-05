<?php

namespace app\controller;

use app\model\Link;
use app\service\LinkConnectQueueService;
use app\service\LinkConnectService;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 新版friendApply实现
 * 使用异步队列+轮询机制
 *
 * 使用方法：将此文件中的 connectApply 和 checkTaskStatus 方法
 * 复制到 LinkController.php 中替换原有方法
 */
class LinkConnectApplyNew
{
    /**
     * 不需要登录的方法
     * connectApply: 发起友链互联申请，公开访问
     * checkTaskStatus: 查询任务状态，公开访问
     */
    protected array $noNeedLogin = ['connectApply', 'checkTaskStatus'];

    /**
     * CAT3*: 发起友链申请（异步处理+轮询机制）
     *
     * 流程：
     * 1. 优先验证token（如果是快速互联URL）
     * 2. 验证URL是否已存在
     * 3. 创建Link记录(pending状态)
     * 4. 入队异步处理
     * 5. 返回task_id供前端轮询
     */
    public function connectApply(Request $request): Response
    {
        if ($request->method() !== 'POST') {
            return json(['code' => 1, 'msg' => '仅支持POST']);
        }

        // 解析请求数据
        $jsonBody = [];
        if (stripos((string) $request->header('Content-Type', ''), 'application/json') !== false) {
            $jsonBody = json_decode((string) $request->rawBody(), true) ?: [];
        }

        $peerApi = trim((string) ($jsonBody['peer_api'] ?? $request->post('peer_api', '')));

        // 提取基本字段
        $name = trim((string) ($jsonBody['name'] ?? $request->post('name', '')));
        $url = trim((string) ($jsonBody['url'] ?? $request->post('url', '')));
        $icon = trim((string) ($jsonBody['icon'] ?? $request->post('icon', '')));
        $description = trim((string) ($jsonBody['description'] ?? $request->post('description', '')));
        $email = trim((string) ($jsonBody['email'] ?? $request->post('email', '')));

        // 兼容site结构
        $site = is_array($jsonBody['site'] ?? null) ? $jsonBody['site'] : [];
        $name = $name ?: trim((string) ($site['name'] ?? ''));
        $url = $url ?: trim((string) ($site['url'] ?? ''));
        $icon = $icon ?: trim((string) ($site['icon'] ?? ''));
        $description = $description ?: trim((string) ($site['description'] ?? ''));
        $email = $email ?: trim((string) ($site['email'] ?? ''));

        // 参数长度验证
        if (strlen($name) > 100) {
            return json(['code' => 1, 'msg' => '站点名称过长']);
        }
        if (strlen($url) > 500) {
            return json(['code' => 1, 'msg' => '站点链接过长']);
        }
        if (strlen($icon) > 500) {
            return json(['code' => 1, 'msg' => '图标地址过长']);
        }
        if (strlen($description) > 500) {
            return json(['code' => 1, 'msg' => '站点描述过长']);
        }
        if (strlen($email) > 100) {
            return json(['code' => 1, 'msg' => '邮箱地址过长']);
        }

        $extractedToken = null;

        // === 步骤1: 提取并验证token（仅查状态，不做远程调用） ===
        if ($peerApi) {
            try {
                $parsedUrl = parse_url($peerApi);
                $queryParams = [];
                if (isset($parsedUrl['query'])) {
                    parse_str($parsedUrl['query'], $queryParams);
                }

                // 如果是快速互联URL（带token）
                if (!empty($queryParams['token'])) {
                    $extractedToken = $queryParams['token'];

                    Log::info('检测到快速互联URL，token: ' . substr($extractedToken, 0, 8) . '...');

                    // 【关键】仅验证token状态（不做远程调用）
                    $tokens = LinkConnectService::listTokens();
                    $tokenValid = false;
                    $tokenError = null;

                    foreach ($tokens as $t) {
                        if ($t['token'] === $extractedToken) {
                            if ($t['status'] === 'revoked') {
                                $tokenError = 'token已被作废';
                                break;
                            } elseif ($t['status'] === 'used') {
                                $tokenError = 'token已被使用';
                                break;
                            } elseif ($t['status'] === 'unused') {
                                $tokenValid = true;
                                break;
                            }
                        }
                    }

                    // 如果token无效，立即返回错误
                    if ($tokenError) {
                        Log::warning("Token验证失败: {$tokenError}");

                        return json(['code' => 1, 'msg' => $tokenError]);
                    }

                    if (!$tokenValid) {
                        Log::warning('无效的token');

                        return json(['code' => 1, 'msg' => '无效的token']);
                    }

                    Log::info('Token状态验证通过，将交给Worker处理远程调用');
                }
            } catch (Throwable $e) {
                Log::warning('Token验证失败: ' . $e->getMessage());

                return json(['code' => 1, 'msg' => 'Token验证失败']);
            }
        }

        // 回填默认值
        if (empty($name)) {
            $name = (string) blog_config('title', 'WindBlog', true);
        }
        if (empty($url)) {
            $url = (string) blog_config('site_url', '', true);
        }
        if (empty($icon)) {
            $icon = (string) blog_config('favicon', '', true);
        }
        if (empty($description)) {
            $description = (string) blog_config('description', '', true);
        }
        if (empty($email)) {
            $email = (string) blog_config('admin_email', '', true);
        }

        // 参数完整性验证
        if (empty($peerApi)) {
            return json(['code' => 1, 'msg' => '请填写对方API地址']);
        }
        if (empty($name)) {
            return json(['code' => 1, 'msg' => '请填写站点名称']);
        }
        if (empty($url)) {
            return json(['code' => 1, 'msg' => '请填写站点URL']);
        }

        // URL格式验证
        if (!filter_var($peerApi, FILTER_VALIDATE_URL)) {
            return json(['code' => 1, 'msg' => '对方API地址格式不正确']);
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return json(['code' => 1, 'msg' => '站点URL格式不正确']);
        }

        // === 步骤2: 入队异步处理（包含token信息） ===
        // 注意：不在这里检查URL是否已存在，交给Worker在异步处理时检查
        $queueResult = LinkConnectQueueService::enqueue([
            'peer_api' => $peerApi,
            'name' => $name,
            'url' => $url,
            'icon' => $icon,
            'description' => $description,
            'email' => $email,
            'token' => $extractedToken, // 传递token给Worker处理
        ]);

        if ($queueResult['code'] !== 0) {
            Log::error('入队失败: ' . $queueResult['msg']);

            return json(['code' => 1, 'msg' => '提交任务失败: ' . $queueResult['msg']]);
        }

        // === 步骤4: 立即返回task_id供前端轮询 ===
        return json([
            'code' => 0,
            'msg' => '任务已提交，正在异步处理',
            'task_id' => $queueResult['task_id'],
        ]);
    }

    /**
     * 检查任务状态（供前端轮询使用）
     *
     * GET /link/connect/check-status?task_id=xxx
     *
     * 返回格式：
     * {
     *   "code": 0,
     *   "status": "pending|processing|success|failed",
     *   "message": "状态消息",
     *   "data": {...}  // 可选的附加数据
     * }
     */
    public function checkTaskStatus(Request $request): Response
    {
        $taskId = trim((string) $request->get('task_id', ''));

        if (empty($taskId)) {
            return json(['code' => 1, 'msg' => 'task_id不能为空']);
        }

        $status = LinkConnectQueueService::getTaskStatus($taskId);

        if ($status === null) {
            return json(['code' => 1, 'msg' => '任务不存在或已过期']);
        }

        return json([
            'code' => 0,
            'status' => $status['status'] ?? 'unknown',
            'message' => $status['message'] ?? '',
            'data' => $status['data'] ?? [],
        ]);
    }
}
