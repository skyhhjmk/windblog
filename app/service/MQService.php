<?php

namespace app\service;

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
    private static $connection = null;

    /**
     * @var \PhpAmqpLib\Channel\AMQPChannel|null
     */
    private static $channel = null;

    /**
     * @var bool 初始化状态
     */
    private static $initialized = false;

    /**
     * 获取RabbitMQ连接（单例模式）
     *
     * @return AMQPStreamConnection
     * @throws Throwable
     */
    private static function getConnection(): AMQPStreamConnection
    {
        if (self::$connection === null) {
            self::$connection = new AMQPStreamConnection(
                blog_config('rabbitmq_host', '127.0.0.1', true),
                blog_config('rabbitmq_port', 5672, true),
                blog_config('rabbitmq_user', 'guest', true),
                blog_config('rabbitmq_password', 'guest', true)
            );
        }

        return self::$connection;
    }

    /**
     * 获取RabbitMQ通道（单例模式）
     *
     * @return \PhpAmqpLib\Channel\AMQPChannel
     * @throws Throwable
     */
    private static function getChannel(): \PhpAmqpLib\Channel\AMQPChannel
    {
        if (self::$channel === null) {
            $connection = self::getConnection();
            self::$channel = $connection->channel();
            self::$channel->basic_qos(0, 1, false);
        }

        return self::$channel;
    }

    /**
     * 初始化MQ队列和交换机（一次性执行）
     *
     * @return void
     */
    private static function initializeQueues(): void
    {
        if (self::$initialized) {
            return;
        }

        try {
            $channel = self::getChannel();

            // 获取配置信息
            $exchange = blog_config('rabbitmq_http_callback_exchange', 'http_callback_exchange', true);
            $routingKey = blog_config('rabbitmq_http_callback_routing_key', 'http_callback', true);
            $queueName = blog_config('rabbitmq_http_callback_queue', 'http_callback_queue', true);

            // 声明死信交换机
            $dlxExchange = blog_config('rabbitmq_dlx_exchange', 'dlx_exchange', true);
            $dlxQueue = blog_config('rabbitmq_dlx_queue', 'dlx_queue', true);

            // 声明死信交换机和队列
            $channel->exchange_declare($dlxExchange, 'direct', false, true, false);
            $channel->queue_declare($dlxQueue, false, true, false, false);
            $channel->queue_bind($dlxQueue, $dlxExchange, $dlxQueue);

            // 声明主交换机和队列（带死信配置）
            $channel->exchange_declare($exchange, 'direct', false, true, false);
            
            $args = [
                'x-dead-letter-exchange' => ['S', $dlxExchange],
                'x-dead-letter-routing-key' => ['S', $dlxQueue]
            ];
            
            try {
                $channel->queue_declare($queueName, false, true, false, false, false, $args);
            } catch (\Exception $e) {
                // 如果队列已存在但参数不匹配，尝试重新声明队列（不带参数）
                Log::warning('队列声明失败，尝试重新声明队列: ' . $e->getMessage());
                $channel->queue_declare($queueName, false, true, false, false, false);
            }
            $channel->queue_bind($queueName, $exchange, $routingKey);

            self::$initialized = true;
            Log::debug('MQ queues and exchanges initialized successfully');

        } catch (Throwable $e) {
            Log::error('Failed to initialize MQ queues: ' . $e->getMessage());
        }
    }

    /**
     * 发送消息到HTTP回调队列
     *
     * @param array $data 回调数据
     * @return bool
     */
    public static function sendToHttpCallback(array $data): bool
    {
        try {
            // 确保队列已初始化
            self::initializeQueues();
            
            $channel = self::getChannel();

            // 获取配置信息
            $exchange = blog_config('rabbitmq_http_callback_exchange', 'http_callback_exchange', true);
            $routingKey = blog_config('rabbitmq_http_callback_routing_key', 'http_callback', true);

            // 创建消息
            $message = new AMQPMessage(
                json_encode($data, JSON_UNESCAPED_UNICODE),
                ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]
            );

            // 发布消息
            $channel->basic_publish($message, $exchange, $routingKey);

            Log::debug('HTTP callback message sent to MQ with DLX: ' . json_encode($data));
            return true;

        } catch (Throwable $e) {
            Log::error('Failed to send HTTP callback message to MQ: ' . $e->getMessage());
            return false;
        }
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