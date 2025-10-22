<?php

declare(strict_types=1);

namespace app\process;

use app\service\MQService;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use PHPMailer\PHPMailer\DSNConfigurator;
use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;
use support\Log;
use Throwable;
use Workerman\Timer;

class MailWorker
{
    /** @var array<string,mixed> */
    protected array $providers = [];

    protected string $failFile = '';

    protected string $strategy = 'weighted'; // weighted | rr

    protected ?string $lastError = null;

    /** @var AMQPStreamConnection|null */
    protected ?AMQPStreamConnection $mqConnection = null;

    /** @var AMQPChannel|null */
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
                (string) blog_config('rabbitmq_host', '127.0.0.1', true),
                (int) blog_config('rabbitmq_port', 5672, true),
                (string) blog_config('rabbitmq_user', 'guest', true),
                (string) blog_config('rabbitmq_password', 'guest', true),
                (string) blog_config('rabbitmq_vhost', '/', true),
            );
        }

        return $this->mqConnection;
    }

    protected function getMqChannel(): AMQPChannel
    {
        if ($this->mqChannel === null) {

            // 使用 MQService 通道
            $this->mqChannel = MQService::getChannel();

            // 初始化命名（从配置覆盖）
            $this->exchange = (string) blog_config('rabbitmq_mail_exchange', $this->exchange, true) ?: $this->exchange;
            $this->routingKey = (string) blog_config('rabbitmq_mail_routing_key', $this->routingKey, true) ?: $this->routingKey;
            $this->queueName = (string) blog_config('rabbitmq_mail_queue', $this->queueName, true) ?: $this->queueName;
            $this->dlxExchange = (string) blog_config('rabbitmq_mail_dlx_exchange', $this->dlxExchange, true) ?: $this->dlxExchange;
            $this->mailDlxQueue = (string) blog_config('rabbitmq_mail_dlx_queue', $this->mailDlxQueue, true) ?: $this->mailDlxQueue;

            // 使用 MQService 的通用初始化（专属 DLX/DLQ）
            MQService::declareDlx($this->mqChannel, $this->dlxExchange, $this->mailDlxQueue);
            MQService::setupQueueWithDlx($this->mqChannel, $this->exchange, $this->routingKey, $this->queueName, $this->dlxExchange, $this->mailDlxQueue);
        }

        return $this->mqChannel;
    }

    public function onWorkerStart(): void
    {
        $this->failFile = base_path() . DIRECTORY_SEPARATOR . '.email_failed_count';
        $this->providers = $this->loadProviders();
        $this->strategy = (string) blog_config('mail_strategy', 'weighted', true) ?: 'weighted';

        $channel = $this->getMqChannel();

        // 每60秒进行一次 MQ 健康检查
        Timer::add(60, function () {
            try {
                MQService::checkAndHeal();
            } catch (Throwable $e) {
                Log::warning('MQ 健康检查异常: ' . $e->getMessage());
            }
        });

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
            } catch (AMQPTimeoutException $e) {
                // 正常超时，无消息到达
            } catch (Throwable $e) {
                //                Log::error('MailWorker wait error: ' . $e->getMessage());
            }
        }
    }

    protected function handleMessage(AMQPMessage $message): void
    {
        try {
            $raw = $message->getBody();
            if ($raw === '' || $raw === null) {
                throw new RuntimeException('Message body empty');
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                throw new RuntimeException('Message body invalid JSON');
            }

            // 指定平台优先
            $specified = isset($data['provider']) ? (string) $data['provider'] : null;
            if ($specified !== null) {
                $ok = $this->sendViaProvider($data, $specified);
                if (!$ok) {
                    // 指定平台失败：仅在该平台重试一次（快速重试），随后交给队列重试
                    $ok2 = $this->sendViaProvider($data, $specified);
                    if (!$ok2) {
                        $msg = 'Specified provider send failed twice: ' . $specified;
                        if ($this->lastError) {
                            $msg .= ' | last=' . $this->lastError;
                        }
                        throw new RuntimeException($msg);
                    }
                }
            } else {
                // 策略选择平台
                $chosen = $this->chooseProvider();
                if ($chosen === null) {
                    throw new RuntimeException('No available provider');
                }
                $ok = $this->sendViaProvider($data, $chosen);
                if (!$ok) {
                    // 故障切换：再选一个不同平台尝试一次
                    $alt = $this->chooseProvider(exclude: [$chosen]);
                    if ($alt !== null) {
                        $ok2 = $this->sendViaProvider($data, $alt);
                        if (!$ok2) {
                            $msg = 'Failover provider also failed: ' . $alt;
                            if ($this->lastError) {
                                $msg .= ' | last=' . $this->lastError;
                            }
                            throw new RuntimeException($msg);
                        }
                    } else {
                        $msg = 'No alternative provider for failover';
                        if ($this->lastError) {
                            $msg .= ' | last=' . $this->lastError;
                        }
                        throw new RuntimeException($msg);
                    }
                }
            }

            $message->ack();
        } catch (Throwable $e) {
            $extra = $this->lastError ? (' | last=' . $this->lastError) : '';
            Log::error('Send mail failed: ' . $e->getMessage() . $extra);
            $this->handleFailedMessage($message);
        }
    }

    protected function handleFailedMessage(AMQPMessage $message): void
    {
        $headers = $message->has('application_headers') ? $message->get('application_headers') : null;
        $retry = 0;
        if ($headers instanceof AMQPTable) {
            $native = method_exists($headers, 'getNativeData') ? $headers->getNativeData() : (array) $headers;
            $retry = (int) ($native['x-retry-count'] ?? 0);
        }

        if ($retry < 2) { // 第1、2次失败重试；第3次进入死信
            $newHeaders = $headers ? clone $headers : new AMQPTable();
            $newHeaders->set('x-retry-count', $retry + 1);

            $newMsg = new AMQPMessage($message->getBody(), [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'application_headers' => $newHeaders,
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
        // 保留旧接口以兼容（使用全局 mail_* 单平台配置）
        $mailer = new PHPMailer(true);
        try {
            $transport = (string) blog_config('mail_transport', 'smtp', true);
            $host = (string) blog_config('mail_host', '', true);
            $port = (int) blog_config('mail_port', 587, true);
            $username = (string) blog_config('mail_username', '', true);
            $password = (string) blog_config('mail_password', '', true);
            $encryption = (string) blog_config('mail_encryption', 'tls', true); // tls|ssl|''

            $fromAddress = (string) blog_config('mail_from_address', 'no-reply@example.com', true);
            $fromName = (string) blog_config('mail_from_name', 'WindBlog', true);
            $replyTo = (string) blog_config('mail_reply_to', '', true);

            // 支持 payload 覆盖 from_name 与 reply_to
            if (!empty($data['from_name'])) {
                $fromName = (string) $data['from_name'];
            }
            if (!empty($data['reply_to'])) {
                $replyTo = (string) $data['reply_to'];
            }

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
                        $mailer->addAddress((string) $addr['email'], (string) ($addr['name'] ?? ''));
                    }
                }
            }

            if (!empty($data['headers']) && is_array($data['headers'])) {
                foreach ($data['headers'] as $k => $v) {
                    $mailer->addCustomHeader((string) $k, (string) $v);
                }
            }

            if (!empty($data['attachments']) && is_array($data['attachments'])) {
                foreach ($data['attachments'] as $att) {
                    $path = $att['path'] ?? null;
                    if ($path) {
                        $mailer->addAttachment(
                            (string) $path,
                            isset($att['name']) ? (string) $att['name'] : '',
                            isset($att['encoding']) ? (string) $att['encoding'] : PHPMailer::ENCODING_BASE64,
                            isset($att['type']) ? (string) $att['type'] : ''
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

            $subject = (string) ($data['subject'] ?? '');
            $html = (string) ($data['html'] ?? '');
            $text = (string) ($data['text'] ?? '');

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
     * 使用指定平台发送（返回是否成功），并处理失败计数与惩罚
     */
    protected function sendViaProvider(array $data, string $providerId): bool
    {
        $provider = $this->providers[$providerId] ?? null;
        $this->lastError = null;
        if (!$provider || !$this->canUseProvider($providerId)) {
            return false;
        }

        $mailer = new PHPMailer(true);
        try {
            // 通过 DSN 快速配置（优先），回退到传统配置
            $dsn = (string) ($provider['dsn'] ?? '');
            if ($dsn !== '') {
                $conf = new DSNConfigurator();
                $conf->configure($mailer, $dsn);
            } else {
                // 传统映射：type, host, port, username, password, encryption
                $type = strtolower((string) ($provider['type'] ?? 'smtp'));
                if ($type === 'smtp' || $type === 'smtps') {
                    $mailer->isSMTP();
                    $mailer->Host = (string) ($provider['host'] ?? '');
                    $mailer->SMTPAuth = (bool) ($provider['smtp_auth'] ?? true);
                    $mailer->Username = (string) ($provider['username'] ?? '');
                    $mailer->Password = (string) ($provider['password'] ?? '');
                    $mailer->Port = (int) ($provider['port'] ?? 587);
                    $enc = (string) ($provider['encryption'] ?? ($type === 'smtps' ? 'ssl' : 'tls'));
                    if ($enc) {
                        $mailer->SMTPSecure = $enc; // tls/ssl
                    }
                } elseif ($type === 'mail') {
                    $mailer->isMail();
                } elseif ($type === 'sendmail') {
                    $mailer->isSendmail();
                    $path = (string) ($provider['sendmail_path'] ?? '');
                    if ($path !== '') {
                        $mailer->Sendmail = $path;
                    }
                } elseif ($type === 'qmail') {
                    $mailer->isQmail();
                    $qpath = (string) ($provider['qmail_path'] ?? '');
                    if ($qpath !== '') {
                        $mailer->Sendmail = $qpath;
                    }
                } else {
                    $mailer->isSMTP();
                }
            }

            // 使用卡片内的发件信息（严格不读 blog_config）
            $fromAddress = (string) ($provider['from_address'] ?? '');
            $fromName = (string) ($provider['from_name'] ?? '');
            $replyTo = (string) ($provider['reply_to'] ?? '');
            $mailer->CharSet = 'UTF-8';
            if ($fromAddress !== '') {
                $mailer->setFrom($fromAddress, $fromName !== '' ? $fromName : '');
            }
            if ($replyTo !== '') {
                $mailer->addReplyTo($replyTo, $fromName !== '' ? $fromName : '');
            }

            // 收件人
            $to = $data['to'] ?? null;
            if (is_string($to) && $to !== '') {
                $mailer->addAddress($to);
            } elseif (is_array($to)) {
                foreach ($to as $addr) {
                    if (is_string($addr) && $addr !== '') {
                        $mailer->addAddress($addr);
                    } elseif (is_array($addr) && !empty($addr['email'])) {
                        $mailer->addAddress((string) $addr['email'], (string) ($addr['name'] ?? ''));
                    }
                }
            }

            // 头与附件
            if (!empty($data['headers']) && is_array($data['headers'])) {
                foreach ($data['headers'] as $k => $v) {
                    $mailer->addCustomHeader((string) $k, (string) $v);
                }
            }
            if (!empty($data['attachments']) && is_array($data['attachments'])) {
                foreach ($data['attachments'] as $att) {
                    $path = $att['path'] ?? null;
                    if ($path) {
                        $mailer->addAttachment(
                            (string) $path,
                            isset($att['name']) ? (string) $att['name'] : '',
                            isset($att['encoding']) ? (string) $att['encoding'] : PHPMailer::ENCODING_BASE64,
                            isset($att['type']) ? (string) $att['type'] : ''
                        );
                    }
                }
            }

            // CC/BCC
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

            // 内容
            $subject = (string) ($data['subject'] ?? '');
            $html = (string) ($data['html'] ?? '');
            $text = (string) ($data['text'] ?? '');
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

            // 成功：如果之前有降权或禁用，在恢复窗口到时自动恢复由后台任务/下次选择判断；此处不做立即恢复以降低抖动
            return true;
        } catch (MailException $e) {
            $this->lastError = $e->getMessage();
            $this->recordFailure($providerId);

            return false;
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            $this->recordFailure($providerId);

            return false;
        }
    }

    /**
     * 选择平台（加权随机/轮询），排除禁用与 ban 中的平台
     *
     * @param array<int,string> $exclude
     */
    protected function chooseProvider(array $exclude = []): ?string
    {
        // 过滤可用
        $candidates = [];
        foreach ($this->providers as $id => $p) {
            if (!empty($exclude) && in_array($id, $exclude, true)) {
                continue;
            }
            if (!($p['enabled'] ?? true)) {
                continue;
            }
            if (!$this->canUseProvider($id)) {
                continue;
            }
            $weight = max(0, (int) ($p['weight'] ?? 1));
            if ($weight > 0) {
                $candidates[$id] = $weight;
            }
        }
        if (!$candidates) {
            return null;
        }

        if ($this->strategy === 'weighted') {
            $sum = array_sum($candidates);
            $rand = random_int(1, max(1, $sum));
            $acc = 0;
            foreach ($candidates as $id => $w) {
                $acc += $w;
                if ($rand <= $acc) {
                    return $id;
                }
            }

            return array_key_first($candidates);
        }

        // 简化的加权轮询：按权重展开序列存于内存，游标保存在文件（简化起见此处回退到加权随机）
        return array_key_first($candidates);
    }

    /**
     * 加载平台配置
     */
    protected function loadProviders(): array
    {
        $raw = blog_config('mail_providers', '[]', true);
        if (is_string($raw)) {
            $list = json_decode($raw, true);
        } else {
            $list = $raw;
        }
        $out = [];
        if (is_array($list)) {
            foreach ($list as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $id = (string) ($item['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $out[$id] = $item;
            }
        }
        // 当没有配置任何平台时，使用全局 mail_* 作为后备平台（只读）
        if (!$out) {
            $transport = strtolower((string) blog_config('mail_transport', 'smtp', true) ?: 'smtp');
            $host = (string) blog_config('mail_host', '', true);
            $port = (int) blog_config('mail_port', 587, true);
            $username = (string) blog_config('mail_username', '', true);
            $password = (string) blog_config('mail_password', '', true);
            $encryption = (string) blog_config('mail_encryption', 'tls', true);
            $out['legacy_smtp'] = [
                'id' => 'legacy_smtp',
                'name' => '默认邮件平台',
                'type' => $transport ?: 'smtp',
                'host' => $host,
                'port' => $port,
                'username' => $username,
                'password' => $password,
                'encryption' => $encryption,
                'smtp_auth' => true,
                'enabled' => true,
                'weight' => 1,
                'dsn' => '',
            ];
        }

        return $out;
    }

    /**
     * 是否可用（未到 ban_until）
     */
    protected function canUseProvider(string $id): bool
    {
        $state = $this->readFailState();
        $s = $state[$id] ?? null;
        if (!$s) {
            return true;
        }
        $now = time();
        if (!empty($s['ban_until']) && (int) $s['ban_until'] > $now) {
            return false;
        }

        return true;
    }

    /**
     * 记录失败并应用惩罚：>5 次失败，降权为 0 并 ban 10 分钟；1 小时后自动恢复（在选择时判断并恢复）
     */
    protected function recordFailure(string $id): void
    {
        $state = $this->readFailState();
        $now = time();
        $s = $state[$id] ?? ['fail_count' => 0, 'last_fail_ts' => 0, 'ban_until' => 0, 'weight_backup' => null];
        $s['fail_count'] = (int) $s['fail_count'] + 1;
        $s['last_fail_ts'] = $now;

        if ($s['fail_count'] > 5) {
            // 若尚未备份权重，备份并将当前权重视为 0（选择阶段通过 canUseProvider + candidates 权重过滤达到禁用效果）
            if ($s['weight_backup'] === null && isset($this->providers[$id]['weight'])) {
                $s['weight_backup'] = (int) $this->providers[$id]['weight'];
            }
            $s['ban_until'] = $now + 10 * 60; // 10 分钟
        }

        $state[$id] = $s;
        $this->writeFailState($state);
    }

    /**
     * 在选择时尝试恢复：超过1小时则恢复权重并清零计数
     */
    protected function tryRecover(string $id): void
    {
        $state = $this->readFailState();
        $s = $state[$id] ?? null;
        if (!$s) {
            return;
        }
        $now = time();
        if (!empty($s['last_fail_ts']) && ($now - (int) $s['last_fail_ts']) >= 3600) {
            // 恢复
            if ($s['weight_backup'] !== null) {
                // 恢复权重
                if (isset($this->providers[$id])) {
                    $this->providers[$id]['weight'] = (int) $s['weight_backup'];
                }
            }
            $state[$id] = ['fail_count' => 0, 'last_fail_ts' => 0, 'ban_until' => 0, 'weight_backup' => null];
            $this->writeFailState($state);
        }
    }

    protected function readFailState(): array
    {
        $file = $this->failFile;
        if ($file === '' || !is_file($file)) {
            return [];
        }
        try {
            $txt = (string) @file_get_contents($file);
            $arr = json_decode($txt, true);

            return is_array($arr) ? $arr : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    protected function writeFailState(array $state): void
    {
        $file = $this->failFile;
        try {
            @file_put_contents($file, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (Throwable $e) {
            // 忽略
        }
    }

    /**
     * 初始化 mail_* 的默认配置（首次无记录时落库）
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }
}
