<?php

namespace app\process;

use app\model\Link;
use app\service\MQService;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use support\Log;
use Workerman\Timer;
use Workerman\Worker;

/**
 * 友链监控消费进程
 * - 消费 link_monitor_queue
 * - 抓取目标站点信息，生成概要写入 Link.custom_fields['monitor']
 * - 阻塞消费 + 手动ACK/NACK，包含重试与死信
 */
class LinkMonitor
{
    protected int $interval = 5;

    protected int $timerId;

    protected int $timerId2;

    // 请求限制
    protected int $requestTimeout = 30;

    protected int $maxResponseSize = 8 * 1024 * 1024; // 8MB

    // MQ
    protected ?AMQPStreamConnection $mqConnection = null;

    protected $mqChannel = null;

    // 失败统计（URL维度，辅助DLX策略）
    protected array $failureStats = [];

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

    protected function getMqChannel()
    {
        if ($this->mqChannel === null) {
            $conn = $this->getMqConnection();
            $this->mqChannel = $conn->channel();
            $this->mqChannel->basic_qos(0, 1, false);
        }

        return $this->mqChannel;
    }

    public function onWorkerStart(Worker $worker): void
    {
        // 在启动前检查 .env 是否存在
        $envPath = base_path() . '/.env';
        if (!file_exists($envPath)) {
            Log::warning("LinkMonitor 检测到缺少 .env，已跳过启动：{$envPath}");

            return;
        }

        Log::info('LinkMonitor 进程已启动 - PID: ' . getmypid());
        try {
            // 使用 MQService 通道与通用方法初始化本进程专属的交换机/队列/死信
            $channel = MQService::getChannel();

            $exchange = (string) blog_config('rabbitmq_link_monitor_exchange', 'link_monitor_exchange', true);
            $routingKey = (string) blog_config('rabbitmq_link_monitor_routing_key', 'link_monitor', true);
            $queueName = (string) blog_config('rabbitmq_link_monitor_queue', 'link_monitor_queue', true);

            // 为 LinkMonitor 使用专属 DLX/DLQ，不共用
            $dlxExchange = (string) blog_config('rabbitmq_link_monitor_dlx_exchange', 'link_monitor_dlx_exchange', true);
            $dlxQueue = (string) blog_config('rabbitmq_link_monitor_dlx_queue', 'link_monitor_dlx_queue', true);

            MQService::declareDlx($channel, $dlxExchange, $dlxQueue);
            MQService::setupQueueWithDlx($channel, $exchange, $routingKey, $queueName, $dlxExchange, $dlxQueue);

            $this->mqChannel = $channel;
            Log::info('RabbitMQ连接初始化成功(LinkMonitor)');
        } catch (\Exception $e) {
            Log::error('RabbitMQ连接初始化失败(LinkMonitor): ' . $e->getMessage());
        }

        $this->timerId = Timer::add(60, function () {
            $memoryUsage = memory_get_usage(true) / 1024 / 1024;
            $peak = memory_get_peak_usage(true) / 1024 / 1024;
            Log::debug("LinkMonitor 状态 - 内存: {$memoryUsage}MB, 峰值: {$peak}MB");
        });
        // 每60秒进行一次 MQ 健康检查
        Timer::add(60, function () {
            try {
                \app\service\MQService::checkAndHeal();
            } catch (\Throwable $e) {
                Log::warning('MQ 健康检查异常(LinkMonitor): ' . $e->getMessage());
            }
        });

        $this->processMessages();
        $this->timerId2 = Timer::add($this->interval, [$this, 'processMessages']);
    }

    public function processMessages(): void
    {
        try {
            $queueName = blog_config('rabbitmq_link_monitor_queue', 'link_monitor_queue', true);
            $channel = $this->getMqChannel();

            $channel->basic_consume(
                $queueName,
                '',
                false,
                false, // no_ack
                false,
                false,
                [$this, 'handleMessage']
            );

            while ($channel->is_consuming()) {
                try {
                    $channel->wait(null, false, 1.0);
                } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
                    // 正常超时，无消息到达，忽略
                } catch (\Throwable $e) {
                    Log::warning('LinkMonitor 消费轮询异常: ' . $e->getMessage());
                    break;
                }
            }
        } catch (\Exception $e) {
            Log::error('LinkMonitor 消费异常: ' . $e->getMessage());
        }
    }

    public function handleMessage(AMQPMessage $message): void
    {
        $url = 'unknown';
        try {
            $data = json_decode($message->body, true);
            if (!$data || empty($data['url'])) {
                Log::warning('LinkMonitor 收到无效消息: ' . $message->body);
                $message->ack();

                return;
            }

            $url = $data['url'];
            $linkId = $data['link_id'] ?? null;
            $myDomain = $data['my_domain'] ?? (blog_config('site_url', '', true) ?: '');

            if ($this->shouldSendToDeadLetter($url)) {
                Log::error('URL连续失败超限，进入DLX: ' . $url);
                $message->nack(false);

                return;
            }

            $fetch = $this->fetchWebContent($url);
            if (!$fetch['success']) {
                $this->recordFailure($url, $fetch['error']);
                $this->handleFailedMessage($message, $url);

                return;
            }

            $html = $fetch['html'];
            $loadTime = $fetch['load_time'];

            $summary = [
                'time' => date('Y-m-d H:i:s'),
                'ok' => true,
                'load_time' => $loadTime . 'ms',
                'backlink' => ['found' => false, 'count' => 0],
                'errors' => [],
            ];

            if ($myDomain) {
                $back = $this->checkBacklink($html, $myDomain);
                $summary['backlink'] = [
                    'found' => $back['found'] ?? false,
                    'count' => $back['link_count'] ?? 0,
                ];
            }

            if ($linkId) {
                try {
                    $model = Link::find((int) $linkId);
                    if ($model) {
                        $model->setCustomField('monitor', $summary);
                        $model->save();
                    }
                } catch (\Throwable $e) {
                    Log::warning('LinkMonitor 保存概要失败: ' . $e->getMessage());
                }
            }

            $this->clearFailureStats($url);
            $message->ack();
        } catch (\Exception $e) {
            Log::error('LinkMonitor 处理异常: ' . $e->getMessage());
            $this->recordFailure($url, $e->getMessage());
            $this->handleFailedMessage($message, $url);
        }
    }

    protected function fetchWebContent(string $url): array
    {
        try {
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                return ['success' => false, 'error' => '无效URL'];
            }

            $ch = curl_init();
            $responseSize = 0;
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->requestTimeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_USERAGENT => 'WindBlog LinkMonitor/1.0',
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_HEADER => false,
                CURLOPT_NOPROGRESS => false,
                CURLOPT_PROGRESSFUNCTION => function ($ch, $dltotal, $dlnow) use (&$responseSize) {
                    if ($dlnow > $this->maxResponseSize) {
                        return 1; // abort
                    }
                    $responseSize = $dlnow;

                    return 0;
                },
            ]);
            $start = microtime(true);
            $html = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            $loadMs = round((microtime(true) - $start) * 1000, 2);

            if ($err) {
                return ['success' => false, 'error' => $err];
            }
            if ($code >= 400) {
                return ['success' => false, 'error' => 'HTTP错误: ' . $code];
            }

            return ['success' => true, 'html' => $html ?: '', 'load_time' => $loadMs];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    protected function checkBacklink(string $html, string $myDomain): array
    {
        $result = ['found' => false, 'link_count' => 0];
        $clean = preg_replace('/^(https?:\/\/)?(www\.)?/', '', $myDomain);
        $clean = rtrim($clean, '/');
        if ($clean === '') {
            return $result;
        }

        if (stripos($html, $clean) !== false) {
            $result['domain_mentioned'] = true;
        }

        if (preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
            foreach ($matches[1] as $href) {
                if (stripos($href, $clean) !== false) {
                    $result['found'] = true;
                    $result['link_count']++;
                }
            }
        }

        return $result;
    }

    protected function recordFailure(string $url, string $error): void
    {
        $key = md5($url);
        if (!isset($this->failureStats[$key])) {
            $this->failureStats[$key] = [
                'count' => 0,
                'first_failure' => time(),
                'last_error' => '',
                'url' => $url,
            ];
        }
        $this->failureStats[$key]['count']++;
        $this->failureStats[$key]['last_error'] = $error;
        $this->failureStats[$key]['last_failure'] = time();
        Log::warning("LinkMonitor 失败统计: {$url}, 次数: " . $this->failureStats[$key]['count'] . " 错误: {$error}");
    }

    protected function clearFailureStats(string $url): void
    {
        $key = md5($url);
        if (isset($this->failureStats[$key])) {
            unset($this->failureStats[$key]);
        }
    }

    protected function shouldSendToDeadLetter(string $url): bool
    {
        $key = md5($url);
        if (!isset($this->failureStats[$key])) {
            return false;
        }
        $stats = $this->failureStats[$key];
        if ($stats['count'] >= 3) {
            return true;
        }
        if ($stats['count'] >= 3 && (time() - $stats['first_failure']) < 3600) {
            return true;
        }

        return false;
    }

    protected function handleFailedMessage(AMQPMessage $message, string $url): void
    {
        try {
            $retry = 0;
            $headers = $message->has('application_headers') ? $message->get('application_headers') : null;
            if ($headers instanceof \PhpAmqpLib\Wire\AMQPTable) {
                $native = method_exists($headers, 'getNativeData') ? $headers->getNativeData() : (array) $headers;
                $retry = (int) ($native['x-retry-count'] ?? 0);
            }

            if ($this->shouldSendToDeadLetter($url)) {
                $message->nack(false);
                Log::error('LinkMonitor URL超限，直接DLX: ' . $url);

                return;
            }

            if ($retry < 2) {
                $newHeaders = $headers ? clone $headers : new \PhpAmqpLib\Wire\AMQPTable();
                $newHeaders->set('x-retry-count', $retry + 1);
                $message->set('application_headers', $newHeaders);
                $message->nack(true); // 重新入队
                Log::warning('LinkMonitor 消息重试: ' . ($retry + 1) . '/3');
            } else {
                $message->nack(false);
                Log::error('LinkMonitor 重试超限(3)，进入DLX');
            }
        } catch (\Exception $e) {
            $message->nack(false);
            Log::error('LinkMonitor 处理失败消息异常，DLX: ' . $e->getMessage());
        }
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
            Log::info('RabbitMQ连接已关闭(LinkMonitor)');
        } catch (\Exception $e) {
            Log::error('关闭MQ连接异常(LinkMonitor): ' . $e->getMessage());
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
}
