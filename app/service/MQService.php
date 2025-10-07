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
     * 获取MQ通道
     *
     * @return AMQPChannel
     * @throws Throwable
     */
    public static function getChannel(): AMQPChannel
    {
        if (self::$channel === null) {
            $connection = self::getConnection();
            self::$channel = $connection->channel();
            self::$channel->basic_qos(0, 1, false);
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
        $channel->exchange_declare($dlxExchange, 'direct', false, true, false);
        $channel->queue_declare($dlxQueue, false, true, false, false);
        $channel->queue_bind($dlxQueue, $dlxExchange, $dlxQueue);
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
        $channel->exchange_declare($exchange, 'direct', false, true, false);
        $args = [
            'x-dead-letter-exchange' => ['S', $dlxExchange],
            'x-dead-letter-routing-key' => ['S', $dlxQueue],
        ];
        try {
            $channel->queue_declare($queueName, false, true, false, false, false, $args);
        } catch (\Throwable $e) {
            \support\Log::warning("队列声明失败，尝试无参重建({$queueName}): " . $e->getMessage());
            $channel->queue_declare($queueName, false, true, false, false, false);
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