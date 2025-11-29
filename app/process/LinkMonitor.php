<?php

namespace app\process;

use app\model\Link;
use app\service\MQService;
use Exception;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use support\Log;
use Throwable;
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
    protected int $timerId;

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
        // 不再直接创建连接，统一通过 MQService 管理；此方法保留以兼容类型，但不会被调用
        if ($this->mqConnection === null) {
            $this->mqConnection = null;
        }

        return MQService::getChannel()->getConnection();
    }

    protected function getMqChannel()
    {
        if ($this->mqChannel === null) {
            $this->mqChannel = MQService::getChannel();

            // 初始化队列和交换机
            $exchange = (string) blog_config('rabbitmq_link_monitor_exchange', 'link_monitor_exchange', true);
            $routingKey = (string) blog_config('rabbitmq_link_monitor_routing_key', 'link_monitor', true);
            $queueName = (string) blog_config('rabbitmq_link_monitor_queue', 'link_monitor_queue', true);

            // 为 LinkMonitor 使用专属 DLX/DLQ，不共用
            $dlxExchange = (string) blog_config('rabbitmq_link_monitor_dlx_exchange', 'link_monitor_dlx_exchange', true);
            $dlxQueue = (string) blog_config('rabbitmq_link_monitor_dlx_queue', 'link_monitor_dlx_queue', true);

            MQService::declareDlx($this->mqChannel, $dlxExchange, $dlxQueue);
            MQService::setupQueueWithDlx($this->mqChannel, $exchange, $routingKey, $queueName, $dlxExchange, $dlxQueue);

            // 注册消费者
            $this->mqChannel->basic_consume(
                $queueName,
                '',
                false,
                false, // no_ack
                false,
                false,
                [$this, 'handleMessage']
            );
        }

        return $this->mqChannel;
    }

    public function onWorkerStart(Worker $worker): void
    {
        // 检查系统是否已安装
        if (!is_installed()) {
            Log::warning('LinkMonitor 检测到系统未安装，已跳过启动');

            return;
        }

        Log::info('LinkMonitor 进程已启动 - PID: ' . getmypid());

        try {
            // 初始化MQ通道
            $this->getMqChannel();
        } catch (Exception $e) {
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
                MQService::checkAndHeal();
            } catch (Throwable $e) {
                Log::warning('MQ 健康检查异常(LinkMonitor): ' . $e->getMessage());
            }
        });

        // 使用事件驱动模式处理消息
        Timer::add(1, function () {
            try {
                if ($this->mqChannel === null) {
                    Log::warning('LinkMonitor: channel is null, reconnecting...');
                    // 重新初始化通道
                    $this->mqChannel = null;
                    $this->getMqChannel();

                    return;
                }
                $this->mqChannel->wait(null, false, 1.0);
            } catch (AMQPTimeoutException $e) {
                // noop
            } catch (Throwable $e) {
                $errorMsg = $e->getMessage();
                Log::warning('LinkMonitor 消费轮询异常: ' . $errorMsg);

                // 检测通道连接断开，触发自愈
                if (strpos($errorMsg, 'Channel connection is closed') !== false ||
                    strpos($errorMsg, 'Broken pipe') !== false ||
                    strpos($errorMsg, 'connection is closed') !== false) {
                    Log::warning('LinkMonitor 检测到连接断开，尝试重建连接');
                    $this->mqChannel = null;
                    $this->getMqChannel();
                }
            }
        });
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

            // 首页监控
            $fetch = $this->fetchWebContent($url);
            if (!$fetch['success']) {
                $this->recordFailure($url, $fetch['error']);
                $this->handleFailedMessage($message, $url);

                return;
            }

            $html = $fetch['html'];
            $loadTime = $fetch['load_time'];

            $summary = [
                'time' => utc_now_string('Y-m-d H:i:s'),
                'ok' => true,
                'load_time' => $loadTime . 'ms',
                'backlink' => ['found' => false, 'count' => 0],
                'errors' => [],
            ];

            // 反链检查：首页
            $backlinkResult = ['found' => false, 'link_count' => 0];
            if ($myDomain) {
                $backlinkResult = $this->checkBacklink($html, $myDomain);
            }

            // 如果有 link_id，检查是否需要监控友链页面，并保存结果
            $linkModel = null;
            if ($linkId) {
                try {
                    $linkModel = Link::find((int) $linkId);
                    if ($linkModel) {
                        $linkPosition = $linkModel->getCustomField('link_position', '');
                        $pageLink = $linkModel->getCustomField('page_link', '');

                        // 如果有友链页面且不是首页，需要额外监控友链页面
                        if (!empty($pageLink) && $linkPosition !== 'homepage' && $myDomain) {
                            try {
                                $pageFetch = $this->fetchWebContent($pageLink);
                                if ($pageFetch['success']) {
                                    $pageBacklink = $this->checkBacklink($pageFetch['html'], $myDomain);

                                    // 合并反链结果：只要其中一个页面找到反链就认为找到了
                                    if ($pageBacklink['found'] ?? false) {
                                        $backlinkResult['found'] = true;
                                        $backlinkResult['link_count'] = ($backlinkResult['link_count'] ?? 0) + ($pageBacklink['link_count'] ?? 0);
                                    }

                                    // 记录检测信息
                                    $summary['page_link_checked'] = true;
                                    $summary['page_link_url'] = $pageLink;
                                    $summary['page_link_found'] = $pageBacklink['found'] ?? false;
                                    $summary['page_link_count'] = $pageBacklink['link_count'] ?? 0;

                                    Log::debug('LinkMonitor 检测友链页面', [
                                        'link_id' => $linkId,
                                        'page_link' => $pageLink,
                                        'homepage_found' => $backlinkResult['found'] ?? false,
                                        'pagepage_found' => $pageBacklink['found'] ?? false,
                                        'total_count' => $backlinkResult['link_count'] ?? 0,
                                    ]);
                                } else {
                                    $summary['errors'][] = '无法访问友链页面：' . $pageFetch['error'];
                                    Log::warning('LinkMonitor: 无法访问友链页面', [
                                        'link_id' => $linkId,
                                        'page_link' => $pageLink,
                                        'error' => $pageFetch['error'],
                                    ]);
                                }
                            } catch (Throwable $e) {
                                $summary['errors'][] = '检测友链页面异常：' . $e->getMessage();
                                Log::error('LinkMonitor: 检测友链页面异常', [
                                    'link_id' => $linkId,
                                    'page_link' => $pageLink,
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }
                    }
                } catch (Throwable $e) {
                    Log::warning('LinkMonitor 获取友链信息失败: ' . $e->getMessage());
                }
            }

            // 设置最终反链结果
            $summary['backlink'] = [
                'found' => $backlinkResult['found'] ?? false,
                'count' => $backlinkResult['link_count'] ?? 0,
            ];

            // 保存监控结果
            if ($linkModel) {
                try {
                    $linkModel->setCustomField('monitor', $summary);
                    $linkModel->save();
                } catch (Throwable $e) {
                    Log::warning('LinkMonitor 保存概要失败: ' . $e->getMessage());
                }
            }

            $this->clearFailureStats($url);
            $message->ack();
        } catch (Exception $e) {
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
        } catch (Exception $e) {
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
                'first_failure' => utc_now()->timestamp,
                'last_error' => '',
                'url' => $url,
            ];
        }
        $this->failureStats[$key]['count']++;
        $this->failureStats[$key]['last_error'] = $error;
        $this->failureStats[$key]['last_failure'] = utc_now()->timestamp;
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
        if ($stats['count'] >= 3 && (utc_now()->timestamp - $stats['first_failure']) < 3600) {
            return true;
        }

        return false;
    }

    protected function handleFailedMessage(AMQPMessage $message, string $url): void
    {
        try {
            $retry = 0;
            $headers = $message->has('application_headers') ? $message->get('application_headers') : null;
            if ($headers instanceof AMQPTable) {
                $native = method_exists($headers, 'getNativeData') ? $headers->getNativeData() : (array) $headers;
                $retry = (int) ($native['x-retry-count'] ?? 0);
            }

            if ($this->shouldSendToDeadLetter($url)) {
                $message->nack(false);
                Log::error('LinkMonitor URL超限，直接DLX: ' . $url);

                return;
            }

            if ($retry < 2) {
                $newHeaders = $headers ? clone $headers : new AMQPTable();
                $newHeaders->set('x-retry-count', $retry + 1);
                $message->set('application_headers', $newHeaders);
                $message->nack(true); // 重新入队
                Log::warning('LinkMonitor 消息重试: ' . ($retry + 1) . '/3');
            } else {
                $message->nack(false);
                Log::error('LinkMonitor 重试超限(3)，进入DLX');
            }
        } catch (Exception $e) {
            $message->nack(false);
            Log::error('LinkMonitor 处理失败消息异常，DLX: ' . $e->getMessage());
        }
    }

    protected function closeMqConnection(): void
    {
        try {
            // 统一通过 MQService 关闭
            MQService::closeConnection();
            $this->mqChannel = null;
            $this->mqConnection = null;
            Log::info('RabbitMQ连接已关闭(LinkMonitor)');
        } catch (Exception $e) {
            Log::error('关闭MQ连接异常(LinkMonitor): ' . $e->getMessage());
        }
    }

    public function onWorkerStop(Worker $worker): void
    {
        if (isset($this->timerId)) {
            Timer::del($this->timerId);
        }
        $this->closeMqConnection();
    }
}
