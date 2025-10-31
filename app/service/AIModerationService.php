<?php

declare(strict_types=1);

namespace app\service;

use PhpAmqpLib\Message\AMQPMessage;
use support\Log;
use Throwable;

/**
 * AI 评论审核队列服务：负责入队评论审核任务
 */
class AIModerationService
{
    /**
     * 入队评论审核任务（非阻塞）
     *
     * payload 示例：
     * [
     *   'comment_id' => 123,
     *   'priority' => 5
     * ]
     */
    public static function enqueue(array $payload): bool
    {
        try {
            $ch = MQService::getChannel();

            $exchange = (string) blog_config('rabbitmq_ai_moderation_exchange', 'ai_moderation_exchange', true);
            $routingKey = (string) blog_config('rabbitmq_ai_moderation_routing_key', 'ai_moderation_moderate', true);
            $queueName = (string) blog_config('rabbitmq_ai_moderation_queue', 'ai_moderation_queue', true);
            $dlxExchange = (string) blog_config('rabbitmq_ai_moderation_dlx_exchange', 'ai_moderation_dlx_exchange', true);
            $dlq = (string) blog_config('rabbitmq_ai_moderation_dlx_queue', 'ai_moderation_dlx_queue', true);

            // 确保队列存在
            MQService::declareDlx($ch, $dlxExchange, $dlq);
            MQService::setupQueueWithDlx($ch, $exchange, $routingKey, $queueName, $dlxExchange, $dlq);

            $priority = 0;
            if (isset($payload['priority'])) {
                $p = $payload['priority'];
                if (is_string($p)) {
                    $map = ['high' => 9, 'normal' => 5, 'low' => 1];
                    $priority = $map[strtolower($p)] ?? 0;
                } elseif (is_numeric($p)) {
                    $priority = max(0, min(9, (int) $p));
                }
            }

            // 标记任务类型
            $payload['task_type'] = 'moderate_comment';

            $msg = new AMQPMessage(
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                [
                    'content_type' => 'application/json',
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    'priority' => $priority,
                ]
            );
            $ch->basic_publish($msg, $exchange, $routingKey);

            Log::debug('AI moderation enqueued: ' . json_encode([
                    'comment_id' => $payload['comment_id'] ?? null,
                    'priority' => $priority,
                ], JSON_UNESCAPED_UNICODE));

            return true;
        } catch (Throwable $e) {
            Log::error('Enqueue AI moderation failed: ' . $e->getMessage());

            return false;
        }
    }
}
