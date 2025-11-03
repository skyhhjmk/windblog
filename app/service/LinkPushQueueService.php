<?php

namespace app\service;

use PhpAmqpLib\Message\AMQPMessage;
use support\Log;
use Throwable;

/**
 * 友链推送队列服务
 * 负责CAT5扩展信息推送任务的入队和管理
 */
class LinkPushQueueService
{
    /**
     * 入队推送任务
     *
     * @param int    $linkId  Link记录ID
     * @param string $peerApi 对方接收API地址
     * @param array  $payload 推送载荷
     *
     * @return array ['code' => 0, 'task_id' => 'xxx'] 或错误信息
     */
    public static function enqueue(int $linkId, string $peerApi, array $payload): array
    {
        try {
            // 生成任务ID
            $taskId = self::generateTaskId();

            $taskData = [
                'task_id' => $taskId,
                'link_id' => $linkId,
                'peer_api' => $peerApi,
                'payload' => $payload,
                'enqueue_time' => time(),
            ];

            // 发送到MQ队列
            $channel = MQService::getChannel();
            $exchange = (string) blog_config('rabbitmq_link_push_exchange', 'link_push_exchange', true);
            $routingKey = (string) blog_config('rabbitmq_link_push_routing_key', 'link_push', true);
            $queueName = (string) blog_config('rabbitmq_link_push_queue', 'link_push_queue', true);

            // 确保队列存在
            $dlxExchange = (string) blog_config('rabbitmq_link_push_dlx_exchange', 'link_push_dlx_exchange', true);
            $dlxQueue = (string) blog_config('rabbitmq_link_push_dlx_queue', 'link_push_dlx_queue', true);

            MQService::declareDlx($channel, $dlxExchange, $dlxQueue);
            MQService::setupQueueWithDlx($channel, $exchange, $routingKey, $queueName, $dlxExchange, $dlxQueue);

            $message = new AMQPMessage(
                json_encode($taskData, JSON_UNESCAPED_UNICODE),
                [
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    'content_type' => 'application/json',
                ]
            );

            $channel->basic_publish($message, $exchange, $routingKey);

            Log::info("友链推送任务已入队 - Task ID: {$taskId}, Link ID: {$linkId}, Peer: {$peerApi}");

            return [
                'code' => 0,
                'msg' => '推送任务已提交',
                'task_id' => $taskId,
            ];

        } catch (Throwable $e) {
            Log::error('友链推送任务入队失败: ' . $e->getMessage());

            return [
                'code' => 1,
                'msg' => '入队失败: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * 生成任务ID
     */
    private static function generateTaskId(): string
    {
        return 'lp_' . uniqid() . '_' . bin2hex(random_bytes(4));
    }
}
