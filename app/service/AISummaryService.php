<?php

declare(strict_types=1);

namespace app\service;

use app\service\ai\AiProviderInterface;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use support\Log;
use support\Redis;
use Throwable;

/**
 * AI 摘要服务：负责入队摘要生成任务和管理AI提供者
 */
class AISummaryService
{
    /** @var AMQPStreamConnection|null */
    private static ?AMQPStreamConnection $connection = null;

    /** @var AMQPChannel|null */
    private static ?AMQPChannel $channel = null;

    private static bool $initialized = false;

    /**
     * 获取可用的AI提供者类映射（类型到实现类）
     */
    public static function getProviderClassMap(): array
    {
        return AiProviderService::getProviderClassMap();
    }

    /**
     * 获取所有启用的提供方（从数据库）
     */
    public static function getAllProviders(bool $enabledOnly = false): array
    {
        return AiProviderService::getAllProviders($enabledOnly);
    }

    /**
     * 从数据库加载提供方并创建实例
     */
    public static function createProviderFromDb(string $providerId): ?AiProviderInterface
    {
        return AiProviderService::createProviderFromDb($providerId);
    }

    /**
     * 创建AI提供者实例（根据类型和配置）
     */
    public static function createProviderInstance(string $type, array $config = []): ?AiProviderInterface
    {
        return AiProviderService::createProviderInstance($type, $config);
    }

    /**
     * 获取当前配置的AI提供者（从轮询组或单个提供方）
     * 支持故障转移
     */
    public static function getCurrentProvider(array $excludeProviders = []): ?AiProviderInterface
    {
        return AiProviderService::getCurrentProvider($excludeProviders);
    }

    /**
     * 入队AI摘要生成任务
     */
    public static function enqueue(array $payload): bool
    {
        try {
            self::initializeQueues();
            $ch = self::getChannel();

            $exchange = (string) blog_config('rabbitmq_ai_exchange', 'ai_summary_exchange', true);
            $routingKey = (string) blog_config('rabbitmq_ai_routing_key', 'ai_summary_generate', true);

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

            $msg = new AMQPMessage(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'priority' => $priority,
            ]);
            $ch->basic_publish($msg, $exchange, $routingKey);

            Log::debug('AI summary enqueued: ' . json_encode([
                    'post_id' => $payload['post_id'] ?? null,
                    'provider' => $payload['provider'] ?? null,
                    'priority' => $priority,
                ], JSON_UNESCAPED_UNICODE));

            return true;
        } catch (Throwable $e) {
            Log::error('Enqueue AI summary failed: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * 入队通用AI任务（chat, generate, translate等）
     *
     * @param string $taskId   任务ID（唯一标识）
     * @param string $task     任务类型（chat, generate, translate等）
     * @param array  $params   任务参数
     * @param array  $options  额外选项
     * @param string $provider 指定AI提供者ID（可选）
     *
     * @return bool
     */
    public static function enqueueTask(string $taskId, string $task, array $params, array $options = [], string $provider = ''): bool
    {
        try {
            self::initializeQueues();
            $ch = self::getChannel();

            $exchange = (string) blog_config('rabbitmq_ai_exchange', 'ai_summary_exchange', true);
            $routingKey = (string) blog_config('rabbitmq_ai_routing_key', 'ai_summary_generate', true);

            // 先设置任务为 pending 状态
            self::setTaskStatus($taskId, 'pending', null, null);

            $payload = [
                'task_type' => 'generic',  // 通用任务标记
                'task_id' => $taskId,
                'task' => $task,
                'params' => $params,
                'options' => $options,
                'provider' => $provider,
            ];

            $priority = (int) ($options['priority'] ?? 5);
            $priority = max(0, min(9, $priority));

            $msg = new AMQPMessage(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'priority' => $priority,
            ]);
            $ch->basic_publish($msg, $exchange, $routingKey);

            Log::debug('AI generic task enqueued: ' . json_encode([
                    'task_id' => $taskId,
                    'task' => $task,
                    'provider' => $provider,
                    'priority' => $priority,
                ], JSON_UNESCAPED_UNICODE));

            return true;
        } catch (Throwable $e) {
            Log::error('Enqueue AI task failed: ' . $e->getMessage());
            self::setTaskStatus($taskId, 'failed', null, $e->getMessage());

            return false;
        }
    }

    /**
     * 查询任务状态
     *
     * @param string $taskId 任务ID
     *
     * @return array|null
     */
    public static function getTaskStatus(string $taskId): ?array
    {
        try {
            $cacheKey = "ai_task_status:{$taskId}";

            // 直接使用Redis读取
            $redis = Redis::connection('default');
            /** @var string|false $jsonData */
            $jsonData = $redis->get($cacheKey);

            if ($jsonData) {
                $data = json_decode($jsonData, true);
                if (is_array($data)) {
                    return $data;
                }
            }

            return null;
        } catch (Throwable $e) {
            Log::error('Failed to get task status: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * 设置任务状态（为 Worker 和 Service 共用）
     *
     * @param string      $taskId 任务ID
     * @param string      $status 任务状态
     * @param array|null  $result 任务结果
     * @param string|null $error  错误信息
     */
    public static function setTaskStatus(string $taskId, string $status, ?array $result = null, ?string $error = null): void
    {
        try {
            $cacheKey = "ai_task_status:{$taskId}";
            $data = [
                'task_id' => $taskId,
                'status' => $status,
                'updated_at' => time(),
            ];

            if ($result !== null) {
                $data['result'] = $result;
            }

            if ($error !== null) {
                $data['error'] = $error;
            }

            // 直接使用Redis存储，过期时间1小时
            $redis = Redis::connection('default');
            $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            /** @phpstan-ignore-next-line */
            $redis->setex($cacheKey, 3600, $jsonData);

            Log::debug("AI task status updated: {$taskId} -> {$status}");
        } catch (Throwable $e) {
            Log::error("Failed to set task status: {$e->getMessage()}");
        }
    }

    private static function getConnection(): AMQPStreamConnection
    {
        if (!self::$connection) {
            self::$connection = new AMQPStreamConnection(
                (string) blog_config('rabbitmq_host', '127.0.0.1', true),
                (int) blog_config('rabbitmq_port', 5672, true),
                (string) blog_config('rabbitmq_user', 'guest', true),
                (string) blog_config('rabbitmq_password', 'guest', true),
                (string) blog_config('rabbitmq_vhost', '/', true),
            );
        }

        return self::$connection;
    }

    private static function getChannel(): AMQPChannel
    {
        if (!self::$channel) {
            self::$channel = self::getConnection()->channel();
            self::$channel->basic_qos(0, 1, false);
        }

        return self::$channel;
    }

    private static function initializeQueues(): void
    {
        if (self::$initialized) {
            return;
        }
        try {
            $ch = self::getChannel();

            $exchange = (string) blog_config('rabbitmq_ai_exchange', 'ai_summary_exchange', true);
            $routingKey = (string) blog_config('rabbitmq_ai_routing_key', 'ai_summary_generate', true);
            $queueName = (string) blog_config('rabbitmq_ai_queue', 'ai_summary_queue', true);

            $dlxExchange = (string) blog_config('rabbitmq_ai_dlx_exchange', 'ai_summary_dlx_exchange', true);
            $dlq = (string) blog_config('rabbitmq_ai_dlx_queue', 'ai_summary_dlx_queue', true);

            // DLX & DLQ
            $ch->exchange_declare($dlxExchange, 'direct', false, true, false);
            $ch->queue_declare($dlq, false, true, false, false);
            $ch->queue_bind($dlq, $dlxExchange, $dlq);

            // Main exchange & queue
            $ch->exchange_declare($exchange, 'direct', false, true, false);
            $ch->queue_declare($queueName, false, true, false, false, false, new AMQPTable([
                'x-dead-letter-exchange' => $dlxExchange,
                'x-dead-letter-routing-key' => $dlq,
                'x-max-priority' => 10,
            ]));
            $ch->queue_bind($queueName, $exchange, $routingKey);

            self::$initialized = true;
        } catch (Throwable $e) {
            Log::error('Initialize AI queues failed: ' . $e->getMessage());
        }
    }
}
