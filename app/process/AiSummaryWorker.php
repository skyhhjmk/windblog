<?php

declare(strict_types=1);

namespace app\process;

use app\model\Post;
use app\model\PostExt;
use app\service\ai\AiProviderInterface;
use app\service\ai\providers\LocalEchoProvider;
use app\service\MQService;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
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
        // 注册内置占位提供者
        $this->providerRegistry['local.echo'] = new LocalEchoProvider();
        // TODO: 可在此注册更多提供者
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

            // 检查是否被标记为“持久化”，若是且非强制则跳过
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
        } catch (Throwable $e) {
            Log::error('AI Summary handle failed: ' . $e->getMessage());
            $this->handleFailedMessage($message);
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
        if ($specified && isset($this->providerRegistry[$specified])) {
            return $this->providerRegistry[$specified];
        }
        // 从配置读取 providers 列表，格式参考 Mail 多平台
        $list = blog_config('ai_providers', '[]', false, true, false);
        $providers = is_string($list) ? json_decode($list, true) : $list;
        if (is_array($providers)) {
            // 简易加权选择，回退到第一个
            $pool = [];
            foreach ($providers as $item) {
                if (!($item['enabled'] ?? true)) {
                    continue;
                }
                $id = (string) ($item['id'] ?? '');
                $w = max(1, (int) ($item['weight'] ?? 1));
                if ($id !== '' && isset($this->providerRegistry[$id])) {
                    for ($i = 0; $i < $w; $i++) {
                        $pool[] = $id;
                    }
                }
            }
            if ($pool) {
                $pick = $pool[random_int(0, count($pool) - 1)];

                return $this->providerRegistry[$pick];
            }
        }

        // 默认使用占位提供者
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
