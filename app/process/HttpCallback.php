<?php

namespace app\process;

use app\service\MQService;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use support\Log;
use Workerman\Timer;
use Workerman\Worker;

/**
 * HTTP回调多进程处理
 */
class HttpCallback
{
    /**
     * 检查间隔（秒）
     *
     * @var int
     */
    protected int $interval = 5;

    /**
     * 定时器ID
     *
     * @var int
     */
    protected int $timerId;
    protected int $timerId2;

    /**
     * 最大响应大小（字节）
     *
     * @var int
     */
    protected int $maxResponseSize = 10485760; // 10MB

    /**
     * 请求超时时间（秒）
     *
     * @var int
     */
    protected int $requestTimeout = 30;

    /**
     * 是否验证SSL证书
     *
     * @var bool
     */
    protected bool $verifySsl = false;

    /**
     * CA证书路径
     *
     * @var string|null
     */
    protected ?string $caCertPath = null;

    /**
     * 失败统计（用于自动死信队列处理）
     *
     * @var array
     */
    protected array $failureStats = [];

    /**
     * 构造函数
     *
     * @param mixed $config 配置参数（可能是数组或false）
     */
    public function __construct($config = [])
    {
        // 处理配置参数类型，确保是数组
        $configArray = is_array($config) ? $config : [];
        
        // 从配置中读取SSL验证设置
        $this->verifySsl = $configArray['verify_ssl'] ?? false;
        $this->caCertPath = $configArray['ca_cert_path'] ?? null;
        
        // 如果启用了SSL验证但没有提供CA证书路径，尝试使用系统默认证书
        if ($this->verifySsl && empty($this->caCertPath)) {
            $this->caCertPath = $this->getDefaultCaCertPath();
        }
    }

    /**
     * 获取系统默认CA证书路径
     *
     * @return string|null
     */
    protected function getDefaultCaCertPath(): ?string
    {
        // 常见CA证书路径
        $commonPaths = [
            '/etc/ssl/certs/ca-certificates.crt',
            '/etc/pki/tls/certs/ca-bundle.crt',
            '/usr/local/ssl/certs/ca-bundle.crt',
            'C:\\Windows\\System32\\curl-ca-bundle.crt',
            'C:\\Windows\\curl-ca-bundle.crt',
        ];
        
        foreach ($commonPaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }
        
        return null;
    }

    /**
     * RabbitMQ连接
     *
     * @var AMQPStreamConnection|null
     */
    protected ?AMQPStreamConnection $mqConnection = null;

    /**
     * RabbitMQ通道
     *
     * @var \PhpAmqpLib\Channel\AMQPChannel|null
     */
    protected $mqChannel = null;

    /**
     * 获取RabbitMQ连接
     *
     * @return AMQPStreamConnection
     */
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
     * 获取RabbitMQ通道
     *
     * @return \PhpAmqpLib\Channel\AMQPChannel
     */
    protected function getMqChannel()
    {
        if ($this->mqChannel === null) {
            // 使用 MQService 提供的通道
            $this->mqChannel = MQService::getChannel();
        }
        return $this->mqChannel;
    }

    /**
     * 进程启动时执行
     *
     * @param Worker $worker
     * @return void
     */
    public function onWorkerStart(Worker $worker): void
    {
        // 在启动前检查 .env 是否存在
        $envPath = base_path() . '/.env';
        if (!file_exists($envPath)) {
            Log::warning("HttpCallback 检测到缺少 .env，已跳过启动：{$envPath}");
            return;
        }

        Log::info("HTTP回调进程已启动 - PID: " . getmypid());

        // 初始化MQ连接与本进程专属交换机/队列/死信
        try {
            $channel = MQService::getChannel();

            $exchange   = (string)blog_config('rabbitmq_http_callback_exchange', 'http_callback_exchange', true);
            $routingKey = (string)blog_config('rabbitmq_http_callback_routing_key', 'http_callback', true);
            $queueName  = (string)blog_config('rabbitmq_http_callback_queue', 'http_callback_queue', true);

            // 使用 HttpCallback 专属 DLX/DLQ（不共用）
            $dlxExchange = (string)blog_config('rabbitmq_http_dlx_exchange', 'http_dlx_exchange', true);
            $dlxQueue    = (string)blog_config('rabbitmq_http_dlx_queue', 'http_dlx_queue', true);

            MQService::declareDlx($channel, $dlxExchange, $dlxQueue);
            MQService::setupQueueWithDlx($channel, $exchange, $routingKey, $queueName, $dlxExchange, $dlxQueue);

            $this->mqChannel = $channel;
            Log::info("RabbitMQ连接初始化成功(HttpCallback)");
        } catch (\Exception $e) {
            Log::error("RabbitMQ连接初始化失败(HttpCallback): " . $e->getMessage());
        }

        // 监控进程状态
        $this->timerId = Timer::add(60, function() {
            $memoryUsage = memory_get_usage(true) / 1024 / 1024;
            $memoryPeak = memory_get_peak_usage(true) / 1024 / 1024;
            Log::debug("HTTP回调进程状态 - 内存使用: {$memoryUsage}MB, 峰值内存: {$memoryPeak}MB");
        });

        // 立即开始处理消息，然后定时检查
        $this->processMessages();
        
        // 定时处理消息
        $this->timerId2 = Timer::add($this->interval, [$this, 'processMessages']);
    }

    /**
     * 处理消息队列中的回调请求
     *
     * @return void
     */
    public function processMessages(): void
    {
        try {
            // 获取队列配置
            $queueName = blog_config('rabbitmq_http_callback_queue', 'http_callback_queue', true);
            
            // 订阅消息队列
            $channel = $this->getMqChannel();
            
            // 设置消息确认模式为手动确认
            $channel->basic_consume(
                $queueName,
                '', // consumer tag
                false, // no_local
                false, // no_ack (设置为false，需要手动确认)
                false, // exclusive
                false, // nowait
                [$this, 'handleCallbackMessage']
            );

            // 阻塞模式处理消息，确保每条消息都完整处理
            while ($channel->is_consuming()) {
                try {
                    $channel->wait(null, false, 1.0);
                } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
                    // 正常超时，无消息到达，忽略
                } catch (\Throwable $e) {
                    Log::warning('HttpCallback 消费轮询异常: ' . $e->getMessage());
                    break;
                }
            }

        } catch (\Exception $e) {
            Log::error('处理HTTP回调消息时出错: ' . $e->getMessage());
        }
    }

    /**
     * 处理回调消息
     *
     * @param AMQPMessage $message
     * @return void
     */
    public function handleCallbackMessage(AMQPMessage $message): void
    {
        $url = 'unknown';
        
        try {
            $data = json_decode($message->body, true);
            
            if (!$data) {
                Log::warning('无效的回调消息格式: ' . $message->body);
                $message->ack();
                return;
            }

            // 支持多种消息格式
            $headers = [];
            if (isset($data['callback_url'])) {
                // 格式1: 包含callback_url字段
                $url = $data['callback_url'];
                $params = $data;
            } elseif (isset($data['url'])) {
                // 格式2: 包含url字段
                $url = $data['url'];
                $params = $data['params'] ?? [];
                $headers = $data['headers'] ?? [];
            } else {
                Log::warning('无效的回调消息格式，缺少url或callback_url字段: ' . $message->body);
                $message->ack();
                return;
            }

            Log::info('开始处理HTTP回调: ' . $url);

            // 检查失败统计
            if ($this->shouldSendToDeadLetter($url)) {
                Log::error('URL连续失败次数超过限制，直接进入死信队列: ' . $url);
                $message->nack(false); // 不重新入队，进入死信队列
                return;
            }

            // 执行GET请求
            $result = $this->executeGetRequest($url, $params, $headers);

            if ($result['success']) {
                Log::info('HTTP回调执行成功: ' . $url . ', 响应大小: ' . strlen($result['response']));
                // 清除失败统计
                $this->clearFailureStats($url);
                $message->ack();
            } else {
                Log::error('HTTP回调执行失败: ' . $url . ', 错误: ' . $result['error']);
                // 记录失败统计
                $this->recordFailure($url, $result['error']);
                $this->handleFailedMessage($message, $url);
            }

        } catch (\Exception $e) {
            Log::error('处理HTTP回调消息时发生异常: ' . $e->getMessage());
            // 记录失败统计
            $this->recordFailure($url, $e->getMessage());
            $this->handleFailedMessage($message, $url);
        }
    }

    /**
     * 执行GET请求（带安全防护）
     *
     * @param string $url
     * @param array $params
     * @param array $headers
     * @return array
     */
    protected function executeGetRequest(string $url, array $params = [], array $headers = []): array
    {
        try {
            // 构建查询字符串
            $queryString = http_build_query($params);
            $fullUrl = $url . ($queryString ? '?' . $queryString : '');

            // 验证URL格式
            if (!filter_var($fullUrl, FILTER_VALIDATE_URL)) {
                return ['success' => false, 'error' => '无效的URL格式'];
            }

            // 初始化cURL
            $ch = curl_init();
            
            // 设置cURL选项
            $curlOptions = [
                CURLOPT_URL => $fullUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->requestTimeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
                CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
                CURLOPT_USERAGENT => 'WindBlog HTTP Callback/1.0',
                CURLOPT_HEADER => false,
                CURLOPT_WRITEFUNCTION => [$this, 'writeCallback'], // 流式处理响应
                CURLOPT_NOPROGRESS => false,
                CURLOPT_PROGRESSFUNCTION => [$this, 'progressCallback'] // 进度回调
            ];

            // 设置CA证书路径（如果提供）
            if ($this->verifySsl && $this->caCertPath && file_exists($this->caCertPath)) {
                $curlOptions[CURLOPT_CAINFO] = $this->caCertPath;
            }

            curl_setopt_array($ch, $curlOptions);

            // 设置请求头
            if (!empty($headers)) {
                $headerArray = [];
                foreach ($headers as $key => $value) {
                    $headerArray[] = $key . ': ' . $value;
                }
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headerArray);
            }

            // 执行请求
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            
            curl_close($ch);

            if ($error) {
                return ['success' => false, 'error' => $error];
            }

            if ($httpCode >= 400) {
                return ['success' => false, 'error' => 'HTTP错误: ' . $httpCode];
            }

            return [
                'success' => true,
                'response' => $response,
                'http_code' => $httpCode
            ];

        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 写入回调函数（防止特大包）
     *
     * @param resource $ch
     * @param string $data
     * @return int
     */
    public function writeCallback($ch, string $data): int
    {
        static $receivedSize = 0;
        
        $dataSize = strlen($data);
        $receivedSize += $dataSize;

        // 如果超过最大响应大小，中止请求
        if ($receivedSize > $this->maxResponseSize) {
            Log::warning("响应大小超过限制: {$receivedSize} > {$this->maxResponseSize}");
            return -1; // 返回-1会中止传输并报错
        }

        return $dataSize;
    }

    /**
     * 进度回调函数（监控传输进度）
     *
     * @param resource $ch
     * @param int $downloadSize
     * @param int $downloaded
     * @param int $uploadSize
     * @param int $uploaded
     * @return int
     */
    public function progressCallback($ch, int $downloadSize, int $downloaded, int $uploadSize, int $uploaded): int
    {
        // 监控下载进度，防止超大响应
        if ($downloadSize > 0 && $downloaded > $this->maxResponseSize) {
            Log::warning("下载进度超过限制: {$downloaded} > {$this->maxResponseSize}");
            return 1; // 返回1会中止传输
        }
        
        return 0;
    }

    /**
     * 记录失败统计
     *
     * @param string $url
     * @param string $error
     * @return void
     */
    protected function recordFailure(string $url, string $error): void
    {
        $urlKey = md5($url);
        
        if (!isset($this->failureStats[$urlKey])) {
            $this->failureStats[$urlKey] = [
                'count' => 0,
                'last_error' => '',
                'first_failure' => time(),
                'url' => $url
            ];
        }

        $this->failureStats[$urlKey]['count']++;
        $this->failureStats[$urlKey]['last_error'] = $error;
        $this->failureStats[$urlKey]['last_failure'] = time();

        Log::warning("URL失败统计: {$url}, 失败次数: " . $this->failureStats[$urlKey]['count']);
    }

    /**
     * 清除失败统计
     *
     * @param string $url
     * @return void
     */
    protected function clearFailureStats(string $url): void
    {
        $urlKey = md5($url);
        if (isset($this->failureStats[$urlKey])) {
            unset($this->failureStats[$urlKey]);
            Log::info("清除URL失败统计: {$url}");
        }
    }

    /**
     * 检查是否应该发送到死信队列
     *
     * @param string $url
     * @return bool
     */
    protected function shouldSendToDeadLetter(string $url): bool
    {
        $urlKey = md5($url);
        
        if (!isset($this->failureStats[$urlKey])) {
            return false;
        }

        $stats = $this->failureStats[$urlKey];
        
        // 连续失败3次
        if ($stats['count'] >= 3) {
            return true;
        }

        // 1小时内失败3次
        if ($stats['count'] >= 3 && (time() - $stats['first_failure']) < 3600) {
            return true;
        }

        return false;
    }

    /**
     * 处理失败消息
     *
     * @param AMQPMessage $message
     * @param string $url
     * @return void
     */
    protected function handleFailedMessage(AMQPMessage $message, string $url): void
    {
        try {
            // 获取消息重试次数，处理application_headers可能不存在的情况
            $retryCount = 0;
            $headers = $message->has('application_headers') ? $message->get('application_headers') : null;
            
            if ($headers instanceof \PhpAmqpLib\Wire\AMQPTable) {
                $native = method_exists($headers, 'getNativeData') ? $headers->getNativeData() : (array)$headers;
                $retryCount = (int)($native['x-retry-count'] ?? 0);
            }
            
            // 检查是否应该直接进入死信队列（URL级别统计）
            if ($this->shouldSendToDeadLetter($url)) {
                $message->nack(false);
                Log::error('URL连续失败超过限制，直接进入死信队列: ' . $url);
                return;
            }

            // 消息级别重试：只有执行3次都失败才进入死信队列
            if ($retryCount < 2) { // 0,1,2 共3次尝试
                // 增加重试次数并重新入队
                $newHeaders = $headers ? clone $headers : new \PhpAmqpLib\Wire\AMQPTable();
                $newHeaders->set('x-retry-count', $retryCount + 1);
                $message->set('application_headers', $newHeaders);
                $message->nack(true); // 重新入队
                Log::warning('消息重试次数: ' . ($retryCount + 1) . "/3");
            } else {
                // 第3次失败，进入死信队列
                $message->nack(false);
                Log::error('消息重试次数超过限制（3次），进入死信队列');
            }
        } catch (\Exception $headerException) {
            // 如果头信息处理失败，直接进入死信队列
            $message->nack(false);
            Log::error('处理消息头信息失败，进入死信队列: ' . $headerException->getMessage());
        }
    }

    /**
     * 关闭MQ连接
     *
     * @return void
     */
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
            
            Log::info("RabbitMQ连接已关闭");
        } catch (\Exception $e) {
            Log::error('关闭MQ连接时出错: ' . $e->getMessage());
        }
    }

    /**
     * 进程停止时执行
     *
     * @param Worker $worker
     * @return void
     */
    public function onWorkerStop(Worker $worker): void
    {
        // 清除定时器
        if (isset($this->timerId)) {
            Timer::del($this->timerId);
        }
        if (isset($this->timerId2)) {
            Timer::del($this->timerId2);
        }

        // 关闭MQ连接
        $this->closeMqConnection();

        // 记录最终内存使用情况
        $memoryUsage = memory_get_usage(true) / 1024 / 1024;
        $memoryPeak = memory_get_peak_usage(true) / 1024 / 1024;
        Log::info("HTTP回调进程已停止 - 最终内存使用: {$memoryUsage}MB, 峰值内存: {$memoryPeak}MB");
    }
}