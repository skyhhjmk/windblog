<?php
declare(strict_types=1);

namespace app\process;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use support\Log;

class MailWorker
{
    /** @var AMQPStreamConnection|null */
    protected ?AMQPStreamConnection $mqConnection = null;
    /** @var \PhpAmqpLib\Channel\AMQPChannel|null */
    protected $mqChannel = null;

    protected string $exchange = 'mail_exchange';
    protected string $routingKey = 'mail_send';
    protected string $queueName = 'mail_queue';
    protected string $dlxExchange = 'mail_dlx_exchange';
    protected string $mailDlxQueue = 'mail_dlx_queue';

    protected function getMqConnection(): AMQPStreamConnection
    {
        if ($this->mqConnection === null) {
            $this->mqConnection = new AMQPStreamConnection(
                (string)blog_config('rabbitmq_host', '127.0.0.1', true),
                (int)blog_config('rabbitmq_port', 5672, true),
                (string)blog_config('rabbitmq_user', 'guest', true),
                (string)blog_config('rabbitmq_password', 'guest', true),
                (string)blog_config('rabbitmq_vhost', '/', true),
            );
        }
        return $this->mqConnection;
    }

    protected function getMqChannel(): \PhpAmqpLib\Channel\AMQPChannel
    {
        if ($this->mqChannel === null) {
            $this->ensureMailDefaults();
            // 使用 MQService 通道
            $this->mqChannel = \app\service\MQService::getChannel();

            // 初始化命名（从配置覆盖）
            $this->exchange     = (string)blog_config('rabbitmq_mail_exchange', $this->exchange, true) ?: $this->exchange;
            $this->routingKey   = (string)blog_config('rabbitmq_mail_routing_key', $this->routingKey, true) ?: $this->routingKey;
            $this->queueName    = (string)blog_config('rabbitmq_mail_queue', $this->queueName, true) ?: $this->queueName;
            $this->dlxExchange  = (string)blog_config('rabbitmq_mail_dlx_exchange', $this->dlxExchange, true) ?: $this->dlxExchange;
            $this->mailDlxQueue = (string)blog_config('rabbitmq_mail_dlx_queue', $this->mailDlxQueue, true) ?: $this->mailDlxQueue;

            // 使用 MQService 的通用初始化（专属 DLX/DLQ）
            \app\service\MQService::declareDlx($this->mqChannel, $this->dlxExchange, $this->mailDlxQueue);
            \app\service\MQService::setupQueueWithDlx($this->mqChannel, $this->exchange, $this->routingKey, $this->queueName, $this->dlxExchange, $this->mailDlxQueue);
        }
        return $this->mqChannel;
    }

    public function onWorkerStart(): void
    {
        $channel = $this->getMqChannel();

        $channel->basic_consume(
            $this->queueName,
            '',
            false, // no_local
            false, // no_ack
            false, // exclusive
            false, // nowait
            function (AMQPMessage $message) {
                $this->handleMessage($message);
            }
        );

        while (true) {
            try {
                $channel->wait(null, false, 1.0);
            } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
                // 正常超时，无消息到达
            } catch (\Throwable $e) {
                Log::error('MailWorker wait error: ' . $e->getMessage());
            }
        }
    }

    protected function handleMessage(AMQPMessage $message): void
    {
        try {
            $raw = $message->getBody();
            if ($raw === '' || $raw === null) {
                throw new \RuntimeException('Message body empty');
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                throw new \RuntimeException('Message body invalid JSON');
            }
            $this->sendMail($data);
            $message->ack();
        } catch (\Throwable $e) {
            Log::error('Send mail failed: ' . $e->getMessage());
            $this->handleFailedMessage($message);
        }
    }

    protected function handleFailedMessage(AMQPMessage $message): void
    {
        $headers = $message->has('application_headers') ? $message->get('application_headers') : null;
        $retry = 0;
        if ($headers instanceof AMQPTable) {
            $native = method_exists($headers, 'getNativeData') ? $headers->getNativeData() : (array)$headers;
            $retry = (int)($native['x-retry-count'] ?? 0);
        }

        if ($retry < 2) { // 第1、2次失败重试；第3次进入死信
            $newHeaders = $headers ? clone $headers : new AMQPTable();
            $newHeaders->set('x-retry-count', $retry + 1);

            $newMsg = new AMQPMessage($message->getBody(), [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'application_headers' => $newHeaders
            ]);
            $this->getMqChannel()->basic_publish($newMsg, $this->exchange, $this->routingKey);
            $message->ack();
            Log::warning('Mail message requeued, retry=' . ($retry + 1));
        } else {
            // 投递到专属 Mail DLQ
            $dlqMsg = new AMQPMessage($message->getBody(), [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            ]);
            $this->getMqChannel()->basic_publish($dlqMsg, $this->dlxExchange, $this->mailDlxQueue);
            $message->ack();
            Log::error('Mail message moved to DLQ after 3 attempts');
        }
    }

    protected function sendMail(array $data): void
    {
        $mailer = new PHPMailer(true);
        try {
            $transport = (string)blog_config('mail_transport', 'smtp', true);
            $host = (string)blog_config('mail_host', '', true);
            $port = (int)blog_config('mail_port', 587, true);
            $username = (string)blog_config('mail_username', '', true);
            $password = (string)blog_config('mail_password', '', true);
            $encryption = (string)blog_config('mail_encryption', 'tls', true); // tls|ssl|''

            $fromAddress = (string)blog_config('mail_from_address', 'no-reply@example.com', true);
            $fromName = (string)blog_config('mail_from_name', 'WindBlog', true);
            $replyTo = (string)blog_config('mail_reply_to', '', true);

            if (strtolower($transport) === 'smtp') {
                $mailer->isSMTP();
                $mailer->Host = $host;
                $mailer->SMTPAuth = true;
                $mailer->Username = $username;
                $mailer->Password = $password;
                $mailer->Port = $port;
                if ($encryption) {
                    $mailer->SMTPSecure = $encryption; // 'tls' or 'ssl'
                }
            } else {
                // 其他传输方式回退到 mail()
                $mailer->isMail();
            }

            $mailer->CharSet = 'UTF-8';
            $mailer->setFrom($fromAddress, $fromName);
            if ($replyTo !== '') {
                $mailer->addReplyTo($replyTo, $fromName);
            }

            $to = $data['to'] ?? null;
            if (is_string($to) && $to !== '') {
                $mailer->addAddress($to);
            } elseif (is_array($to)) {
                foreach ($to as $addr) {
                    if (is_string($addr) && $addr !== '') {
                        $mailer->addAddress($addr);
                    } elseif (is_array($addr) && !empty($addr['email'])) {
                        $mailer->addAddress((string)$addr['email'], (string)($addr['name'] ?? ''));
                    }
                }
            }

            if (!empty($data['headers']) && is_array($data['headers'])) {
                foreach ($data['headers'] as $k => $v) {
                    $mailer->addCustomHeader((string)$k, (string)$v);
                }
            }

            if (!empty($data['attachments']) && is_array($data['attachments'])) {
                foreach ($data['attachments'] as $att) {
                    $path = $att['path'] ?? null;
                    if ($path) {
                        $mailer->addAttachment(
                            (string)$path,
                            isset($att['name']) ? (string)$att['name'] : '',
                            isset($att['encoding']) ? (string)$att['encoding'] : PHPMailer::ENCODING_BASE64,
                            isset($att['type']) ? (string)$att['type'] : ''
                        );
                    }
                }
            }

            // 可选 CC/BCC
            if (!empty($data['cc']) && is_array($data['cc'])) {
                foreach ($data['cc'] as $cc) {
                    if (is_string($cc) && $cc !== '') {
                        $mailer->addCC($cc);
                    }
                }
            }
            if (!empty($data['bcc']) && is_array($data['bcc'])) {
                foreach ($data['bcc'] as $bcc) {
                    if (is_string($bcc) && $bcc !== '') {
                        $mailer->addBCC($bcc);
                    }
                }
            }

            $subject = (string)($data['subject'] ?? '');
            $html = (string)($data['html'] ?? '');
            $text = (string)($data['text'] ?? '');

            $mailer->Subject = $subject;
            if ($html !== '') {
                $mailer->isHTML(true);
                $mailer->Body = $html;
                if ($text !== '') {
                    $mailer->AltBody = $text;
                }
            } else {
                $mailer->isHTML(false);
                $mailer->Body = $text;
            }

            $mailer->send();
        } catch (MailException $e) {
            throw $e;
        }
    }

    /**
     * 初始化 mail_* 的默认配置（首次无记录时落库）
     */
    protected function ensureMailDefaults(): void
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
        } catch (\Throwable $e) {
            Log::warning('ensureMailDefaults warn: ' . $e->getMessage());
        }
    }
}