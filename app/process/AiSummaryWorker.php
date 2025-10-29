<?php

declare(strict_types=1);

namespace app\process;

use app\model\Post;
use app\model\PostExt;
use app\service\ai\AiProviderInterface;
use app\service\ai\providers\LocalEchoProvider;
use app\service\AISummaryService;
use app\service\MQService;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use RuntimeException;
use support\Log;
use support\Redis as SupportRedis;
use Throwable;
use Workerman\Timer;

/**
 * AI 摘要生成工作进程
 * - 参考 MailWorker 设计：多提供者/模型轮询
 * - 解耦：通过 AiProviderInterface 适配不同平台与模型
 */
class AiSummaryWorker
{
    /** @var AMQPChannel|null */
    protected $mqChannel = null;

    // MQ 命名（可通过 blog_config 覆盖）
    protected string $exchange = 'ai_summary_exchange';

    protected string $routingKey = 'ai_summary_generate';

    protected string $queueName = 'ai_summary_queue';

    protected string $dlxExchange = 'ai_summary_dlx_exchange';

    protected string $dlq = 'ai_summary_dlx_queue';

    /** @var array<string, AiProviderInterface> */
    protected array $providerRegistry = [];

    public function onWorkerStart(): void
    {
        if (!is_installed()) {
            Log::warning('AiSummaryWorker: system not installed, skip');

            return;
        }

        $this->initMq();
        $this->initProviders();
        $this->startConsumer();

        // MQ 健康检查
        if (class_exists(Timer::class)) {
            Timer::add(60, function () {
                try {
                    MQService::checkAndHeal();
                } catch (Throwable $e) {
                    Log::warning('MQ health check (AI): ' . $e->getMessage());
                }
            });
        }
    }

    protected function initMq(): void
    {
        try {
            $this->mqChannel = MQService::getChannel();

            $this->exchange = (string) blog_config('rabbitmq_ai_exchange', $this->exchange, true) ?: $this->exchange;
            $this->routingKey = (string) blog_config('rabbitmq_ai_routing_key', $this->routingKey, true) ?: $this->routingKey;
            $this->queueName = (string) blog_config('rabbitmq_ai_queue', $this->queueName, true) ?: $this->queueName;
            $this->dlxExchange = (string) blog_config('rabbitmq_ai_dlx_exchange', $this->dlxExchange, true) ?: $this->dlxExchange;
            $this->dlq = (string) blog_config('rabbitmq_ai_dlx_queue', $this->dlq, true) ?: $this->dlq;

            MQService::declareDlx($this->mqChannel, $this->dlxExchange, $this->dlq);
            MQService::setupQueueWithDlx($this->mqChannel, $this->exchange, $this->routingKey, $this->queueName, $this->dlxExchange, $this->dlq);
        } catch (Throwable $e) {
            Log::error('AiSummaryWorker MQ init failed: ' . $e->getMessage());
        }
    }

    protected function initProviders(): void
    {
        // 不册需要预先注册提供者
        // 所有提供者将从数据库动态加载
    }

    protected function startConsumer(): void
    {
        if (!$this->mqChannel) {
            return;
        }
        $this->mqChannel->basic_qos(0, 1, null);
        $this->mqChannel->basic_consume($this->queueName, '', false, false, false, false, function (AMQPMessage $message) {
            $this->handleMessage($message);
        });
        if (class_exists(Timer::class)) {
            Timer::add(1, function () {
                try {
                    $this->mqChannel?->wait(null, false, 1.0);
                } catch (AMQPTimeoutException $e) { /* noop */
                } catch (Throwable $e) {
                    Log::warning('AI wait: ' . $e->getMessage());
                }
            });
        }
    }

    protected function handleMessage(AMQPMessage $message): void
    {
        try {
            $data = json_decode($message->getBody(), true);
            if (!is_array($data)) {
                throw new RuntimeException('Invalid payload');
            }

            // 支持两种模式：文章摘要和通用任务
            $taskType = (string) ($data['task_type'] ?? 'summarize');

            if ($taskType === 'summarize') {
                $this->handleSummarizeTask($data, $message);
            } else {
                $this->handleGenericTask($data, $message);
            }
        } catch (Throwable $e) {
            Log::error('AI task handle failed: ' . $e->getMessage());
            $this->handleFailedMessage($message);
        }
    }

    /**
     * 处理文章摘要任务（原有逻辑）
     */
    protected function handleSummarizeTask(array $data, AMQPMessage $message): void
    {
        $postId = (int) ($data['post_id'] ?? 0);
        if ($postId <= 0) {
            throw new RuntimeException('Missing post_id');
        }
        $providerId = (string) ($data['provider'] ?? '');
        $options = (array) ($data['options'] ?? []);

        $post = Post::find($postId);
        if (!$post) {
            throw new RuntimeException('Post not found: ' . $postId);
        }

        // 检查是否被标记为"持久化"，若是且非强制则跳过
        $meta = $this->getAiMeta($postId);
        $force = (bool) ($options['force'] ?? false);
        if (($meta['status'] ?? 'none') === 'persisted' && !$force) {
            $message->ack();

            return;
        }

        // 标记状态为刷新中
        $this->setAiMeta($postId, ['enabled' => (bool) ($meta['enabled'] ?? true), 'status' => 'refreshing']);

        $content = (string) $post->content;
        $prov = $this->chooseProvider($providerId);
        $result = $prov->summarize($content, $options);

        if (!($result['ok'] ?? false)) {
            $this->setAiMeta($postId, ['enabled' => (bool) ($meta['enabled'] ?? true), 'status' => 'failed', 'error' => (string) ($result['error'] ?? 'unknown')]);
            throw new RuntimeException('AI summarize failed: ' . ($result['error'] ?? 'unknown'));
        }

        $summary = (string) ($result['summary'] ?? '');
        // 更新文章字段
        $post->ai_summary = $summary;
        $post->save();

        $this->setAiMeta($postId, ['enabled' => (bool) ($meta['enabled'] ?? true), 'status' => 'done', 'provider' => $prov->getId(), 'usage' => $result['usage'] ?? []]);

        $message->ack();
    }

    /**
     * 处理通用AI任务（chat, generate, translate等）
     */
    protected function handleGenericTask(array $data, AMQPMessage $message): void
    {
        $taskId = (string) ($data['task_id'] ?? '');
        if (empty($taskId)) {
            throw new RuntimeException('Missing task_id');
        }

        $task = (string) ($data['task'] ?? 'chat');
        $providerId = (string) ($data['provider'] ?? '');
        $params = (array) ($data['params'] ?? []);
        $options = (array) ($data['options'] ?? []);

        // 标记任务开始处理
        $this->setTaskStatus($taskId, 'processing', null, null);

        try {
            $prov = $this->chooseProvider($providerId);
            $result = $prov->call($task, $params, $options);

            if (!($result['ok'] ?? false)) {
                $this->setTaskStatus($taskId, 'failed', null, (string) ($result['error'] ?? 'unknown'));
                throw new RuntimeException('AI task failed: ' . ($result['error'] ?? 'unknown'));
            }

            // 保存结果
            $this->setTaskStatus($taskId, 'completed', [
                'result' => $result['result'] ?? '',
                'usage' => $result['usage'] ?? null,
                'model' => $result['model'] ?? null,
                'finish_reason' => $result['finish_reason'] ?? null,
            ], null);

            $message->ack();
        } catch (Throwable $e) {
            $this->setTaskStatus($taskId, 'failed', null, $e->getMessage());
            throw $e;
        }
    }

    protected function handleFailedMessage(AMQPMessage $message): void
    {
        $headers = $message->has('application_headers') ? $message->get('application_headers') : null;
        $retry = 0;
        if ($headers instanceof AMQPTable) {
            $native = method_exists($headers, 'getNativeData') ? $headers->getNativeData() : (array) $headers;
            $retry = (int) ($native['x-retry-count'] ?? 0);
        }
        if ($retry < 2) {
            $newHeaders = $headers ? clone $headers : new AMQPTable();
            $newHeaders->set('x-retry-count', $retry + 1);
            $newMsg = new AMQPMessage($message->getBody(), ['content_type' => 'application/json', 'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT, 'application_headers' => $newHeaders]);
            $this->mqChannel?->basic_publish($newMsg, $this->exchange, $this->routingKey);
            $message->ack();
        } else {
            // 进入 DLQ
            $message->reject(false);
        }
    }

    protected function chooseProvider(?string $specified = null): AiProviderInterface
    {
        // 如果指定了providerId，尝试从数据库加载
        if (!empty($specified)) {
            $provider = AISummaryService::createProviderFromDb($specified);
            if ($provider) {
                return $provider;
            }
            Log::warning("AiSummaryWorker: Specified provider not found: {$specified}, falling back to current provider");
        }

        // 使用当前配置的提供者（支持轮询组和单个提供者）
        $provider = AISummaryService::getCurrentProvider();
        if ($provider) {
            return $provider;
        }

        // 如果没有配置任何提供者，使用内置的本地测试提供者
        Log::warning('AiSummaryWorker: No AI provider configured, using local echo provider');
        if (!isset($this->providerRegistry['local.echo'])) {
            $this->providerRegistry['local.echo'] = new LocalEchoProvider();
        }

        return $this->providerRegistry['local.echo'];
    }

    /**
     * 读取 AI 元数据（来自 post_ext.key = 'ai_summary_meta'）
     *
     * @return array{enabled?:bool,status?:string,provider?:string,usage?:array,error?:string}
     */
    protected function getAiMeta(int $postId): array
    {
        $row = PostExt::where('post_id', $postId)->where('key', 'ai_summary_meta')->first();

        return $row?->value ?? [];
    }

    protected function setAiMeta(int $postId, array $meta): void
    {
        $row = PostExt::where('post_id', $postId)->where('key', 'ai_summary_meta')->first();
        if (!$row) {
            $row = new PostExt(['post_id' => $postId, 'key' => 'ai_summary_meta', 'value' => []]);
        }
        $row->value = array_merge((array) $row->value, $meta);
        $row->save();
    }

    /**
     * 设置通用任务状态（存储到Redis或数据库）
     * 状态: pending, processing, completed, failed
     *
     * @param string      $taskId 任务ID
     * @param string      $status 任务状态
     * @param array|null  $result 任务结果
     * @param string|null $error  错误信息
     */
    protected function setTaskStatus(string $taskId, string $status, ?array $result = null, ?string $error = null): void
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
            $redis = SupportRedis::connection('default');
            $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $redis->setex($cacheKey, 3600, $jsonData);

            Log::debug("AI task status updated: {$taskId} -> {$status}");
        } catch (Throwable $e) {
            Log::error("Failed to set task status: {$e->getMessage()}");
        }
    }
}
