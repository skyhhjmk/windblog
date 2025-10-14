<?php

namespace app\service;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use support\Log;
use Throwable;

/**
 * MQ消息队列服务
 */
class MQService
{
    /**
     * @var AMQPStreamConnection|null
     */
    private static ?AMQPStreamConnection $connection = null;

    /**
     * @var AMQPChannel|null
     */
    private static ?AMQPChannel $channel = null;

    /**
     * 获取RabbitMQ连接
     *
     * @return AMQPStreamConnection
     * @throws Throwable
     */
    private static function getConnection(): AMQPStreamConnection
    {
        if (self::$connection === null) {
            $host   = (string)blog_config('rabbitmq_host', '127.0.0.1', true);
            $port   = (int)blog_config('rabbitmq_port', 5672, true);
            $user   = (string)blog_config('rabbitmq_user', 'guest', true);
            $pass   = (string)blog_config('rabbitmq_password', 'guest', true);
            $vhost  = (string)blog_config('rabbitmq_vhost', '/', true);

            // 超时与心跳（可根据需要在配置中添加对应键）
            $connTimeout       = 3.0;   // 连接超时秒
            $readWriteTimeout  = 3.0;   // 读写超时秒
            $heartbeat         = 60;    // 心跳秒
            $keepalive         = true;  // TCP keepalive

            $attempts = 3;
            $delayMs  = [300, 600, 1200];

            $lastError = '';
            for ($i = 0; $i < $attempts; $i++) {
                try {
                    // 使用扩展构造参数，提升连接稳定性
                    self::$connection = new AMQPStreamConnection(
                        $host,
                        $port,
                        $user,
                        $pass,
                        $vhost,
                        false,                  // insist
                        'AMQPLAIN',             // login_method
                        null,                   // locale
                        null,                   // connection_parameters writer (自动)
                        $connTimeout,
                        $readWriteTimeout,
                        null,                   // context
                        $keepalive,
                        $heartbeat
                    );
                    // 连接成功则退出重试
                    break;
                } catch (\Throwable $e) {
                    $lastError = $e->getMessage();
                    Log::error("RabbitMQ 连接失败(第 ".($i+1)." 次): " . $lastError);
                    if ($i < $attempts - 1) {
                        usleep($delayMs[$i] * 1000);
                    }
                }
            }

            if (self::$connection === null) {
                Log::error('RabbitMQ连接初始化失败: ' . $lastError);
            }
        }

        return self::$connection;
    }

    /**
     * 获取MQ通道
     *
     * @return AMQPChannel
     * @throws Throwable
     */
    public static function getChannel(): AMQPChannel
    {
        if (self::$channel === null) {
            try {
                $connection = self::getConnection();
                self::$channel = $connection->channel();
                self::$channel->basic_qos(0, 1, false);
            } catch (\Throwable $e) {
                Log::warning('MQ 获取通道失败，尝试自动重连: ' . $e->getMessage());
                // 自动重连一次
                self::closeConnection();
                $connection = self::getConnection();
                self::$channel = $connection->channel();
                self::$channel->basic_qos(0, 1, false);
            }
        } else {
            // 通道已存在，进行轻量操作以触发异常（若已断开则走重连）
            try {
                self::$channel->basic_qos(0, 1, false);
            } catch (\Throwable $e) {
                Log::warning('MQ 通道可能已断开，自动重连: ' . $e->getMessage());
                self::closeConnection();
                $connection = self::getConnection();
                self::$channel = $connection->channel();
                self::$channel->basic_qos(0, 1, false);
            }
        }
        return self::$channel;
    }

    /**
     * 声明死信交换机与队列
     *
     * @param AMQPChannel $channel
     * @param string      $dlxExchange
     * @param string      $dlxQueue
     *
     * @return void
     */
    public static function declareDlx(AMQPChannel $channel, string $dlxExchange, string $dlxQueue): void
    {
        try {
            $channel->exchange_declare($dlxExchange, 'direct', false, true, false);
        } catch (\Throwable $e) {
            Log::warning("DLX 交换机声明失败({$dlxExchange}): " . $e->getMessage());
            // 再次尝试非持久/自动删除以避免阻塞
            try { $channel->exchange_declare($dlxExchange, 'direct', false, false, false); } catch (\Throwable $e2) { Log::error("DLX 交换机重试失败({$dlxExchange}): " . $e2->getMessage()); }
        }

        try {
            $channel->queue_declare($dlxQueue, false, true, false, false);
        } catch (\Throwable $e) {
            Log::warning("DLQ 队列声明失败({$dlxQueue}): " . $e->getMessage());
            try { $channel->queue_declare($dlxQueue, false, true, false, false); } catch (\Throwable $e2) { Log::error("DLQ 队列重试失败({$dlxQueue}): " . $e2->getMessage()); }
        }

        try {
            $channel->queue_bind($dlxQueue, $dlxExchange, $dlxQueue);
        } catch (\Throwable $e) {
            Log::warning("DLQ 绑定失败(queue={$dlxQueue}, exchange={$dlxExchange}): " . $e->getMessage());
        }
    }

    /**
     * 声明主交换机与队列并绑定
     *
     * @param AMQPChannel $channel
     * @param string      $exchange
     * @param string      $routingKey
     * @param string      $queueName
     * @param string      $dlxExchange
     * @param string      $dlxQueue
     *
     * @return void
     */
    public static function setupQueueWithDlx(
        AMQPChannel $channel,
        string      $exchange,
        string      $routingKey,
        string      $queueName,
        string      $dlxExchange,
        string      $dlxQueue
    ): void
    {
        try {
            $channel->exchange_declare($exchange, 'direct', false, true, false);
        } catch (\Throwable $e) {
            \support\Log::warning("主交换机声明失败({$exchange}): " . $e->getMessage());
            try { $channel->exchange_declare($exchange, 'direct', false, false, false); } catch (\Throwable $e2) { \support\Log::error("主交换机重试失败({$exchange}): " . $e2->getMessage()); }
        }
        $args = [
            'x-dead-letter-exchange' => ['S', $dlxExchange],
            'x-dead-letter-routing-key' => ['S', $dlxQueue],
            'x-max-priority' => ['I', 10], // 开启队列优先级（0-9）
        ];
        try {
            $channel->queue_declare($queueName, false, true, false, false, false, $args);
        } catch (\Throwable $e) {
            \support\Log::warning("队列声明失败，尝试无参重建({$queueName}): " . $e->getMessage());
            // 如果是因为参数不匹配导致的错误，则删除队列后重新声明
            if (strpos($e->getMessage(), 'inequivalent arg') !== false) {
                try {
                    // 删除已存在的队列
                    $channel->queue_delete($queueName);
                    // 重新声明队列
                    $channel->queue_declare($queueName, false, true, false, false, false, $args);
                } catch (\Throwable $e2) {
                    \support\Log::error("队列重建失败({$queueName}): " . $e2->getMessage());
                    throw $e2;
                }
            } else {
                // 其他错误则尝试无参声明
                try {
                    $channel->queue_declare($queueName, false, true, false, false, false);
                } catch (\Throwable $e3) {
                    \support\Log::error("队列无参重建失败({$queueName}): " . $e3->getMessage());
                    throw $e3;
                }
            }
        }
        $channel->queue_bind($queueName, $exchange, $routingKey);
    }

    /**
     * 发送消息到友链监控队列
     *
     * @param array $data
     *
     * @return bool
     */


    /**
     * 发送消息到HTTP回调队列
     *
     * @param array $data 回调数据
     *
     * @return bool
     */


    /**
     * 健康检查与自愈：尝试轻量操作检测连接/通道状态，失败则自动重建
     */
    public static function checkAndHeal(): bool
    {
        try {
            // 轻量健康检查：打开临时通道并关闭
            $conn = self::getConnection();
            $tmp = $conn->channel();
            $tmp->close();
            return true;
        } catch (\Throwable $e) {
            Log::warning('MQ 健康检查失败，执行自愈: ' . $e->getMessage());
            // 执行自愈：关闭现有连接并重建
            try {
                self::closeConnection();
            } catch (\Throwable $ce) {
                Log::warning('MQ 关闭旧连接失败（忽略）: ' . $ce->getMessage());
            }
            try {
                // 重新建立连接与主通道
                $conn = self::getConnection();
                self::$channel = $conn->channel();
                self::$channel->basic_qos(0, 1, false);
                return true;
            } catch (\Throwable $re) {
                Log::error('MQ 自愈重建失败: ' . $re->getMessage());
                return false;
            }
        }
    }

    /**
     * 主动重连（供外部在需要时调用）
     */
    public static function reconnect(): void
    {
        self::closeConnection();
        // 连接与通道将由 getConnection/getChannel 懒加载重建
    }

    /**
     * 关闭连接
     */
    public static function closeConnection(): void
    {
        try {
            if (self::$channel !== null) {
                self::$channel->close();
                self::$channel = null;
            }

            if (self::$connection !== null) {
                self::$connection->close();
                self::$connection = null;
            }
        } catch (Throwable $e) {
            Log::error('Error closing MQ connection: ' . $e->getMessage());
        }
    }

    /**
     * 析构函数，确保连接被关闭
     */
    public function __destruct()
    {
        self::closeConnection();
    }
}