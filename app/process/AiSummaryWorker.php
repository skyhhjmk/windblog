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
use RuntimeException;
use support\Log;
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

        // 标记状态为刷新中，并记录开始时间
        $this->setAiMeta($postId, [
            'enabled' => (bool) ($meta['enabled'] ?? true),
            'status' => 'refreshing',
            'started_at' => date('Y-m-d H:i:s'),
        ]);

        $content = (string) $post->content;
        $prov = $this->chooseProvider($providerId);

        // 获取摘要提示词
        $defaultPrompt = <<<'EOF'
            请为以下文章生成一个简洁的摘要，重点阐述文章的主要内容和核心观点。

            要求：
            1. 摘要长度为140-160字
            2. 着重描述文章讲了什么，概括主要内容和观点
            3. 使用简洁、流畅的语言，避免冗长
            4. 不使用表情符号、特殊符号，标点符号仅保留必要的逗号和句号
            5. 保持客观、中立的陈述角度

            直接输出摘要内容，不要添加“本文介绍了”、“摘要：”等前缀词。
            EOF;
        $prompt = (string) blog_config('ai_summary_prompt', $defaultPrompt, true);

        // 使用统一的 call 方法调用摘要任务
        $result = $prov->call('summarize', ['content' => $content, 'prompt' => $prompt], $options);

        if (!($result['ok'] ?? false)) {
            $errorMsg = (string) ($result['error'] ?? 'unknown');
            $this->setAiMeta($postId, [
                'enabled' => (bool) ($meta['enabled'] ?? true),
                'status' => 'failed',
                'error' => $errorMsg,
                'failed_at' => date('Y-m-d H:i:s'),
                'provider' => $prov->getId(),
            ]);
            // 失败后直接ACK，不重试
            $message->ack();
            Log::error("AI summarize failed for post {$postId}: {$errorMsg}");

            return;
        }

        $summary = (string) ($result['result'] ?? '');
        if (empty($summary)) {
            $this->setAiMeta($postId, [
                'enabled' => (bool) ($meta['enabled'] ?? true),
                'status' => 'failed',
                'error' => 'Empty summary returned',
                'failed_at' => date('Y-m-d H:i:s'),
                'provider' => $prov->getId(),
            ]);
            $message->ack();
            Log::error("AI returned empty summary for post {$postId}");

            return;
        }

        // 更新文章字段
        $post->ai_summary = $summary;
        $post->save();

        $this->setAiMeta($postId, [
            'enabled' => (bool) ($meta['enabled'] ?? true),
            'status' => 'done',
            'provider' => $prov->getId(),
            'usage' => $result['usage'] ?? [],
            'model' => $result['model'] ?? null,
            'generated_at' => date('Y-m-d H:i:s'),
        ]);

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

        Log::debug("AI generic task processing started: {$taskId}, task: {$task}, provider: {$providerId}");

        // 标记任务开始处理
        AISummaryService::setTaskStatus($taskId, 'processing', null, null);

        try {
            $prov = $this->chooseProvider($providerId);
            $result = $prov->call($task, $params, $options);

            if (!($result['ok'] ?? false)) {
                $errorMsg = (string) ($result['error'] ?? 'unknown');
                Log::error("AI generic task failed: {$taskId}, error: {$errorMsg}");
                AISummaryService::setTaskStatus($taskId, 'failed', null, $errorMsg);
                throw new RuntimeException('AI task failed: ' . $errorMsg);
            }

            // 保存结果
            Log::debug("AI generic task completed: {$taskId}");
            AISummaryService::setTaskStatus($taskId, 'completed', [
                'result' => $result['result'] ?? '',
                'usage' => $result['usage'] ?? null,
                'model' => $result['model'] ?? null,
                'finish_reason' => $result['finish_reason'] ?? null,
            ], null);

            $message->ack();
        } catch (Throwable $e) {
            Log::error("AI generic task exception: {$taskId}, exception: {$e->getMessage()}");
            AISummaryService::setTaskStatus($taskId, 'failed', null, $e->getMessage());
            throw $e;
        }
    }

    protected function handleFailedMessage(AMQPMessage $message): void
    {
        try {
            // 解析消息体以获取任务信息
            $data = json_decode($message->getBody(), true);
            if (is_array($data)) {
                $taskType = (string) ($data['task_type'] ?? 'summarize');

                if ($taskType === 'summarize' && isset($data['post_id'])) {
                    // 摘要任务失败，更新状态
                    $postId = (int) $data['post_id'];
                    $meta = $this->getAiMeta($postId);
                    $this->setAiMeta($postId, [
                        'enabled' => (bool) ($meta['enabled'] ?? true),
                        'status' => 'failed',
                        'error' => 'Message processing failed',
                        'failed_at' => date('Y-m-d H:i:s'),
                    ]);
                } elseif (isset($data['task_id'])) {
                    // 通用任务失败
                    $taskId = (string) $data['task_id'];
                    AISummaryService::setTaskStatus($taskId, 'failed', null, 'Message processing failed');
                }
            }
        } catch (Throwable $e) {
            Log::error('Failed to handle failed message metadata: ' . $e->getMessage());
        }

        // 直接ACK，不重试，避免无限循环
        $message->ack();
        Log::warning('AI task message failed and acknowledged without retry');
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
}
