<?php

namespace plugin\admin\app\controller;

use app\model\Comment as CommentModel;
use app\service\CommentModerationService;
use support\Request;
use support\Response;

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

            $results = [];
            foreach ($comments as $comment) {
                $moderationResult = CommentModerationService::moderateComment(
                    $comment->content,
                    $comment->guest_name,
                    $comment->guest_email,
                    $comment->ip_address,
                    $comment->user_agent
                );

                // 更新审核结果
                $comment->ai_moderation_result = $moderationResult['result'];
                $comment->ai_moderation_reason = $moderationResult['reason'];
                $comment->ai_moderation_confidence = $moderationResult['confidence'];
                $comment->ai_moderation_categories = !empty($moderationResult['categories'])
                    ? json_encode($moderationResult['categories'], JSON_UNESCAPED_UNICODE)
                    : null;

                // 根据AI审核结果更新评论状态
                if (!$moderationResult['passed']) {
                    $comment->status = $moderationResult['result'];
                }

                $comment->save();

                $results[] = [
                    'id' => $comment->id,
                    'result' => $moderationResult['result'],
                    'passed' => $moderationResult['passed'],
                ];
            }

            return json([
                'code' => 0,
                'msg' => '重新审核完成',
                'data' => ['results' => $results],
            ]);
        } catch (\Throwable $e) {
            return json([
                'code' => 500,
                'msg' => '重新审核失败：' . $e->getMessage(),
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
                'prompt' => blog_config('comment_ai_moderation_prompt', '', true),
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

            // 更新配置
            blog_config('comment_ai_moderation_enabled', $enabled ? '1' : '0', false, true, true);
            blog_config('comment_ai_moderation_failure_strategy', $failureStrategy, false, true, true);
            blog_config('comment_ai_moderation_prompt', $prompt, false, true, true);
            blog_config('comment_ai_moderation_temperature', $temperature, false, true, true);

            // 如选择了轮询组，则更新全局AI选择为该组
            if ($groupId > 0) {
                // 可选：校验轮询组是否存在
                try {
                    $exists = \app\model\AiPollingGroup::where('id', $groupId)->exists();
                    if ($exists) {
                        blog_config('ai_current_selection', 'group:' . $groupId, false, true, true);
                    }
                } catch (\Throwable $e) {
                    // 如果查询失败，忽略，只保存其他配置
                }
            }

            return json([
                'code' => 0,
                'msg' => '配置更新成功',
            ]);
        } catch (\Throwable $e) {
            return json([
                'code' => 500,
                'msg' => '配置更新失败：' . $e->getMessage(),
            ]);
        }
    }
}
