<?php

namespace app\process;

use app\model\Link;
use app\service\MQService;
use Exception;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use support\Log;
use Throwable;
use Workerman\Timer;
use Workerman\Worker;

/**
 * 友链推送异步处理Worker
 * 负责CAT5扩展信息推送的异步任务
 *
 * 功能:
 * - 消费 link_push_queue 队列
 * - 异步推送扩展信息到对方站点
 * - 支持失败重试和死信队列
 */
class LinkPushWorker
{
    protected int $interval = 5;

    protected int $timerId;

    protected int $timerId2;

    // 请求配置
    protected int $requestTimeout = 30;

    protected int $maxRetries = 3;

    // MQ连接
    protected ?AMQPStreamConnection $mqConnection = null;

    protected $mqChannel = null;

    // 缓存的配置
    protected string $cachedQueueName = '';

    protected int $lastDebugLogTime = 0;

    public function onWorkerStart(Worker $worker): void
    {
        // 检查系统是否已安装
        if (!is_installed()) {
            Log::warning('LinkPushWorker 检测到系统未安装，已跳过启动');

            return;
        }

        Log::info('LinkPushWorker 进程已启动 - PID: ' . getmypid());

        try {
            $channel = MQService::getChannel();

            $exchange = (string) blog_config('rabbitmq_link_push_exchange', 'link_push_exchange', true);
            $routingKey = (string) blog_config('rabbitmq_link_push_routing_key', 'link_push', true);
            $this->cachedQueueName = (string) blog_config('rabbitmq_link_push_queue', 'link_push_queue', true);

            // 设置专属DLX
            $dlxExchange = (string) blog_config('rabbitmq_link_push_dlx_exchange', 'link_push_dlx_exchange', true);
            $dlxQueue = (string) blog_config('rabbitmq_link_push_dlx_queue', 'link_push_dlx_queue', true);

            MQService::declareDlx($channel, $dlxExchange, $dlxQueue);
            MQService::setupQueueWithDlx($channel, $exchange, $routingKey, $this->cachedQueueName, $dlxExchange, $dlxQueue);

            $this->mqChannel = $channel;
            $this->lastDebugLogTime = time();
            Log::info('RabbitMQ连接初始化成功(LinkPushWorker) - Queue: ' . $this->cachedQueueName);
        } catch (Exception $e) {
            Log::error('RabbitMQ连接初始化失败(LinkPushWorker): ' . $e->getMessage());
        }

        // 定时输出状态
        //        $this->timerId = Timer::add(60, function () {
        //            $memoryUsage = memory_get_usage(true) / 1024 / 1024;
        //            $peak = memory_get_peak_usage(true) / 1024 / 1024;
        //            Log::debug("LinkPushWorker 状态 - 内存: {$memoryUsage}MB, 峰值: {$peak}MB");
        //        });

        // MQ健康检查
        Timer::add(60, function () {
            try {
                MQService::checkAndHeal();
            } catch (Throwable $e) {
                Log::warning('MQ 健康检查异常(LinkPushWorker): ' . $e->getMessage());
            }
        });

        $this->processMessages();
        $this->timerId2 = Timer::add($this->interval, [$this, 'processMessages']);
    }

    public function processMessages(): void
    {
        try {
            $channel = $this->getMqChannel();

            // 使用缓存的队列名称
            $message = $channel->basic_get($this->cachedQueueName, false);

            if ($message) {
                Log::debug('[LinkPushWorker] 收到推送任务');
                $this->handleMessage($message);
            } else {
                // 每 60 秒输出一次日志
                $now = time();
                if ($now - $this->lastDebugLogTime >= 60) {
                    //                    Log::debug('[LinkPushWorker] 队列无消息');
                    $this->lastDebugLogTime = $now;
                }
            }
        } catch (Exception $e) {
            $errorMsg = $e->getMessage();
            Log::error('LinkPushWorker 消费异常: ' . $errorMsg);

            // 检测通道连接断开，触发自愈
            if (strpos($errorMsg, 'Channel connection is closed') !== false ||
                strpos($errorMsg, 'Broken pipe') !== false ||
                strpos($errorMsg, 'connection is closed') !== false) {
                Log::warning('LinkPushWorker 检测到连接断开，尝试重建连接');
                $this->reconnectMq();
            }
        }
    }

    protected function getMqChannel()
    {
        if ($this->mqChannel === null) {
            $conn = $this->getMqConnection();
            $this->mqChannel = $conn->channel();
            $this->mqChannel->basic_qos(0, 1, false);
        }

        return $this->mqChannel;
    }

    protected function getMqConnection(): AMQPStreamConnection
    {
        if ($this->mqConnection === null) {
            $this->mqConnection = new AMQPStreamConnection(
                blog_config('rabbitmq_host', '127.0.0.1', true),
                blog_config('rabbitmq_port', 5672, true),
                blog_config('rabbitmq_user', 'guest', true),
                blog_config('rabbitmq_password', 'guest', true)
            );
        }

        return $this->mqConnection;
    }

    /**
     * 重建 MQ 连接（自愈机制）
     */
    protected function reconnectMq(): void
    {
        try {
            // 关闭现有连接
            $this->closeMqConnection();

            // 等待短暂时间后重建
            usleep(500000); // 0.5秒

            // 重新获取通道
            $channel = MQService::getChannel();
            $this->mqChannel = $channel;

            Log::info('LinkPushWorker MQ连接重建成功');
        } catch (Throwable $e) {
            Log::error('LinkPushWorker MQ连接重建失败: ' . $e->getMessage());
            $this->mqChannel = null;
            $this->mqConnection = null;
        }
    }

    public function handleMessage(AMQPMessage $message): void
    {
        $data = json_decode($message->body, true);
        $taskId = $data['task_id'] ?? uniqid('lp_', true);
        $linkId = $data['link_id'] ?? 0;
        $peerApi = $data['peer_api'] ?? '';
        $payload = $data['payload'] ?? [];

        Log::info("[LinkPushWorker] 开始处理推送任务 [{$taskId}] - Link ID: {$linkId}, Peer: {$peerApi}");

        try {
            if (!$linkId || !$peerApi || empty($payload)) {
                Log::warning("[LinkPushWorker] 无效消息 [{$taskId}]: " . $message->body);
                $message->ack();

                return;
            }

            // 验证Link记录是否存在且已审核通过
            $link = Link::find($linkId);
            if (!$link) {
                Log::warning("[LinkPushWorker] Link记录不存在 [{$taskId}]: {$linkId}");
                $message->ack();

                return;
            }

            if (!$link->status) {
                Log::warning("[LinkPushWorker] Link未审核通过 [{$taskId}]: {$linkId}");
                $message->ack();

                return;
            }

            // 发送HTTP推送请求
            Log::info("[LinkPushWorker] 发送推送请求 [{$taskId}] 到: {$peerApi}");
            $startTime = microtime(true);
            $result = $this->sendPushRequest($peerApi, $payload);
            $endTime = microtime(true);
            $responseTime = round(($endTime - $startTime) * 1000, 2);

            if ($result['success']) {
                Log::info("[LinkPushWorker] 推送成功 [{$taskId}] - 耗时: {$responseTime}ms");

                // 更新推送时间
                $link->setCustomField('peer_last_push', utc_now_string('Y-m-d H:i:s'));
                $link->setCustomField('peer_last_push_status', 'success');
                $link->save();

                $message->ack();
            } else {
                Log::warning("[LinkPushWorker] 推送失败 [{$taskId}] - 原因: " . ($result['error'] ?? '未知错误'));

                // 更新失败状态
                $link->setCustomField('peer_last_push_status', 'failed');
                $link->setCustomField('peer_last_push_error', $result['error'] ?? '未知错误');
                $link->save();

                $this->handleFailedMessage($message);
            }

        } catch (Exception $e) {
            Log::error("[LinkPushWorker] 处理异常 [{$taskId}]: " . $e->getMessage());
            $this->handleFailedMessage($message);
        }
    }

    /**
     * 发送推送请求
     */
    protected function sendPushRequest(string $url, array $payload): array
    {
        try {
            $token = (string) blog_config('wind_connect_token', '', true);
            $sslVerify = (bool) blog_config('wind_connect_ssl_verify', true, true);
            $timeout = (int) blog_config('link_push_timeout', 30, true);

            Log::debug("[LinkPushWorker] 准备发送请求 - URL: {$url}, Timeout: {$timeout}s");

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-WIND-CONNECT-TOKEN: ' . $token,
                ],
                CURLOPT_SSL_VERIFYPEER => $sslVerify,
                CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            Log::debug("[LinkPushWorker] 请求完成 - HTTP Code: {$httpCode}, Response: " . substr($response ?: '', 0, 200));

            if ($error) {
                return ['success' => false, 'error' => $error];
            }

            if ($httpCode < 200 || $httpCode >= 300) {
                return ['success' => false, 'error' => "HTTP {$httpCode}"];
            }

            return ['success' => true, 'response' => $response];

        } catch (Throwable $e) {
            Log::error('[LinkPushWorker] 请求异常: ' . $e->getMessage());

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 处理失败的消息
     */
    protected function handleFailedMessage(AMQPMessage $message): void
    {
        try {
            $retry = 0;
            $headers = $message->has('application_headers') ? $message->get('application_headers') : null;

            if ($headers) {
                $native = method_exists($headers, 'getNativeData') ? $headers->getNativeData() : (array) $headers;
                $retry = (int) ($native['x-retry-count'] ?? 0);
            }

            if ($retry < $this->maxRetries) {
                $message->nack(true); // 重新入队
                Log::warning('[LinkPushWorker] 消息重试: ' . ($retry + 1) . "/{$this->maxRetries}");
            } else {
                $message->nack(false); // 进入DLX
                Log::error("[LinkPushWorker] 重试超限({$this->maxRetries})，进入DLX");
            }
        } catch (Exception $e) {
            $message->nack(false);
            Log::error('[LinkPushWorker] 处理失败消息异常: ' . $e->getMessage());
        }
    }

    public function onWorkerStop(Worker $worker): void
    {
        if (isset($this->timerId)) {
            Timer::del($this->timerId);
        }
        if (isset($this->timerId2)) {
            Timer::del($this->timerId2);
        }
        $this->closeMqConnection();
    }

    protected function closeMqConnection(): void
    {
        try {
            if ($this->mqChannel !== null) {
                $this->mqChannel->close();
                $this->mqChannel = null;
            }
            if ($this->mqConnection !== null) {
                $this->mqConnection->close();
                $this->mqConnection = null;
            }
            Log::info('RabbitMQ连接已关闭(LinkPushWorker)');
        } catch (Exception $e) {
            Log::error('关闭MQ连接异常(LinkPushWorker): ' . $e->getMessage());
        }
    }
}
