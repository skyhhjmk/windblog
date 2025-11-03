<?php

namespace app\service;

use PhpAmqpLib\Message\AMQPMessage;
use support\Log;
use support\Redis;
use Throwable;

/**
 * 友链互联队列服务
 * 负责友链申请任务的入队和状态管理
 */
class LinkConnectQueueService
{
    /**
     * 入队友链申请任务
     *
     * @param array $payload 任务数据
     *
     * @return array ['code' => 0, 'task_id' => 'xxx'] 或错误信息
     */
    public static function enqueue(array $payload): array
    {
        try {
            // 生成任务ID
            $taskId = self::generateTaskId();

            // 添加任务ID到payload
            $payload['task_id'] = $taskId;
            $payload['enqueue_time'] = time();

            // 初始化Redis状态
            self::initTaskStatus($taskId, [
                'status' => 'pending',
                'message' => '任务已提交，等待处理',
                'peer_api' => $payload['peer_api'] ?? '',
                'created_at' => time(),
            ]);

            // 发送到MQ队列
            $channel = MQService::getChannel();
            $exchange = (string) blog_config('rabbitmq_link_connect_exchange', 'link_connect_exchange', true);
            $routingKey = (string) blog_config('rabbitmq_link_connect_routing_key', 'link_connect', true);

            // 确保队列存在
            $queueName = (string) blog_config('rabbitmq_link_connect_queue', 'link_connect_queue', true);
            $dlxExchange = (string) blog_config('rabbitmq_link_connect_dlx_exchange', 'link_connect_dlx_exchange', true);
            $dlxQueue = (string) blog_config('rabbitmq_link_connect_dlx_queue', 'link_connect_dlx_queue', true);

            MQService::declareDlx($channel, $dlxExchange, $dlxQueue);
            MQService::setupQueueWithDlx($channel, $exchange, $routingKey, $queueName, $dlxExchange, $dlxQueue);

            // 设置优先级
            $priority = isset($payload['priority']) ? max(0, min(9, (int) $payload['priority'])) : 5;

            $message = new AMQPMessage(
                json_encode($payload, JSON_UNESCAPED_UNICODE),
                [
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    'priority' => $priority,
                    'content_type' => 'application/json',
                ]
            );

            $channel->basic_publish($message, $exchange, $routingKey);

            Log::info("友链申请任务已入队 - Task ID: {$taskId}, Peer: " . ($payload['peer_api'] ?? 'unknown'));

            return [
                'code' => 0,
                'msg' => '任务已提交',
                'task_id' => $taskId,
            ];

        } catch (Throwable $e) {
            Log::error('友链申请入队失败: ' . $e->getMessage());

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
        return 'lc_' . uniqid() . '_' . bin2hex(random_bytes(4));
    }

    /**
     * 初始化任务状态到Redis
     *
     * @param string $taskId 任务ID
     * @param array  $status 状态信息
     */
    public static function initTaskStatus(string $taskId, array $status): void
    {
        try {
            $redis = Redis::connection();
            $key = self::getTaskStatusKey($taskId);

            // 设置状态，TTL 1小时
            $redis->setex($key, 3600, json_encode($status, JSON_UNESCAPED_UNICODE));

        } catch (Throwable $e) {
            Log::error("初始化任务状态失败 [{$taskId}]: " . $e->getMessage());
        }
    }

    /**
     * 获取任务状态的Redis Key
     */
    private static function getTaskStatusKey(string $taskId): string
    {
        return "link_connect:task:{$taskId}";
    }

    /**
     * 更新任务状态
     *
     * @param string $taskId  任务ID
     * @param string $status  状态: pending, processing, success, failed
     * @param string $message 状态消息
     * @param array  $data    附加数据
     */
    public static function updateTaskStatus(string $taskId, string $status, string $message, array $data = []): void
    {
        try {
            $redis = Redis::connection();
            $key = self::getTaskStatusKey($taskId);

            $statusData = [
                'status' => $status,
                'message' => $message,
                'updated_at' => time(),
            ];

            if (!empty($data)) {
                $statusData['data'] = $data;
            }

            // 更新状态，保持TTL
            $ttl = $redis->ttl($key);
            if ($ttl > 0) {
                $redis->setex($key, $ttl, json_encode($statusData, JSON_UNESCAPED_UNICODE));
            } else {
                $redis->setex($key, 3600, json_encode($statusData, JSON_UNESCAPED_UNICODE));
            }

            Log::debug("任务状态已更新 [{$taskId}]: {$status} - {$message}");

        } catch (Throwable $e) {
            Log::error("更新任务状态失败 [{$taskId}]: " . $e->getMessage());
        }
    }

    /**
     * 获取任务状态
     *
     * @param string $taskId 任务ID
     *
     * @return array|null 状态信息或null
     */
    public static function getTaskStatus(string $taskId): ?array
    {
        try {
            $redis = Redis::connection();
            $key = self::getTaskStatusKey($taskId);

            $status = $redis->get($key);

            if ($status === false || $status === null) {
                return null;
            }

            return json_decode($status, true);

        } catch (Throwable $e) {
            Log::error("获取任务状态失败 [{$taskId}]: " . $e->getMessage());

            return null;
        }
    }

    /**
     * 删除任务状态
     *
     * @param string $taskId 任务ID
     */
    public static function deleteTaskStatus(string $taskId): void
    {
        try {
            $redis = Redis::connection();
            $key = self::getTaskStatusKey($taskId);
            $redis->del($key);
        } catch (Throwable $e) {
            Log::error("删除任务状态失败 [{$taskId}]: " . $e->getMessage());
        }
    }
}
