<?php

namespace app\process;

use app\controller\SkeletonController;
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
 * 骨架页生成进程
 *
 * 负责生成静态化的骨架页文件,支持 CDN 和浏览器缓存
 */
class SkeletonGenerator
{
    /** @var AMQPChannel|null */
    protected ?AMQPChannel $mqChannel = null;

    private string $exchange = 'windblog_skeleton_gen';

    private string $routingKey = 'skeleton_gen';

    private string $queueName = 'windblog_skeleton_queue';

    private string $dlxExchange = 'windblog_skeleton_dlx';

    private string $dlxQueue = 'windblog_skeleton_dlq';

    public function onWorkerStart(): void
    {
        if (!is_installed()) {
            Log::warning('SkeletonGenerator 检测到系统未安装,已跳过启动');

            return;
        }

        $this->initMq();
        $this->startConsumer();

        if (class_exists(Timer::class)) {
            Timer::add(60, function () {
                try {
                    MQService::checkAndHeal();
                } catch (Throwable $e) {
                    Log::warning('MQ 健康检查异常(SkeletonGenerator): ' . $e->getMessage());
                }
            });
        }
    }

    /**
     * 初始化 MQ 连接
     */
    protected function initMq(): void
    {
        try {
            $this->mqChannel = MQService::getChannel();

            $this->exchange = (string) blog_config('rabbitmq_skeleton_exchange', $this->exchange, true) ?: $this->exchange;
            $this->routingKey = (string) blog_config('rabbitmq_skeleton_routing_key', $this->routingKey, true) ?: $this->routingKey;
            $this->queueName = (string) blog_config('rabbitmq_skeleton_queue', $this->queueName, true) ?: $this->queueName;
            $this->dlxExchange = (string) blog_config('rabbitmq_skeleton_dlx_exchange', $this->dlxExchange, true) ?: $this->dlxExchange;
            $this->dlxQueue = (string) blog_config('rabbitmq_skeleton_dlx_queue', $this->dlxQueue, true) ?: $this->dlxQueue;

            MQService::declareDlx($this->mqChannel, $this->dlxExchange, $this->dlxQueue);
            MQService::setupQueueWithDlx($this->mqChannel, $this->exchange, $this->routingKey, $this->queueName, $this->dlxExchange, $this->dlxQueue);

            Log::info('SkeletonGenerator MQ 初始化成功');
        } catch (Throwable $e) {
            Log::error('SkeletonGenerator MQ 初始化失败: ' . $e->getMessage());
        }
    }

    /**
     * 启动消费者
     */
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
                    if ($this->mqChannel === null) {
                        Log::warning('SkeletonGenerator: channel is null, reconnecting...');
                        $this->reconnectMq();

                        return;
                    }
                    $this->mqChannel->wait(null, false, 1.0);
                } catch (AMQPTimeoutException $e) {
                } catch (Throwable $e) {
                    $errorMsg = $e->getMessage();
                    Log::warning('SkeletonGenerator 消费轮询异常: ' . $errorMsg);

                    if (str_contains($errorMsg, 'Channel connection is closed') ||
                        str_contains($errorMsg, 'Broken pipe') ||
                        str_contains($errorMsg, 'connection is closed') ||
                        str_contains($errorMsg, 'on null')) {
                        Log::warning('SkeletonGenerator 检测到连接断开,尝试重建连接');
                        $this->reconnectMq();
                    }
                }
            });
        }
    }

    /**
     * 处理消息
     */
    protected function handleMessage(AMQPMessage $message): void
    {
        try {
            $payload = json_decode($message->getBody(), true);
            if (!is_array($payload)) {
                throw new RuntimeException('消息体不是有效JSON');
            }

            $type = $payload['type'] ?? 'url';
            $options = $payload['options'] ?? [];
            $force = (bool) ($options['force'] ?? false);

            if ($type === 'url') {
                $url = (string) $payload['value'];
                $this->generateByUrl($url, $force);
            } elseif ($type === 'batch') {
                $urls = (array) ($payload['value'] ?? []);
                foreach ($urls as $url) {
                    $this->generateByUrl((string) $url, $force);
                }
            } else {
                throw new RuntimeException('未知消息类型: ' . $type);
            }

            $message->ack();
        } catch (Throwable $e) {
            Log::error('骨架页生成消息处理失败: ' . $e->getMessage());
            $this->handleFailedMessage($message);
        }
    }

    /**
     * 按 URL 生成骨架页
     */
    protected function generateByUrl(string $url, bool $force = false): void
    {
        try {
            $controller = new SkeletonController();
            $timestamp = time();

            $request = new \stdClass();
            $request->get = function ($key, $default = null) use ($url, $timestamp) {
                if ($key === 'target') {
                    return $url;
                }
                if ($key === 't') {
                    return $timestamp;
                }

                return $default;
            };

            $html = $controller->generateSkeletonHtml($url, $timestamp);
            $targetPath = $this->mapPath($url);
            $versionDir = $this->getVersionDir($timestamp);

            $baseDir = public_path() . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'skeleton';
            $final = $baseDir . DIRECTORY_SEPARATOR . $versionDir . DIRECTORY_SEPARATOR . $targetPath;
            $stage = public_path() . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'skeleton_tmp' . DIRECTORY_SEPARATOR . $versionDir . DIRECTORY_SEPARATOR . $targetPath;

            $finalDir = dirname($final);
            $stageDir = dirname($stage);
            if (!is_dir($finalDir)) {
                if (!mkdir($finalDir, 0o775, true)) {
                    throw new RuntimeException("无法创建目录: {$finalDir}");
                }
            }
            if (!is_dir($stageDir)) {
                if (!mkdir($stageDir, 0o775, true)) {
                    throw new RuntimeException("无法创建目录: {$stageDir}");
                }
            }

            if (file_exists($stage)) {
                @unlink($stage);
            }
            file_put_contents($stage, $html);

            if (!$force && file_exists($final)) {
                @unlink($stage);

                return;
            }

            if (file_exists($final)) {
                @unlink($final);
            }
            @rename($stage, $final);

            Log::info("骨架页生成成功: {$final}");
        } catch (Throwable $e) {
            Log::error('骨架页生成失败: ' . $e->getMessage());
        }
    }

    /**
     * URL 转文件路径
     */
    protected function mapPath(string $url): string
    {
        $path = ltrim($url, '/');
        if ($path === '' || $path === '/') {
            return 'index.html';
        }
        $path = preg_replace('#\.html$#', '', $path);
        $path = str_replace('/', '_', $path);

        return $path . '.html';
    }

    /**
     * 获取版本目录
     */
    protected function getVersionDir(int $timestamp): string
    {
        return substr(md5($timestamp), 0, 8);
    }

    /**
     * 处理失败消息
     */
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
            $newMsg = new AMQPMessage($message->getBody(), [
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'application_headers' => $newHeaders,
            ]);
            $this->mqChannel->basic_publish($newMsg, $this->exchange, $this->routingKey);
            $message->ack();
            Log::warning('骨架页生成消息重试: ' . ($retry + 1));
        } else {
            $message->reject(false);
            Log::error('骨架页生成消息进入死信队列');
        }
    }

    /**
     * 重建 MQ 连接
     */
    protected function reconnectMq(): void
    {
        try {
            $this->mqChannel = null;

            usleep(500000);

            $this->initMq();
            $this->startConsumer();

            Log::info('SkeletonGenerator MQ连接重建成功');
        } catch (Throwable $e) {
            Log::error('SkeletonGenerator MQ连接重建失败: ' . $e->getMessage());
            $this->mqChannel = null;
        }
    }

    /**
     * 发布消息
     */
    public function publish(array $data): void
    {
        if (!$this->mqChannel) {
            return;
        }
        $msg = new AMQPMessage(json_encode($data, JSON_UNESCAPED_UNICODE), [
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        ]);
        $this->mqChannel->basic_publish($msg, $this->exchange, $this->routingKey);
    }

    public function onWorkerStop(): void
    {
        try {
            if ($this->mqChannel) {
                $this->mqChannel->close();
            }
            MQService::closeConnection();
            Log::info('SkeletonGenerator MQ连接已关闭');
        } catch (Throwable $e) {
            Log::warning('关闭MQ连接失败: ' . $e->getMessage());
        }
    }
}
