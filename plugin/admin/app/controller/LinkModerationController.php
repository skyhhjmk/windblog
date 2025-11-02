<?php

namespace plugin\admin\app\controller;

use app\model\AiPollingGroup;
use app\model\Link;
use app\service\LinkAIModerationService;
use support\Request;
use support\Response;
use Throwable;

/**
 * 友链AI审核管理控制器
 */
class LinkModerationController extends Base
{
    /**
     * 审核统计页面
     *
     * @param Request $request
     *
     * @return Response
     */
    public function index(Request $request): Response
    {
        return view('link/moderation/index');
    }

    /**
     * 获取审核统计数据
     *
     * @param Request $request
     *
     * @return Response
     */
    public function stats(Request $request): Response
    {
        $days = (int) $request->get('days', 7);

        try {
            // 获取指定天数内的友链AI审核统计（基于 ai_audit_status 字段）
            $startDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));

            $base = Link::whereNotNull('custom_fields->ai_audit_status')
                ->where('updated_at', '>=', $startDate);

            $total = (clone $base)->count();
            $approved = (clone $base)->where('custom_fields->ai_audit_status', 'approved')->count();
            $rejected = (clone $base)->where('custom_fields->ai_audit_status', 'rejected')->count();
            $spam = (clone $base)->where('custom_fields->ai_audit_status', 'spam')->count();

            $rate = $total > 0 ? round(($approved / $total) * 100, 2) : 0;

            $stats = [
                'total' => $total,
                'approved' => $approved,
                'rejected' => $rejected,
                'spam' => $spam,
                'rate' => $rate,
            ];

            return json([
                'code' => 0,
                'msg' => 'success',
                'data' => $stats,
            ]);
        } catch (Throwable $e) {
            return json([
                'code' => 500,
                'msg' => '获取统计数据失败：' . $e->getMessage(),
            ]);
        }
    }

    /**
     * 获取审核日志列表
     *
     * @param Request $request
     *
     * @return Response
     */
    public function logs(Request $request): Response
    {
        $page = (int) $request->get('page', 1);
        $limit = (int) $request->get('limit', 20);
        $result = $request->get('result', ''); // approved/rejected/spam

        try {
            // 仅筛选有 AI 审核结果的友链
            $query = Link::whereNotNull('custom_fields->ai_audit_status');

            if ($result === 'approved') {
                $query->where('custom_fields->ai_audit_status', 'approved');
            } elseif ($result === 'rejected') {
                $query->where('custom_fields->ai_audit_status', 'rejected');
            } elseif ($result === 'spam') {
                $query->where('custom_fields->ai_audit_status', 'spam');
            }

            $total = $query->count();

            $logs = $query->orderBy('updated_at', 'desc')
                ->forPage($page, $limit)
                ->get();

            // 处理数据，提取 AI 审核信息
            $logs = $logs->map(function ($log) {
                $logArray = $log->toArray();

                $logArray['ai_moderation_result'] = (string) $log->getCustomField('ai_audit_status', '');
                $logArray['ai_moderation_score'] = (float) $log->getCustomField('ai_audit_score', 0);
                $logArray['ai_moderation_time'] = (string) $log->getCustomField('last_audit_time', '');
                $logArray['ai_moderation_confidence'] = (float) $log->getCustomField('ai_audit_confidence', 0);
                $logArray['ai_moderation_reason'] = (string) $log->getCustomField('ai_audit_reason', '');

                return $logArray;
            });

            return json([
                'code' => 0,
                'msg' => 'success',
                'count' => $total,
                'data' => $logs,
            ]);
        } catch (Throwable $e) {
            return json([
                'code' => 500,
                'msg' => '获取日志失败：' . $e->getMessage(),
            ]);
        }
    }

    /**
     * 批量重新审核
     *
     * @param Request $request
     *
     * @return Response
     */
    public function batchRemoderate(Request $request): Response
    {
        $ids = $request->post('ids', []);

        if (empty($ids)) {
            return json(['code' => 400, 'msg' => '请选择要重新审核的友链']);
        }

        try {
            $links = Link::whereIn('id', (array) $ids)->get();

            $enqueued = 0;
            foreach ($links as $link) {
                // 入队待审核，手动触发不修改状态
                if (LinkAIModerationService::enqueue(['link_id' => $link->id, 'priority' => 5, 'manual' => true])) {
                    $enqueued++;
                }
            }

            return json([
                'code' => 0,
                'msg' => '已入队，等待审核',
                'data' => ['count' => $enqueued],
            ]);
        } catch (Throwable $e) {
            return json([
                'code' => 500,
                'msg' => '入队失败：' . $e->getMessage(),
            ]);
        }
    }

    /**
     * 触发AI审核（单个）
     *
     * @param Request $request
     *
     * @return Response
     */
    public function triggerAudit(Request $request): Response
    {
        $id = (int) $request->post('id', 0);
        $manual = $request->post('manual', 'true'); // 默认为手动模式

        // 处理字符串形式的布尔值
        if (is_string($manual)) {
            $manual = ($manual === 'false' || $manual === '0') ? false : true;
        } else {
            $manual = (bool) $manual;
        }

        if ($id <= 0) {
            return json(['code' => 400, 'msg' => '无效的友链ID']);
        }

        try {
            $link = Link::find($id);
            if (!$link) {
                return json(['code' => 404, 'msg' => '友链不存在']);
            }

            // 入队待审核
            if (LinkAIModerationService::enqueue(['link_id' => $link->id, 'priority' => 9, 'manual' => $manual])) {
                return json([
                    'code' => 0,
                    'msg' => '已入队，等待审核',
                ]);
            } else {
                return json([
                    'code' => 500,
                    'msg' => '入队失败',
                ]);
            }
        } catch (Throwable $e) {
            return json([
                'code' => 500,
                'msg' => '入队失败：' . $e->getMessage(),
            ]);
        }
    }

    /**
     * 批量自动审核（通过后会修改友链状态）
     *
     * @param Request $request
     *
     * @return Response
     */
    public function batchAutoAudit(Request $request): Response
    {
        $ids = $request->post('ids', []);
        $manual = $request->post('manual', 'false'); // 默认为自动模式

        // 处理字符串形式的布尔值
        if (is_string($manual)) {
            $manual = ($manual === 'false' || $manual === '0') ? false : true;
        } else {
            $manual = (bool) $manual;
        }

        if (empty($ids)) {
            return json(['code' => 400, 'msg' => '请选择要审核的友链']);
        }

        try {
            $links = Link::whereIn('id', (array) $ids)->get();

            $enqueued = 0;
            foreach ($links as $link) {
                // 入队待审核
                if (LinkAIModerationService::enqueue(['link_id' => $link->id, 'priority' => 7, 'manual' => $manual])) {
                    $enqueued++;
                }
            }

            return json([
                'code' => 0,
                'msg' => '已入队，等待审核',
                'data' => ['count' => $enqueued],
            ]);
        } catch (Throwable $e) {
            return json([
                'code' => 500,
                'msg' => '入队失败：' . $e->getMessage(),
            ]);
        }
    }

    /**
     * 配置管理
     *
     * @param Request $request
     *
     * @return Response
     */
    public function config(Request $request): Response
    {
        if ($request->method() === 'GET') {
            // 获取当前配置
            $currentSelection = (string) blog_config('ai_current_selection', '', true);
            $groupId = 0;
            if (str_starts_with($currentSelection, 'group:')) {
                $groupId = (int) substr($currentSelection, 6);
            }

            $config = [
                'enabled' => (bool) blog_config('link_ai_moderation_enabled', false, true),
                'group_id' => $groupId,
                'failure_strategy' => blog_config('link_ai_moderation_failure_strategy', 'approve', true),
                'auto_approve_on_pass' => (bool) blog_config('link_ai_auto_approve_on_pass', false, true),
                'auto_approve_min_confidence' => (float) blog_config('link_ai_auto_approve_min_confidence', 0.85, true),
                'auto_approve_min_score' => (int) blog_config('link_ai_auto_approve_min_score', 60, true),
                'temperature' => (float) blog_config('link_ai_moderation_temperature', 0.1, true),
                'prompt' => (function () {
                    $p = blog_config('link_ai_moderation_prompt', '', true);
                    if (trim((string) $p) === '') {
                        return self::defaultPrompt();
                    }

                    return $p;
                })(),
            ];

            return json([
                'code' => 0,
                'msg' => 'success',
                'data' => $config,
            ]);
        }

        // 更新配置
        try {
            $enabled = (bool) $request->post('enabled', false);
            $groupId = (int) $request->post('group_id', 0);
            $failureStrategy = $request->post('failure_strategy', 'approve');
            $autoApprove = (bool) $request->post('auto_approve_on_pass', false);
            $minConf = (float) $request->post('auto_approve_min_confidence', 0.85);
            $minScore = (int) $request->post('auto_approve_min_score', 60);
            $temperature = (float) $request->post('temperature', 0.1);
            $prompt = (string) $request->post('prompt', '');

            // 验证参数
            if ($minConf < 0 || $minConf > 1) {
                return json(['code' => 400, 'msg' => '最低置信度必须在0-1之间']);
            }
            if ($minScore < 0 || $minScore > 100) {
                return json(['code' => 400, 'msg' => '最低评分必须在0-100之间']);
            }
            if (trim($prompt) === '') {
                return json(['code' => 400, 'msg' => '提示词不能为空']);
            }

            // 更新配置
            blog_config('link_ai_moderation_enabled', $enabled ? '1' : '0', false, true, true);
            blog_config('link_ai_moderation_failure_strategy', $failureStrategy, false, true, true);
            blog_config('link_ai_auto_approve_on_pass', $autoApprove ? '1' : '0', false, true, true);
            blog_config('link_ai_auto_approve_min_confidence', $minConf, false, true, true);
            blog_config('link_ai_auto_approve_min_score', $minScore, false, true, true);
            blog_config('link_ai_moderation_temperature', $temperature, false, true, true);
            blog_config('link_ai_moderation_prompt', $prompt, false, true, true);

            // 如选择了轮询组，则更新全局AI选择为该组
            if ($groupId > 0) {
                try {
                    $exists = AiPollingGroup::where('id', $groupId)->exists();
                    if ($exists) {
                        blog_config('ai_current_selection', 'group:' . $groupId, false, true, true);
                    }
                } catch (Throwable $e) {
                    // 如果查询失败，忽略，只保存其他配置
                }
            }

            return json([
                'code' => 0,
                'msg' => '配置更新成功',
            ]);
        } catch (Throwable $e) {
            return json([
                'code' => 500,
                'msg' => '配置更新失败：' . $e->getMessage(),
            ]);
        }
    }

    private static function defaultPrompt(): string
    {
        return <<<EOT
请审核以下友情链接申请，并仅以JSON返回结果：
{
  "passed": true/false,
  "result": "approved/rejected/spam",
  "confidence": 0.0-1.0,
  "score": 0-100,
  "reason": "审核理由",
  "details": {
    "backlink_found": true/false,
    "backlink_count": 0,
    "semantic_match": true/false,
    "quality_assessment": "质量评估"
  }
}

请严格按照如下说明给出 confidence：
- confidence 表示"你对本次审核结论(passed/score)的把握程度"。
- 取值范围 0 到 1，保留两位小数。
- 0 表示 100% 不确定（高度怀疑你的结论是错误的），1 表示 100% 确定（你的结论完全可信）。

审核要点：
1. 检查反向链接：对方网站是否包含指向本站的链接
2. 语义匹配：页面是否包含"友情链接"、"友链"、"Friends"、"Links"等关键词
3. 网站质量：内容质量、更新频率、用户体验
4. 相关性：网站主题是否与本站相关

评分标准：
- 找到反向链接：基础分
- 每增加一个反链：额外加分
- 页面包含友链语义：加分
- 网站质量优秀：可适当加分
- 总分最高100分

仅输出上述JSON，不要包含多余文本或注释。

友链信息：
网站名称：{name}
网站地址：{url}
网站描述：{description}
反链状态：{backlink_found}（数量：{backlink_count}）
页面内容摘要：{html_snippet}
EOT;
    }
}
