<?php

declare(strict_types=1);

namespace app\service;

use app\model\AiPollingGroup;
use app\model\AiProvider;
use app\service\ai\AiProviderInterface;
use app\service\ai\providers\LocalEchoProvider;
use app\service\ai\providers\OpenAiProvider;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use support\Log;
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
        return [
            'local' => LocalEchoProvider::class,
            'openai' => OpenAiProvider::class,
            'azure_openai' => OpenAiProvider::class, // Azure 也使用 OpenAI 实现
            'claude' => OpenAiProvider::class, // 暂时使用 OpenAI 兼容实现
            'gemini' => OpenAiProvider::class, // 暂时使用 OpenAI 兼容实现
            'custom' => OpenAiProvider::class, // 自定义使用 OpenAI 兼容实现
        ];
    }

    /**
     * 获取所有启用的提供方（从数据库）
     */
    public static function getAllProviders(bool $enabledOnly = false): array
    {
        $query = AiProvider::query();

        if ($enabledOnly) {
            $query->where('enabled', true);
        }

        return $query->orderBy('weight', 'desc')->get()->toArray();
    }

    /**
     * 从数据库加载提供方并创建实例
     */
    public static function createProviderFromDb(string $providerId): ?AiProviderInterface
    {
        try {
            $provider = AiProvider::find($providerId);

            if (!$provider || !$provider->enabled) {
                Log::warning("AI provider {$providerId} not found or disabled");

                return null;
            }

            return self::createProviderInstance($provider->type, $provider->getConfigArray());
        } catch (\Throwable $e) {
            Log::error("Failed to create provider from DB: {$providerId}, error: " . $e->getMessage());

            return null;
        }
    }

    /**
     * 创建AI提供者实例（根据类型和配置）
     */
    public static function createProviderInstance(string $type, array $config = []): ?AiProviderInterface
    {
        $classMap = self::getProviderClassMap();

        if (!isset($classMap[$type])) {
            Log::error("Unknown AI provider type: {$type}");

            return null;
        }

        $class = $classMap[$type];

        return new $class($config);
    }

    /**
     * 获取当前配置的AI提供者（从轮询组或单个提供方）
     * 支持故障转移
     */
    public static function getCurrentProvider(array $excludeProviders = []): ?AiProviderInterface
    {
        $currentSelection = (string) blog_config('ai_current_selection', '', true);

        if (empty($currentSelection)) {
            Log::warning('No AI provider selected, trying first enabled provider');

            return self::getFirstAvailableProvider($excludeProviders);
        }

        if (str_starts_with($currentSelection, 'provider:')) {
            // 直接选择提供方
            $providerId = substr($currentSelection, 9);
            if (in_array($providerId, $excludeProviders, true)) {
                return self::getFirstAvailableProvider($excludeProviders);
            }

            return self::createProviderFromDb($providerId);
        }

        if (str_starts_with($currentSelection, 'group:')) {
            // 选择轮询组
            $groupId = (int) substr($currentSelection, 6);

            return self::getProviderFromGroup($groupId, $excludeProviders);
        }

        Log::error("Invalid selection format: {$currentSelection}");

        return self::getFirstAvailableProvider($excludeProviders);
    }

    /**
     * 获取第一个可用的提供方（作为预留）
     */
    private static function getFirstAvailableProvider(array $excludeProviders = []): ?AiProviderInterface
    {
        try {
            $provider = AiProvider::where('enabled', true)
                ->whereNotIn('id', $excludeProviders)
                ->orderBy('weight', 'desc')
                ->first();

            if (!$provider) {
                Log::warning('No available AI provider, using local echo');

                return self::createProviderInstance('local', []);
            }

            return self::createProviderInstance($provider->type, $provider->getConfigArray());
        } catch (\Throwable $e) {
            Log::error('Failed to get first available provider: ' . $e->getMessage());

            return self::createProviderInstance('local', []);
        }
    }

    /**
     * 从轮询组获取提供方（支持故障转移）
     */
    private static function getProviderFromGroup(int $groupId, array $excludeProviders = []): ?AiProviderInterface
    {
        try {
            $group = AiPollingGroup::with(['providers' => function ($query) use ($excludeProviders) {
                $query->where('enabled', true);
                if (!empty($excludeProviders)) {
                    $query->whereNotIn('provider_id', $excludeProviders);
                }
            }, 'providers.provider'])
                ->where('id', $groupId)
                ->where('enabled', true)
                ->first();

            if (!$group || $group->providers->isEmpty()) {
                Log::warning("Polling group {$groupId} not found or has no enabled providers");

                return self::getFirstAvailableProvider($excludeProviders);
            }

            // 获取提供方详情
            $providerRelations = $group->providers->filter(function ($rel) {
                return $rel->provider && $rel->provider->enabled;
            });

            if ($providerRelations->isEmpty()) {
                Log::warning("No valid providers in group {$groupId}");

                return self::getFirstAvailableProvider($excludeProviders);
            }

            // 根据策略选择提供方
            if ($group->strategy === 'polling') {
                return self::selectProviderByPolling($providerRelations->toArray());
            } elseif ($group->strategy === 'failover') {
                return self::selectProviderByFailover($providerRelations->toArray());
            }

            // 默认返回第一个
            $first = $providerRelations->first();

            return self::createProviderInstance($first->provider->type, $first->provider->getConfigArray());
        } catch (\Throwable $e) {
            Log::error('Failed to get provider from group: ' . $e->getMessage());

            return self::getFirstAvailableProvider($excludeProviders);
        }
    }

    /**
     * 轮询策略：根据权重加权随机选择
     *
     * @param array $providerRelations AiPollingGroupProvider 关联数据
     */
    private static function selectProviderByPolling(array $providerRelations): ?AiProviderInterface
    {
        if (empty($providerRelations)) {
            return null;
        }

        $totalWeight = array_sum(array_column($providerRelations, 'weight'));
        if ($totalWeight <= 0) {
            $totalWeight = count($providerRelations);
        }

        $random = mt_rand(1, $totalWeight);
        $currentWeight = 0;

        foreach ($providerRelations as $relation) {
            $weight = (int) ($relation['weight'] ?? 1);
            $currentWeight += $weight;

            if ($random <= $currentWeight && isset($relation['provider'])) {
                $provider = $relation['provider'];
                if (is_array($provider)) {
                    return self::createProviderInstance($provider['type'], json_decode($provider['config'] ?? '{}', true) ?: []);
                }

                return self::createProviderInstance($provider->type, $provider->getConfigArray());
            }
        }

        // 默认返回第一个
        $first = reset($providerRelations);
        if (isset($first['provider'])) {
            $provider = $first['provider'];
            if (is_array($provider)) {
                return self::createProviderInstance($provider['type'], json_decode($provider['config'] ?? '{}', true) ?: []);
            }

            return self::createProviderInstance($provider->type, $provider->getConfigArray());
        }

        return null;
    }

    /**
     * 主备策略：按权重顺序选择最高优先级
     *
     * @param array $providerRelations AiPollingGroupProvider 关联数据
     */
    private static function selectProviderByFailover(array $providerRelations): ?AiProviderInterface
    {
        if (empty($providerRelations)) {
            return null;
        }

        // 按权重降序排列
        usort($providerRelations, function ($a, $b) {
            return ($b['weight'] ?? 1) <=> ($a['weight'] ?? 1);
        });

        // 返回权重最高的提供者
        $first = reset($providerRelations);
        if (isset($first['provider'])) {
            $provider = $first['provider'];
            if (is_array($provider)) {
                return self::createProviderInstance($provider['type'], json_decode($provider['config'] ?? '{}', true) ?: []);
            }

            return self::createProviderInstance($provider->type, $provider->getConfigArray());
        }

        return null;
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
            $ch->queue_declare($queueName, false, true, false, false, false, new \PhpAmqpLib\Wire\AMQPTable([
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
