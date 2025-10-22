<?php

declare(strict_types=1);

namespace app\service;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use support\Log;
use Throwable;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class MailService
{
    /** @var AMQPStreamConnection|null */
    private static ?AMQPStreamConnection $connection = null;

    /** @var AMQPChannel|null */
    private static $channel = null;

    /** @var bool */
    private static bool $initialized = false;

    /**
     * 渲染：基于 view 模板名（走 TwigTemplateService 与多主题逻辑）
     */
    public static function renderView(string $template, array $vars = [], ?string $app = null, ?string $plugin = null): string
    {
        return TwigTemplateService::render($template, $vars, $app, $plugin);
    }

    /**
     * 渲染：基于 inline Twig 模板字符串
     */
    public static function renderInline(string $templateString, array $vars = []): string
    {
        $options = config('view.options', []);
        // inline 模板默认不落地缓存
        $options['cache'] = false;

        $env = new Environment(new ArrayLoader(['__inline__' => $templateString]), $options);
        $extension = config('view.extension');
        if ($extension) {
            $extension($env);
        }

        return $env->render('__inline__', $vars);
    }

    /**
     * 入队：构建并发送邮件任务到 MQ
     * 支持 payload:
     * - to: string|array 收件人或收件人数组
     * - subject: string
     * - html: string 已渲染 HTML（可选）
     * - text: string 纯文本（可选）
     * - headers: array 额外头（可选）
     * - attachments: array [['path' => '/a/b.txt','name'=>'b.txt','encoding'=>null,'type'=>null], ...]
     * - view: string Twig 视图名（如 'emails/welcome'，自动追加后缀）
     * - view_vars: array 视图变量
     * - inline_template: string Twig 语法的内联模板
     * - inline_vars: array 内联模板变量
     */
    public static function enqueue(array $payload): bool
    {
        try {
            self::initializeQueues();
            $channel = self::getChannel();

            // 如果未提供 html，但提供了 view 或 inline_template，则进行渲染
            if (empty($payload['html'])) {
                if (!empty($payload['view'])) {
                    $vars = (array) ($payload['view_vars'] ?? []);
                    $payload['html'] = self::renderView((string) $payload['view'], $vars);
                } elseif (!empty($payload['inline_template'])) {
                    $vars = (array) ($payload['inline_vars'] ?? []);
                    $payload['html'] = self::renderInline((string) $payload['inline_template'], $vars);
                }
            }

            $exchange = (string) blog_config('rabbitmq_mail_exchange', 'mail_exchange', true);
            $routingKey = (string) blog_config('rabbitmq_mail_routing_key', 'mail_send', true);

            // 计算优先级（0-9），支持字符串映射 high/normal/low
            $priority = 0;
            if (isset($payload['priority'])) {
                $p = $payload['priority'];
                if (is_string($p)) {
                    $map = [
                        'high' => 9,
                        'normal' => 5,
                        'low' => 1,
                    ];
                    $priority = $map[strtolower($p)] ?? 0;
                } elseif (is_numeric($p)) {
                    $priority = max(0, min(9, (int) $p));
                }
            }
            // 过滤器：允许插件调整邮件payload（需权限 mail:filter.payload）
            $payload = PluginService::apply_filters('mail.payload_filter', $payload);
            // 动作：入队前（需权限 mail:action.before_enqueue）
            PluginService::do_action('mail.before_enqueue', $payload);

            $msg = new AMQPMessage(json_encode($payload, JSON_UNESCAPED_UNICODE), [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'priority' => $priority,
            ]);
            $channel->basic_publish($msg, $exchange, $routingKey);

            // 动作：入队后（需权限 mail:action.after_enqueue）
            PluginService::do_action('mail.after_enqueue', [
                'payload' => $payload,
                'priority' => $priority,
                'exchange' => $exchange,
                'routingKey' => $routingKey,
            ]);

            Log::debug('Mail enqueued: ' . json_encode([
                    'to' => $payload['to'] ?? null,
                    'subject' => $payload['subject'] ?? null,
                    'priority' => $priority,
                ], JSON_UNESCAPED_UNICODE));

            return true;
        } catch (Throwable $e) {
            Log::error('Enqueue mail failed: ' . $e->getMessage());

            return false;
        }
    }

    private static function getConnection(): AMQPStreamConnection
    {
        if (!self::$connection) {
            self::$connection = new AMQPStreamConnection(
                (string) blog_config('rabbitmq_host', '127.0.0.1', true),
                (int) blog_config('rabbitmq_port', 5672, true),
                (string) blog_config('rabbitmq_user', 'guest', true),
                (string) blog_config('rabbitmq_password', 'guest', true),
                (string) blog_config('rabbitmq_vhost', '/', true),
            );
        }

        return self::$connection;
    }

    private static function getChannel(): AMQPChannel
    {
        if (!self::$channel) {
            self::$channel = self::getConnection()->channel();
            self::$channel->basic_qos(0, 1, false);
        }

        return self::$channel;
    }

    private static function initializeQueues(): void
    {
        if (self::$initialized) {
            return;
        }
        try {
            self::ensureMailDefaults();
            $ch = self::getChannel();

            $exchange = (string) blog_config('rabbitmq_mail_exchange', 'mail_exchange', true);
            $routingKey = (string) blog_config('rabbitmq_mail_routing_key', 'mail_send', true);
            $queueName = (string) blog_config('rabbitmq_mail_queue', 'mail_queue', true);

            $dlxExchange = (string) blog_config('rabbitmq_mail_dlx_exchange', 'mail_dlx_exchange', true);
            $mailDlxQueue = (string) blog_config('rabbitmq_mail_dlx_queue', 'mail_dlx_queue', true);

            // 声明 DLX 及 mail 专属 DLQ（使用 mail_dlx_exchange，routing key 绑定到 mail_dlx_queue）
            $ch->exchange_declare($dlxExchange, 'direct', false, true, false);
            $ch->queue_declare($mailDlxQueue, false, true, false, false);
            $ch->queue_bind($mailDlxQueue, $dlxExchange, $mailDlxQueue);

            // 主交换机 & 队列（带死信参数）
            $ch->exchange_declare($exchange, 'direct', false, true, false);
            $args = new AMQPTable([
                'x-dead-letter-exchange' => $dlxExchange,
                'x-dead-letter-routing-key' => $mailDlxQueue,
                'x-max-priority' => 10, // 开启队列优先级（0-9）
            ]);
            try {
                $ch->queue_declare($queueName, false, true, false, false, false, $args);
            } catch (Throwable $e) {
                Log::warning('mail_queue 声明失败，尝试无参重建: ' . $e->getMessage());
                $ch->queue_declare($queueName, false, true, false, false, false);
            }
            $ch->queue_bind($queueName, $exchange, $routingKey);

            self::$initialized = true;
            Log::debug('Mail queues initialized');
        } catch (Throwable $e) {
            Log::error('Initialize mail queues failed: ' . $e->getMessage());
        }
    }

    public static function close(): void
    {
        try {
            if (self::$channel) {
                self::$channel->close();
                self::$channel = null;
            }
            if (self::$connection) {
                self::$connection->close();
                self::$connection = null;
            }
        } catch (Throwable $e) {
            Log::warning('Close mail MQ connection failed: ' . $e->getMessage());
        }
    }

    public function __destruct()
    {
        self::close();
    }

    /**
     * 初始化 mail_* 的默认配置（首次无记录时落库）
     */
    protected static function ensureMailDefaults(): void
    {
        try {
            blog_config('mail_transport', 'smtp', true);
            blog_config('mail_host', '', true);
            blog_config('mail_port', 587, true);
            blog_config('mail_username', '', true);
            blog_config('mail_password', '', true);
            blog_config('mail_encryption', 'tls', true);
            blog_config('mail_from_address', 'no-reply@example.com', true);
            blog_config('mail_from_name', 'WindBlog', true);
            blog_config('mail_reply_to', '', true);
        } catch (Throwable $e) {
            Log::warning('ensureMailDefaults warn: ' . $e->getMessage());
        }
    }
}
