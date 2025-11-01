<?php

namespace plugin\admin\app\controller;

use app\model\AiPollingGroup;
use app\model\Comment as CommentModel;
use app\service\AIModerationService;
use app\service\CommentModerationService;
use support\Request;
use support\Response;
use Throwable;

/**
 * 评论AI审核管理控制器
 */
class CommentModerationController extends Base
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
        return view('comment/moderation/index');
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

        $stats = CommentModerationService::getModerationStats($days);

        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => $stats,
        ]);
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
        $result = $request->get('result', '');

        $query = CommentModel::whereNotNull('ai_moderation_result');

        if ($result !== '') {
            $query->where('ai_moderation_result', $result);
        }

        $total = $query->count();

        $logs = $query->with(['post', 'author'])
            ->orderBy('created_at', 'desc')
            ->forPage($page, $limit)
            ->get();

        // 处理审核类别（JSON转数组）
        $logs = $logs->map(function ($log) {
            $logArray = $log->toArray();
            if (!empty($log->ai_moderation_categories)) {
                $logArray['ai_moderation_categories'] = json_decode($log->ai_moderation_categories, true);
            }

            return $logArray;
        });

        return json([
            'code' => 0,
            'msg' => 'success',
            'count' => $total,
            'data' => $logs,
        ]);
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
            return json(['code' => 400, 'msg' => '请选择要重新审核的评论']);
        }

        try {
            $comments = CommentModel::whereIn('id', (array) $ids)->get();

            $enqueued = 0;
            foreach ($comments as $comment) {
                if (AIModerationService::enqueue(['comment_id' => $comment->id, 'priority' => 5])) {
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
                'enabled' => blog_config('comment_ai_moderation_enabled', false, true),
                'group_id' => $groupId,
                'failure_strategy' => blog_config('comment_ai_moderation_failure_strategy', 'approve', true),
                'prompt' => (function () {
                    $p = blog_config('comment_ai_moderation_prompt', '', true);
                    if (trim((string) $p) === '') {
                        return self::defaultPrompt();
                    }

                    return $p;
                })(),
                'auto_approve_on_pass' => (bool) blog_config('comment_ai_auto_approve_on_pass', false, true),
                'auto_approve_min_confidence' => (float) blog_config('comment_ai_auto_approve_min_confidence', 0.85, true),
                'temperature' => (float) blog_config('comment_ai_moderation_temperature', 0.1, true),
            ];

            return json([
                'code' => 0,
                'msg' => 'success',
                'data' => $config,
            ]);
        }

        // 更新配置
        try {
            $enabled = $request->post('enabled', false);
            $groupId = (int) $request->post('group_id', 0);
            $failureStrategy = $request->post('failure_strategy', 'approve');
            $prompt = (string) $request->post('prompt', '');
            $temperature = (float) $request->post('temperature', 0.1);
            $autoApprove = (bool) $request->post('auto_approve_on_pass', false);
            $minConf = (float) $request->post('auto_approve_min_confidence', 0.85);

            if (trim($prompt) === '') {
                return json(['code' => 400, 'msg' => '提示词不能为空']);
            }
            if ($minConf < 0) {
                $minConf = 0.0;
            }
            if ($minConf > 1) {
                $minConf = 1.0;
            }

            // 更新配置
            blog_config('comment_ai_moderation_enabled', $enabled ? '1' : '0', false, true, true);
            blog_config('comment_ai_moderation_failure_strategy', $failureStrategy, false, true, true);
            blog_config('comment_ai_moderation_prompt', $prompt, false, true, true);
            blog_config('comment_ai_moderation_temperature', $temperature, false, true, true);
            blog_config('comment_ai_auto_approve_on_pass', $autoApprove ? '1' : '0', false, true, true);
            blog_config('comment_ai_auto_approve_min_confidence', $minConf, false, true, true);

            // 如选择了轮询组，则更新全局AI选择为该组
            if ($groupId > 0) {
                // 可选：校验轮询组是否存在
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
            请审核以下评论内容，并仅以JSON返回结果：
            {
              "passed": true/false,
              "result": "approved/rejected/spam",
              "reason": "审核理由",
              "confidence": 0.0-1.0,
              "categories": ["问题类别，如 spam, offensive 等"]
            }

            请严格按照如下说明给出 confidence：
            - confidence 表示“你对本次审核结论(result/passed)的把握程度”。
            - 取值范围 0 到 1，保留两位小数。
            - 0 表示 100% 不确定（高度怀疑你的结论是错误的），1 表示 100% 确定（你的结论完全可信）。

            在判断是否违规时，请特别留意并尽量进行归一化识别以下绕过方式：
            - 同音/谐音/形似替换（含数字、符号、标点、emoji、部首拆分、火星文、形声/象形变体）
            - 多语/方言/中英混写（含拼音、粤语/闽南/吴语等方言写法、音译/谐译，及夹杂阿拉伯文、日文、韩文等）
            - Leet/大小写变换/插入空格与标点/拉长重复/零宽字符
            - 隐喻、暗号、缩写、避敏同义替换

            额外要求（语音音素检测）：
            1) 先将“评论内容”转换为多通道的发音序列：
               - 普通话：拼音（保留与不保留声调两版）
               - 粤语：粤拼/Jyutping
               - 英语：音标或CMU-like音素
               - 日语：罗马音/假名；韩语：RR/IPA
               - 其他语种可近似用IPA或合理的转写
            2) 基于音素序列做近似匹配，检查是否与常见违规词（辱骂/歧视/色情/暴恐/违法等）的读音相近（允许少量替换/插入/删除、跨语种同音）。
            3) 若识别为谐音/近音规避，请在 reason 中明确：
               - 标注“phonetic-hint”并给出被映射的目标词、使用的音素/转写及匹配依据（如编辑距离/相似度）。

            如命中，请在 reason 中简要说明识别依据与类别（如："offensive(谐音, phonetic-hint): ..."）。

            仅输出上述JSON，不要包含多余文本或注释。

            评论内容：{content}
            昵称：{author_name}
            邮箱：{author_email}
            IP：{ip_address}
            User-Agent：{user_agent}
            EOT;
    }
}
