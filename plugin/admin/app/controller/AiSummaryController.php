<?php

declare(strict_types=1);

namespace plugin\admin\app\controller;

use app\model\PostExt;
use app\service\AISummaryService;
use support\Db;
use support\Request;
use support\Response;
use Throwable;

class AiSummaryController
{
    /**
     * 管理页面：分标签页（Overview、API配置、Prompt配置）
     * GET /app/admin/ai-summary
     */
    public function index(Request $request): Response
    {
        $path = base_path() . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'ai_summary' . DIRECTORY_SEPARATOR . 'index.html';
        if (is_file($path)) {
            return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], (string) file_get_contents($path));
        }

        return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8'], 'AI summary index template not found');
    }

    // 统计：各状态数量、使用量、提供者信息等
    public function stats(Request $request): Response
    {
        $rows = Db::table('post_ext')->where('key', 'ai_summary_meta')->get();
        $counters = ['none' => 0, 'queued' => 0, 'done' => 0, 'failed' => 0, 'refreshing' => 0, 'persisted' => 0];
        $totalTokens = 0;
        $totalCost = 0;
        $providerUsage = [];
        $recentErrors = [];
        $recentSuccess = [];

        foreach ($rows as $r) {
            $val = $r->value;
            if (is_string($val)) {
                $meta = json_decode($val, true) ?: [];
            } else {
                $meta = (array) $val;
            }

            $st = (string) ($meta['status'] ?? 'none');
            if (!isset($counters[$st])) {
                $counters[$st] = 0;
            }
            $counters[$st]++;

            // 统计使用量
            if (isset($meta['usage']['total_tokens'])) {
                $totalTokens += (int) $meta['usage']['total_tokens'];
            }

            // 统计提供者使用情况
            if (isset($meta['provider'])) {
                $provider = (string) $meta['provider'];
                if (!isset($providerUsage[$provider])) {
                    $providerUsage[$provider] = ['count' => 0, 'tokens' => 0];
                }
                $providerUsage[$provider]['count']++;
                if (isset($meta['usage']['total_tokens'])) {
                    $providerUsage[$provider]['tokens'] += (int) $meta['usage']['total_tokens'];
                }
            }

            // 收集最近的错误
            if ($st === 'failed' && isset($meta['error']) && count($recentErrors) < 10) {
                $recentErrors[] = [
                    'post_id' => $r->post_id,
                    'error' => (string) $meta['error'],
                    'provider' => (string) ($meta['provider'] ?? 'unknown'),
                    'failed_at' => (string) ($meta['failed_at'] ?? 'unknown'),
                ];
            }

            // 收集最近成功的任务
            if ($st === 'done' && count($recentSuccess) < 10) {
                $recentSuccess[] = [
                    'post_id' => $r->post_id,
                    'provider' => (string) ($meta['provider'] ?? 'unknown'),
                    'model' => (string) ($meta['model'] ?? 'unknown'),
                    'tokens' => (int) ($meta['usage']['total_tokens'] ?? 0),
                    'generated_at' => (string) ($meta['generated_at'] ?? 'unknown'),
                ];
            }
        }

        // 按时间排序
        usort($recentErrors, function ($a, $b) {
            return strtotime($b['failed_at']) <=> strtotime($a['failed_at']);
        });
        usort($recentSuccess, function ($a, $b) {
            return strtotime($b['generated_at']) <=> strtotime($a['generated_at']);
        });

        return json([
            'code' => 0,
            'data' => [
                'counters' => $counters,
                'total_tokens' => $totalTokens,
                'provider_usage' => $providerUsage,
                'recent_errors' => $recentErrors,
                'recent_success' => $recentSuccess,
            ],
        ]);
    }

    /**
     * 设置当前选择（提供方或轮询组）
     * POST /app/admin/ai/selection/set
     * body: { selection: 'provider:openai' | 'group:1' }
     */
    public function setSelection(Request $request): Response
    {
        try {
            $payload = $request->post();
            if (!is_array($payload)) {
                $payload = json_decode((string) $request->rawBody(), true);
            }

            $selection = (string) ($payload['selection'] ?? '');
            if (empty($selection)) {
                return json(['code' => 1, 'msg' => 'selection is required']);
            }

            // 验证选择格式
            if (str_starts_with($selection, 'provider:')) {
                $providerId = substr($selection, 9);
                $provider = \app\model\AiProvider::find($providerId);
                if (!$provider || !$provider->enabled) {
                    return json(['code' => 1, 'msg' => '提供方不存在或未启用']);
                }
            } elseif (str_starts_with($selection, 'group:')) {
                $groupId = (int) substr($selection, 6);
                $group = \app\model\AiPollingGroup::find($groupId);
                if (!$group) {
                    return json(['code' => 1, 'msg' => '轮询组不存在']);
                }
            } else {
                return json(['code' => 1, 'msg' => '无效的选择格式']);
            }

            blog_config('ai_current_selection', $selection, false, true, true);

            return json(['code' => 0, 'msg' => '设置成功']);
        } catch (Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 获取当前选择
     * GET /app/admin/ai/selection/get
     */
    public function getSelection(Request $request): Response
    {
        try {
            $currentSelection = (string) blog_config('ai_current_selection', '', true);

            return json(['code' => 0, 'data' => ['selection' => $currentSelection]]);
        } catch (Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    // 为文章设置启用/状态/持久化
    public function setMeta(Request $request): Response
    {
        $postId = (int) $request->post('post_id', 0);
        if ($postId <= 0) {
            return json(['code' => 1, 'msg' => 'post_id required']);
        }
        $enabled = $request->post('enabled');
        $status = $request->post('status');
        $meta = [];
        if ($enabled !== null) {
            $meta['enabled'] = (bool) $enabled;
        }
        if (is_string($status) && $status !== '') {
            $meta['status'] = $status;
        }
        $row = PostExt::where('post_id', $postId)->where('key', 'ai_summary_meta')->first();
        if (!$row) {
            $row = new PostExt(['post_id' => $postId, 'key' => 'ai_summary_meta', 'value' => []]);
        }
        $row->value = array_merge((array) $row->value, $meta);
        $row->save();

        return json(['code' => 0, 'msg' => 'ok']);
    }

    // 入队生成
    public function enqueue(Request $request): Response
    {
        $postId = (int) $request->post('post_id', 0);
        if ($postId <= 0) {
            return json(['code' => 1, 'msg' => 'post_id required']);
        }
        $provider = (string) $request->post('provider', '');
        $force = (bool) $request->post('force', false);

        // 立即设置状态为 queued
        $row = PostExt::where('post_id', $postId)->where('key', 'ai_summary_meta')->first();
        if (!$row) {
            $row = new PostExt(['post_id' => $postId, 'key' => 'ai_summary_meta', 'value' => []]);
        }
        $meta = (array) $row->value;
        $meta['status'] = 'queued';
        $meta['queued_at'] = date('Y-m-d H:i:s');
        $row->value = $meta;
        $row->save();

        $ok = AISummaryService::enqueue(['post_id' => $postId, 'provider' => ($provider ?: null), 'options' => ['force' => $force]]);

        return json(['code' => $ok ? 0 : 1, 'msg' => $ok ? '已入队' : '入队失败']);
    }

    /**
     * 获取Prompt配置
     * GET /app/admin/ai/summary/prompt
     */
    public function promptGet(Request $request): Response
    {
        try {
            $defaultPrompt = <<<'EOF'
                请为以下文章生成一个简洁的摘要，重点阐述文章的主要内容和核心观点。

                要求：
                1. 摘要长度为140-160字
                2. 着重描述文章讲了什么，概括主要内容和观点
                3. 使用简洁、流畅的语言，避免冗长
                4. 不使用表情符号、特殊符号，标点符号仅保留必要的逗号和句号
                5. 保持客观、中立的陈述角度

                直接输出摘要内容，不要添加“本文介绍了”、“摘要：”等前缀词。
                EOF;
            $prompt = (string) blog_config('ai_summary_prompt', $defaultPrompt, true);

            return json(['code' => 0, 'data' => ['prompt' => $prompt]]);
        } catch (Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 保存Prompt配置
     * POST /app/admin/ai/summary/prompt-save
     * body: { prompt: "..." }
     */
    public function promptSave(Request $request): Response
    {
        try {
            $payload = $request->post();
            if (!is_array($payload)) {
                $payload = json_decode((string) $request->rawBody(), true);
            }
            if (!is_array($payload)) {
                return json(['code' => 1, 'msg' => 'invalid payload']);
            }

            $prompt = (string) ($payload['prompt'] ?? '');

            if (empty($prompt)) {
                return json(['code' => 1, 'msg' => 'Prompt不能为空']);
            }

            blog_config('ai_summary_prompt', $prompt, false, true, true);

            return json(['code' => 0, 'msg' => '保存成功']);
        } catch (Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 获取文章AI摘要状态
     * GET /app/admin/ai/summary/status
     * query: post_id
     */
    public function getStatus(Request $request): Response
    {
        try {
            $postId = (int) $request->get('post_id', 0);
            if ($postId <= 0) {
                return json(['code' => 1, 'msg' => 'post_id required']);
            }

            $row = PostExt::where('post_id', $postId)->where('key', 'ai_summary_meta')->first();
            $meta = $row ? (array) $row->value : [];

            // 检查是否超时（刷新状态超过3分钟）
            $isStuck = false;
            if (($meta['status'] ?? '') === 'refreshing') {
                $startedAt = $meta['started_at'] ?? null;
                if ($startedAt) {
                    $startTime = strtotime($startedAt);
                    $elapsed = time() - $startTime;
                    // 如果超过3分钟，认为卡住
                    if ($elapsed > 180) {
                        $isStuck = true;
                        // 自动标记为失败
                        $meta['status'] = 'failed';
                        $meta['error'] = '任务超时（超过3分钟）';
                        $meta['failed_at'] = date('Y-m-d H:i:s');

                        // 更新数据库
                        if ($row) {
                            $row->value = $meta;
                            $row->save();
                        }
                    }
                }
            }

            // 同时获取文章的ai_summary字段
            $post = \app\model\Post::find($postId);
            $aiSummary = $post ? (string) $post->ai_summary : '';

            return json([
                'code' => 0,
                'data' => [
                    'meta' => $meta,
                    'summary' => $aiSummary,
                    'status' => (string) ($meta['status'] ?? 'none'),
                    'enabled' => (bool) ($meta['enabled'] ?? false),
                    'error' => (string) ($meta['error'] ?? ''),
                    'provider' => (string) ($meta['provider'] ?? ''),
                    'model' => (string) ($meta['model'] ?? ''),
                    'generated_at' => (string) ($meta['generated_at'] ?? ''),
                    'is_stuck' => $isStuck,
                    'started_at' => (string) ($meta['started_at'] ?? ''),
                ],
            ]);
        } catch (Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 重置卡住的任务
     * POST /app/admin/ai/summary/reset
     * body: { post_id: 123 }
     */
    public function resetStuckTask(Request $request): Response
    {
        try {
            $payload = $request->post();
            if (!is_array($payload)) {
                $payload = json_decode((string) $request->rawBody(), true);
            }

            $postId = (int) ($payload['post_id'] ?? 0);
            if ($postId <= 0) {
                return json(['code' => 1, 'msg' => 'post_id required']);
            }

            $row = PostExt::where('post_id', $postId)->where('key', 'ai_summary_meta')->first();
            if (!$row) {
                return json(['code' => 1, 'msg' => '未找到AI摘要元数据']);
            }

            $meta = (array) $row->value;

            // 重置为失败状态
            $meta['status'] = 'failed';
            $meta['error'] = '手动重置（任务卡住）';
            $meta['failed_at'] = date('Y-m-d H:i:s');

            $row->value = $meta;
            $row->save();

            return json(['code' => 0, 'msg' => '已重置任务状态']);
        } catch (Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 批量重置所有卡住的任务
     * POST /app/admin/ai/summary/reset-all-stuck
     */
    public function resetAllStuckTasks(Request $request): Response
    {
        try {
            $rows = Db::table('post_ext')->where('key', 'ai_summary_meta')->get();
            $resetCount = 0;
            $timeout = 180; // 3分钟超时

            foreach ($rows as $row) {
                $val = $row->value;
                if (is_string($val)) {
                    $meta = json_decode($val, true) ?: [];
                } else {
                    $meta = (array) $val;
                }

                // 检查是否处于刷新中且超时
                if (($meta['status'] ?? '') === 'refreshing') {
                    $startedAt = $meta['started_at'] ?? null;
                    if ($startedAt) {
                        $startTime = strtotime($startedAt);
                        $elapsed = time() - $startTime;

                        if ($elapsed > $timeout) {
                            // 重置为失败
                            $meta['status'] = 'failed';
                            $meta['error'] = '批量重置（任务超时' . round($elapsed / 60, 1) . '分钟）';
                            $meta['failed_at'] = date('Y-m-d H:i:s');

                            // 更新数据库
                            Db::table('post_ext')
                                ->where('post_id', $row->post_id)
                                ->where('key', 'ai_summary_meta')
                                ->update(['value' => json_encode($meta)]);

                            $resetCount++;
                        }
                    }
                }
            }

            return json([
                'code' => 0,
                'msg' => "已重置 {$resetCount} 个卡住的任务",
                'data' => ['reset_count' => $resetCount],
            ]);
        } catch (Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 获取文章列表（包含AI摘要信息）
     * GET /app/admin/ai/summary/articles
     */
    public function articles(Request $request): Response
    {
        try {
            $page = (int) $request->get('page', 1);
            $limit = (int) $request->get('limit', 15);
            $title = (string) $request->get('title', '');
            $aiStatus = (string) $request->get('ai_status', '');

            // 构建查询
            $query = \app\model\Post::query();

            // 文章标题筛选
            if ($title) {
                $query->where('title', 'like', "%{$title}%");
            }

            // 获取总数
            $total = $query->count();

            // 获取文章列表
            $posts = $query->orderBy('id', 'desc')
                ->forPage($page, $limit)
                ->get();

            $data = [];
            foreach ($posts as $post) {
                // 获取AI摘要元数据
                $metaRow = PostExt::where('post_id', $post->id)
                    ->where('key', 'ai_summary_meta')
                    ->first();

                $meta = $metaRow ? (array) $metaRow->value : [];
                $status = (string) ($meta['status'] ?? 'none');

                // AI状态筛选
                if ($aiStatus && $status !== $aiStatus) {
                    continue;
                }

                $item = [
                    'id' => $post->id,
                    'title' => $post->title,
                    'ai_summary' => (string) $post->ai_summary,
                    'ai_status' => $status,
                    'ai_provider' => (string) ($meta['provider'] ?? ''),
                    'ai_model' => (string) ($meta['model'] ?? ''),
                    'ai_tokens' => (int) ($meta['usage']['total_tokens'] ?? 0),
                    'ai_generated_at' => (string) ($meta['generated_at'] ?? ''),
                    'ai_error' => (string) ($meta['error'] ?? ''),
                ];

                $data[] = $item;
            }

            // 如果有AI状态筛选，重新计算总数
            if ($aiStatus) {
                $total = count($data);
            }

            return json([
                'code' => 0,
                'msg' => 'success',
                'count' => $total,
                'data' => $data,
            ]);
        } catch (Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }
}
