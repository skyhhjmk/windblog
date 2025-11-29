<?php

declare(strict_types=1);

namespace app\service;

use app\model\Comment;
use support\Log;
use Throwable;

/**
 * 评论AI审核服务
 * 用于检测垃圾评论、敏感词、恶意内容等
 */
class CommentModerationService
{
    /**
     * 批量审核评论（用于后台批量处理）
     *
     * @param array $comments 评论列表
     *
     * @return array 审核结果列表
     */
    public static function moderateComments(array $comments): array
    {
        $results = [];

        foreach ($comments as $comment) {
            $commentId = $comment['id'] ?? null;
            $content = $comment['content'] ?? '';
            $guestName = $comment['guest_name'] ?? '';
            $guestEmail = $comment['guest_email'] ?? '';
            $ipAddress = $comment['ip_address'] ?? '';
            $userAgent = $comment['user_agent'] ?? '';

            $result = self::moderateComment(
                $content,
                $guestName,
                $guestEmail,
                $ipAddress,
                $userAgent
            );

            $results[$commentId] = $result;
        }

        return $results;
    }

    /**
     * 审核评论内容
     *
     * @param string $content    评论内容
     * @param string $guestName  评论者名称
     * @param string $guestEmail 评论者邮箱
     * @param string $ipAddress  IP地址
     * @param string $userAgent  用户代理
     *
     * @return array{
     *     passed: bool,
     *     result: string,
     *     reason: string,
     *     confidence: float,
     *     categories: array
     * }
     */
    public static function moderateComment(
        string $content,
        string $guestName = '',
        string $guestEmail = '',
        string $ipAddress = '',
        string $userAgent = ''
    ): array {
        // 检查是否启用AI审核
        $aiModerationEnabled = blog_config('comment_ai_moderation_enabled', false, true);

        if (!$aiModerationEnabled) {
            Log::debug('AI comment moderation is disabled');

            return [
                'passed' => true,
                'result' => 'approved',
                'reason' => 'AI审核未启用',
                'confidence' => 1.0,
                'categories' => [],
            ];
        }

        try {
            // 获取AI提供者
            $provider = AISummaryService::getCurrentProvider();

            if (!$provider) {
                Log::warning('No AI provider available for comment moderation');

                return self::getFallbackResult(true, 'AI服务不可用，使用默认审核');
            }

            // 检查提供者是否支持审核任务
            if (!in_array('moderate_comment', $provider->getSupportedTasks(), true)) {
                Log::warning("AI provider does not support 'moderate_comment' task");

                return self::getFallbackResult(true, 'AI提供者不支持评论审核');
            }

            // 构建审核参数
            $params = [
                'content' => $content,
                'author_name' => $guestName,
                'author_email' => $guestEmail,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
            ];

            // 获取审核选项（支持后台配置）
            $options = [
                'temperature' => (float) blog_config('comment_ai_moderation_temperature', 0.1, true),
                'model' => blog_config('comment_ai_moderation_model', '', true) ?: $provider->getDefaultModel(),
            ];

            // 在服务层拼装提示词与消息，提供者保持可复用
            $template = (string) blog_config('comment_ai_moderation_prompt', '', true);
            if (trim($template) === '') {
                // 默认模板仅在运行时使用，不写入提供者
                $template = <<<EOT
请审核以下评论内容，并仅以JSON返回结果：
{
  "passed": true/false,
  "result": "approved/rejected/spam",
  "reason": "审核理由",
  "confidence": 0.0-1.0,
  "categories": ["问题类别，如 spam, offensive 等"]
}

评论内容：{content}
昵称：{author_name}
邮箱：{author_email}
IP：{ip_address}
User-Agent：{user_agent}
EOT;
            }

            $promptText = strtr($template, [
                '{content}' => $content,
                '{author_name}' => $guestName,
                '{author_email}' => $guestEmail,
                '{ip_address}' => $ipAddress,
                '{user_agent}' => $userAgent,
            ]);

            // 传入标准聊天消息给提供者
            $params['messages'] = [
                ['role' => 'user', 'content' => $promptText],
            ];

            // 调用AI进行审核
            $result = $provider->call('moderate_comment', $params, $options);

            if (!$result['ok']) {
                Log::error('AI moderation failed: ' . ($result['error'] ?? 'Unknown error'));

                return self::getFallbackResult(true, 'AI审核失败: ' . ($result['error'] ?? 'Unknown error'));
            }

            // 解析AI返回结果
            $moderationResult = $result['result'] ?? [];

            return [
                'passed' => $moderationResult['passed'] ?? true,
                'result' => $moderationResult['result'] ?? 'approved',
                'reason' => $moderationResult['reason'] ?? '',
                'confidence' => $moderationResult['confidence'] ?? 1.0,
                'categories' => $moderationResult['categories'] ?? [],
            ];

        } catch (Throwable $e) {
            Log::error('Comment moderation exception: ' . $e->getMessage());

            // 获取失败策略
            $failureStrategy = blog_config('comment_ai_moderation_failure_strategy', 'approve', true);
            $passed = ($failureStrategy === 'approve');

            return self::getFallbackResult($passed, 'AI审核异常: ' . $e->getMessage());
        }
    }

    /**
     * 获取回退结果
     */
    private static function getFallbackResult(bool $passed, string $reason): array
    {
        return [
            'passed' => $passed,
            'result' => $passed ? 'approved' : 'pending',
            'reason' => $reason,
            'confidence' => 0.0,
            'categories' => [],
        ];
    }

    /**
     * 获取审核统计信息
     *
     * @param int $days 统计天数
     *
     * @return array{
     *     total: int,
     *     approved: int,
     *     rejected: int,
     *     spam: int,
     *     rate: float
     * }
     */
    public static function getModerationStats(int $days = 7): array
    {
        try {
            $startDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));

            $total = Comment::withTrashed()
                ->where('created_at', '>=', $startDate)
                ->whereNotNull('ai_moderation_result')
                ->count();

            $approved = Comment::withTrashed()
                ->where('created_at', '>=', $startDate)
                ->where('ai_moderation_result', 'approved')
                ->count();

            $rejected = Comment::withTrashed()
                ->where('created_at', '>=', $startDate)
                ->where('ai_moderation_result', 'rejected')
                ->count();

            $spam = Comment::withTrashed()
                ->where('created_at', '>=', $startDate)
                ->where('ai_moderation_result', 'spam')
                ->count();

            $rate = $total > 0 ? round(($approved / $total) * 100, 2) : 0;

            return [
                'total' => $total,
                'approved' => $approved,
                'rejected' => $rejected,
                'spam' => $spam,
                'rate' => $rate,
            ];
        } catch (Throwable $e) {
            Log::error('Failed to get moderation stats: ' . $e->getMessage());

            return [
                'total' => 0,
                'approved' => 0,
                'rejected' => 0,
                'spam' => 0,
                'rate' => 0.0,
            ];
        }
    }
}
