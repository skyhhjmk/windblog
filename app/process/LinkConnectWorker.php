<?php

namespace app\process;

use app\model\Link;
use app\service\LinkConnectQueueService;
use app\service\LinkConnectService;
use app\service\MQService;
use Exception;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use support\Log;
use Throwable;
use Workerman\Timer;
use Workerman\Worker;

/**
 * 友链互联异步处理Worker
 * 负责处理CAT3*友链申请的异步任务
 *
 * 功能:
 * - 消费 link_connect_queue 队列
 * - 异步发送友链申请到对方站点
 * - 支持失败重试和死信队列
 */
class LinkConnectWorker
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

    // 失败统计
    protected array $failureStats = [];

    // 缓存的配置
    protected string $cachedQueueName = '';

    protected int $lastDebugLogTime = 0;

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

            Log::info('LinkConnectWorker MQ连接重建成功');
        } catch (Throwable $e) {
            Log::error('LinkConnectWorker MQ连接重建失败: ' . $e->getMessage());
            $this->mqChannel = null;
            $this->mqConnection = null;
        }
    }

    public function onWorkerStart(Worker $worker): void
    {
        // 检查系统是否已安装
        if (!is_installed()) {
            Log::warning('LinkConnectWorker 检测到系统未安装，已跳过启动');

            return;
        }

        Log::info('LinkConnectWorker 进程已启动 - PID: ' . getmypid());

        try {
            $channel = MQService::getChannel();

            $exchange = (string) blog_config('rabbitmq_link_connect_exchange', 'link_connect_exchange', true);
            $routingKey = (string) blog_config('rabbitmq_link_connect_routing_key', 'link_connect', true);
            $this->cachedQueueName = (string) blog_config('rabbitmq_link_connect_queue', 'link_connect_queue', true);

            // 设置专属DLX
            $dlxExchange = (string) blog_config('rabbitmq_link_connect_dlx_exchange', 'link_connect_dlx_exchange', true);
            $dlxQueue = (string) blog_config('rabbitmq_link_connect_dlx_queue', 'link_connect_dlx_queue', true);

            MQService::declareDlx($channel, $dlxExchange, $dlxQueue);
            MQService::setupQueueWithDlx($channel, $exchange, $routingKey, $this->cachedQueueName, $dlxExchange, $dlxQueue);

            $this->mqChannel = $channel;
            $this->lastDebugLogTime = time();
            Log::info('RabbitMQ连接初始化成功(LinkConnectWorker) - Queue: ' . $this->cachedQueueName);
        } catch (Exception $e) {
            Log::error('RabbitMQ连接初始化失败(LinkConnectWorker): ' . $e->getMessage());
        }

        // 定时输出状态
        $this->timerId = Timer::add(60, function () {
            $memoryUsage = memory_get_usage(true) / 1024 / 1024;
            $peak = memory_get_peak_usage(true) / 1024 / 1024;
            Log::debug("LinkConnectWorker 状态 - 内存: {$memoryUsage}MB, 峰值: {$peak}MB");
        });

        // MQ健康检查
        Timer::add(60, function () {
            try {
                MQService::checkAndHeal();
            } catch (Throwable $e) {
                Log::warning('MQ 健康检查异常(LinkConnectWorker): ' . $e->getMessage());
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
                Log::debug('[LinkConnectWorker] 收到消息');
                $this->handleMessage($message);
            } else {
                // 每 60 秒输出一次日志
                $now = time();
                if ($now - $this->lastDebugLogTime >= 60) {
                    //                    Log::debug('[LinkConnectWorker] 队列无消息');
                    $this->lastDebugLogTime = $now;
                }
            }
        } catch (Exception $e) {
            $errorMsg = $e->getMessage();
            Log::error('LinkConnectWorker 消费异常: ' . $errorMsg);

            // 检测通道连接断开，触发自愈
            if (strpos($errorMsg, 'Channel connection is closed') !== false ||
                strpos($errorMsg, 'Broken pipe') !== false ||
                strpos($errorMsg, 'connection is closed') !== false) {
                Log::warning('LinkConnectWorker 检测到连接断开，尝试重建连接');
                $this->reconnectMq();
            }
        }
    }

    public function handleMessage(AMQPMessage $message): void
    {
        $data = json_decode($message->body, true);
        $taskId = $data['task_id'] ?? uniqid('lc_', true);

        Log::info("LinkConnectWorker 开始处理任务 [{$taskId}]");

        // 更新状态为processing
        LinkConnectQueueService::updateTaskStatus($taskId, 'processing', '正在处理...');

        try {
            if (!$data || !isset($data['peer_api'])) {
                Log::warning("LinkConnectWorker 收到无效消息 [{$taskId}]: " . $message->body);
                LinkConnectQueueService::updateTaskStatus($taskId, 'failed', '消息格式无效');
                $message->ack();

                return;
            }

            $peerApi = $data['peer_api'];
            $name = $data['name'] ?? '';
            $url = $data['url'] ?? '';
            $icon = $data['icon'] ?? '';
            $description = $data['description'] ?? '';
            $email = $data['email'] ?? '';
            $token = $data['token'] ?? null;

            Log::info("LinkConnectWorker 处理友链申请 [{$taskId}] - 目标: {$peerApi}");

            // === 步骤1: 如果有 token，直接跳过预获取，由 sendQuickConnectRequest 一次性完成 ===
            if ($token) {
                Log::info("[{$taskId}] 检测到 token，将在后续请求中一次性处理");
            }

            // 回填默认值
            if (empty($name)) {
                $name = (string) blog_config('title', 'WindBlog', true);
            }
            if (empty($url)) {
                $url = (string) blog_config('site_url', '', true);
            }
            if (empty($icon)) {
                $icon = (string) blog_config('favicon', '', true);
            }
            if (empty($description)) {
                $description = (string) blog_config('description', '', true);
            }
            if (empty($email)) {
                $email = (string) blog_config('admin_email', '', true);
            }

            // === 步骤2: 检查URL是否已存在，如果存在则直接使用现有记录 ===
            $existingLink = Link::where('url', $url)->first();
            if ($existingLink) {
                Log::info("[{$taskId}] 该链接已存在，使用现有记录 - ID: {$existingLink->id}, URL: {$url}");
                $linkId = $existingLink->id;
                // 不需要重复创建，直接跳到发送请求步骤
            } else {
                // === 步骤3: 创建Link记录 ===
                try {
                    $link = new Link();
                    $link->name = $name;
                    $link->url = $url;
                    $link->icon = $icon;
                    $link->description = $description;
                    $link->status = false; // pending
                    $link->sort_order = 999;
                    $link->target = '_blank';
                    $link->redirect_type = 'goto';
                    $link->show_url = true;
                    $link->email = $email;
                    $link->setCustomFields([
                        'peer_status' => 'pending',
                        'peer_api' => $peerApi,
                        'peer_protocol' => 'wind_connect',
                        'source' => 'wind_connect', // 标记为快速互联申请
                        'connect_status' => 'pending',
                    ]);
                    $link->save();

                    Log::info("[{$taskId}] 创建Link记录成功 - ID: {$link->id}, URL: {$url}");
                    $linkId = $link->id;
                } catch (Throwable $e) {
                    Log::error("[{$taskId}] 创建Link记录失败: " . $e->getMessage());
                    LinkConnectQueueService::updateTaskStatus($taskId, 'failed', '创建记录失败: ' . $e->getMessage());
                    $message->ack();

                    return;
                }
            }

            // === 步骤4: 发送互联请求到 A站 ===
            // 检查是否超过失败限制
            if ($this->shouldSendToDeadLetter($peerApi)) {
                Log::error("友链申请连续失败超限 [{$taskId}]，进入DLX: {$peerApi}");
                LinkConnectQueueService::updateTaskStatus($taskId, 'failed', '连续失败超限');
                $message->nack(false);

                return;
            }

            // === CAT3* 步骤5: 发送带有 create_backlink 的请求 ===
            // 构建对外载荷，包含 B站的信息
            $payload = [
                'create_backlink' => 'true',
                'peer_site' => [
                    'name' => $name,
                    'url' => $url,
                    'icon' => $icon,
                    'description' => $description,
                    'email' => $email,
                    'api' => $peerApi,
                ],
            ];

            // 发送HTTP请求到 A站的 quickConnect 接口
            $startTime = microtime(true);
            $result = $this->sendQuickConnectRequest($peerApi, $token, $payload);
            $endTime = microtime(true);
            $responseTime = round(($endTime - $startTime) * 1000, 2);

            if ($result['success']) {
                // 解析响应
                $responseData = json_decode($result['response'], true);

                // 如果有 token 且响应成功，标记 token 为已使用
                if ($token && isset($responseData['code']) && $responseData['code'] === 0) {
                    // 从响应中获取站点信息并更新 Link 记录
                    $remoteSite = $responseData['site'] ?? [];
                    $remoteLink = $responseData['link'] ?? [];

                    if (!empty($remoteSite['url'])) {
                        // 标记 token 为已使用
                        LinkConnectService::markTokenUsed($token, $remoteSite['url']);
                        Log::info("[{$taskId}] Token 已标记为已使用");

                        // 更新 Link 记录的信息（如果之前没有填充）
                        if ($linkId) {
                            $link = Link::find($linkId);
                            if ($link) {
                                $updated = false;
                                if (empty($link->name) || $link->name === 'WindBlog') {
                                    $link->name = $remoteLink['name'] ?? $remoteSite['name'] ?? $link->name;
                                    $updated = true;
                                }
                                if (empty($link->icon)) {
                                    $link->icon = $remoteLink['icon'] ?? $remoteSite['icon'] ?? '';
                                    $updated = true;
                                }
                                if (empty($link->description)) {
                                    $link->description = $remoteLink['description'] ?? $remoteSite['description'] ?? '';
                                    $updated = true;
                                }
                                if ($updated) {
                                    $link->save();
                                    Log::info("[{$taskId}] 已更新 Link 记录信息 - ID: {$linkId}");
                                }
                            }
                        }
                    }
                }

                // === CAT3* 步骤6: 检查 A站是否成功创建了回链 ===
                if (isset($responseData['backlink_id']) && $responseData['code'] === 0) {
                    Log::info("友链申请成功且已确认回链 [{$taskId}] - A站回链ID: {$responseData['backlink_id']}, 耗时: {$responseTime}ms");

                    // 更新本地link记录状态，保存对方回链ID
                    if ($linkId) {
                        $link = Link::find($linkId);
                        if ($link) {
                            $link->setCustomField('peer_backlink_id', $responseData['backlink_id']);
                            $link->setCustomField('connect_status', 'confirmed');
                            $link->setCustomField('connect_last_attempt', utc_now_string('Y-m-d H:i:s'));
                            $link->setCustomField('connect_result', [
                                'success' => true,
                                'backlink_id' => $responseData['backlink_id'],
                                'response_time' => $responseTime,
                            ]);
                            $link->save();
                        }
                    }

                    // 更新任务状态为成功
                    LinkConnectQueueService::updateTaskStatus($taskId, 'success', '友链申请已发送且已确认回链', [
                        'link_id' => $linkId,
                        'peer_backlink_id' => $responseData['backlink_id'],
                        'response_time' => $responseTime,
                    ]);

                    $this->clearFailureStats($peerApi);
                    $message->ack();
                } else {
                    // A站未创建回链或请求失败
                    Log::warning("友链申请成功但未确认回链 [{$taskId}] - Response: " . $result['response']);

                    // 仍然更新本地记录，但标记为未确认
                    if ($linkId) {
                        $this->updateLinkStatus($linkId, 'sent_unconfirmed', $result);
                    }

                    LinkConnectQueueService::updateTaskStatus($taskId, 'partial_success', '友链申请已发送但未确认回链', [
                        'link_id' => $linkId,
                        'response_time' => $responseTime,
                        'warning' => '对方未创建回链',
                    ]);

                    $message->ack();
                }
            } else {
                Log::warning("友链申请失败 [{$taskId}] - 原因: " . ($result['error'] ?? '未知错误'));

                // 更新任务状态为失败
                LinkConnectQueueService::updateTaskStatus($taskId, 'failed', '发送失败: ' . ($result['error'] ?? '未知错误'));

                $this->recordFailure($peerApi, $result['error'] ?? '未知错误');
                $this->handleFailedMessage($message, $peerApi);
            }

        } catch (Exception $e) {
            Log::error("LinkConnectWorker 处理异常 [{$taskId}]: " . $e->getMessage());
            LinkConnectQueueService::updateTaskStatus($taskId, 'failed', '处理异常: ' . $e->getMessage());
            $this->handleFailedMessage($message, $data['peer_api'] ?? 'unknown');
        }
    }

    /**
     * 发送友链互联请求
     */
    protected function sendLinkConnectRequest(string $url, array $payload): array
    {
        try {
            $token = (string) blog_config('wind_connect_token', '', true);
            $sslVerify = (bool) blog_config('wind_connect_ssl_verify', true, true);
            $timeout = (int) blog_config('link_connect_timeout', 30, true);

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

            if ($error) {
                return ['success' => false, 'error' => $error];
            }

            if ($httpCode < 200 || $httpCode >= 300) {
                return ['success' => false, 'error' => "HTTP {$httpCode}"];
            }

            return ['success' => true, 'response' => $response];

        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * CAT3* 发送 QuickConnect 请求（带 create_backlink 参数）
     * 注意：quickConnect 路由使用 GET+POST 混合方式，token 在 GET 参数中，peer_site 在 POST body 中
     */
    protected function sendQuickConnectRequest(string $peerApi, ?string $token, array $payload): array
    {
        try {
            if (empty($token)) {
                Log::error('[sendQuickConnectRequest] Token 不能为空');

                return ['success' => false, 'error' => 'Token 不能为空'];
            }

            // 从 peerApi 解析出基础 URL
            $parsedUrl = parse_url($peerApi);
            $scheme = $parsedUrl['scheme'] ?? 'http';
            $host = $parsedUrl['host'] ?? '';
            $port = isset($parsedUrl['port']) ? ':' . $parsedUrl['port'] : '';

            // 构建 quickConnect URL，token 在 URL 中
            $quickConnectUrl = $scheme . '://' . $host . $port . '/link/quick-connect?token=' . urlencode($token);

            $sslVerify = (bool) blog_config('wind_connect_ssl_verify', true, true);
            $timeout = (int) blog_config('link_connect_timeout', 30, true);

            // 准备 POST 数据（使用 multipart/form-data 格式，支持嵌套数组）
            $postFields = [];
            foreach ($payload as $key => $value) {
                if (is_array($value)) {
                    // peer_site 是嵌套数组，需要特殊处理
                    foreach ($value as $subKey => $subValue) {
                        $postFields["{$key}[{$subKey}]"] = $subValue;
                    }
                } else {
                    $postFields[$key] = $value;
                }
            }

            $postData = http_build_query($postFields);

            // 输出详细的请求日志
            Log::info('[sendQuickConnectRequest] 准备发送请求', [
                'url' => $quickConnectUrl,
                'method' => 'POST',
                'payload_raw' => $payload,
                'post_fields' => $postFields,
                'post_data' => $postData,
                'content_type' => 'application/x-www-form-urlencoded',
                'ssl_verify' => $sslVerify,
                'timeout' => $timeout,
            ]);

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $quickConnectUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $postData,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/x-www-form-urlencoded',
                ],
                CURLOPT_SSL_VERIFYPEER => $sslVerify,
                CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $error = curl_error($ch);
            curl_close($ch);

            // 输出详细的响应日志
            Log::info('[sendQuickConnectRequest] 收到响应', [
                'http_code' => $httpCode,
                'effective_url' => $effectiveUrl,
                'curl_error' => $error ?: 'none',
                'response_length' => strlen($response),
                'response_preview' => substr($response, 0, 500),
            ]);

            if ($error) {
                Log::error('[sendQuickConnectRequest] CURL 错误: ' . $error);

                return ['success' => false, 'error' => $error];
            }

            if ($httpCode < 200 || $httpCode >= 300) {
                Log::error('[sendQuickConnectRequest] HTTP 错误', [
                    'http_code' => $httpCode,
                    'response' => $response,
                ]);

                return ['success' => false, 'error' => "HTTP {$httpCode}", 'response' => $response];
            }

            Log::info('[sendQuickConnectRequest] 请求成功');

            return ['success' => true, 'response' => $response];

        } catch (Throwable $e) {
            Log::error('[sendQuickConnectRequest] 异常: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 更新Link记录状态
     */
    protected function updateLinkStatus(int $linkId, string $status, array $result): void
    {
        try {
            $link = Link::find($linkId);
            if ($link) {
                $link->setCustomField('connect_status', $status);
                $link->setCustomField('connect_last_attempt', utc_now_string('Y-m-d H:i:s'));
                $link->setCustomField('connect_result', $result);
                $link->save();
            }
        } catch (Throwable $e) {
            Log::warning('更新Link状态失败: ' . $e->getMessage());
        }
    }

    /**
     * 记录失败统计
     */
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
    }

    /**
     * 清除失败统计
     */
    protected function clearFailureStats(string $url): void
    {
        $key = md5($url);
        unset($this->failureStats[$key]);
    }

    /**
     * 判断是否应该进入死信队列
     */
    protected function shouldSendToDeadLetter(string $url): bool
    {
        $key = md5($url);
        if (!isset($this->failureStats[$key])) {
            return false;
        }

        $stats = $this->failureStats[$key];

        // 累计失败5次进入DLX
        return $stats['count'] >= 5;
    }

    /**
     * 处理失败的消息
     */
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
                Log::error('LinkConnectWorker URL超限，直接DLX: ' . $url);

                return;
            }

            if ($retry < $this->maxRetries) {
                $newHeaders = $headers ? clone $headers : new AMQPTable();
                $newHeaders->set('x-retry-count', $retry + 1);
                $message->set('application_headers', $newHeaders);
                $message->nack(true); // 重新入队
                Log::warning('LinkConnectWorker 消息重试: ' . ($retry + 1) . "/{$this->maxRetries}");
            } else {
                $message->nack(false);
                Log::error("LinkConnectWorker 重试超限({$this->maxRetries})，进入DLX");
            }
        } catch (Exception $e) {
            $message->nack(false);
            Log::error('LinkConnectWorker 处理失败消息异常: ' . $e->getMessage());
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
            Log::info('RabbitMQ连接已关闭(LinkConnectWorker)');
        } catch (Exception $e) {
            Log::error('关闭MQ连接异常(LinkConnectWorker): ' . $e->getMessage());
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
