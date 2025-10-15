<?php

declare(strict_types=1);

namespace plugin\admin\app\controller;

use app\service\MailService;
use support\Request;
use support\Response;

/**
 * 后台-邮件管理（重构版）
 * - 设置页：/app/admin/mail/index（模板）
 * - 模板预览页：/app/admin/mail/preview（模板）
 * - 发信测试页：/app/admin/mail/send（模板）
 * - 预览渲染API：/app/admin/mail/preview-render（返回HTML）
 * - 入队测试API：/app/admin/mail/enqueue-test
 * - 队列统计API：/app/admin/mail/queue-stats
 * - 配置读写API：/app/admin/mail/config、/app/admin/mail/config-save
 * - 连接测试API：/app/admin/mail/config-test
 */
class MailController
{
    /**
     * 多平台配置读取
     * GET /app/admin/mail/providers
     */
    public function providersGet(Request $request): Response
    {
        $raw = blog_config('mail_providers', '[]', false, true, false);
        if (is_string($raw)) {
            $list = json_decode($raw, true);
        } else {
            $list = $raw;
        }
        $strategy = (string) blog_config('mail_strategy', 'weighted', false, true, false) ?: 'weighted';

        return json(['code' => 0, 'data' => [
            'providers' => is_array($list) ? $list : [],
            'strategy' => in_array($strategy, ['weighted', 'rr'], true) ? $strategy : 'weighted',
        ]]);
    }

    /**
     * 多平台配置保存
     * POST /app/admin/mail/providers-save
     * body: [{id,name,dsn|type,host,port,username,password,encryption,weight,enabled}, ...]
     */
    public function providersSave(Request $request): Response
    {
        try {
            $payload = $request->post();
            if (!is_array($payload)) {
                $json = (string) $request->rawBody();
                $payload = json_decode($json, true);
            }
            if (!is_array($payload)) {
                return json(['code' => 1, 'msg' => 'invalid payload']);
            }

            // 支持两种格式：
            // 1) 旧格式：直接是 providers 数组
            // 2) 新格式：{ strategy: 'weighted'|'rr', providers: [...] }
            $strategy = 'weighted';
            $list = [];
            if (isset($payload['providers']) && is_array($payload['providers'])) {
                $list = $payload['providers'];
                $st = (string) ($payload['strategy'] ?? 'weighted');
                $strategy = in_array($st, ['weighted', 'rr'], true) ? $st : 'weighted';
            } else {
                // 旧格式
                $list = $payload;
            }

            // 规范化与校验
            $out = [];
            foreach ($list as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $id = trim((string) ($item['id'] ?? ''));
                $name = trim((string) ($item['name'] ?? ''));
                if ($id === '' || $name === '') {
                    continue;
                }
                $weight = max(0, (int) ($item['weight'] ?? 1));
                $enabled = (bool) ($item['enabled'] ?? true);
                $norm = $item;
                $norm['id'] = $id;
                $norm['name'] = $name;
                $norm['weight'] = $weight;
                $norm['enabled'] = $enabled;
                $out[] = $norm;
            }

            blog_config('mail_providers', json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), false, true, true);
            blog_config('mail_strategy', $strategy, false, true, true);

            return json(['code' => 0, 'msg' => '保存成功']);
        } catch (\Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 分平台测试发信（不入队，直接发送）
     * POST /app/admin/mail/provider-test
     * body: { provider: 'id', to:'x@y', subject:'...', text:'...'|view/inline_template... }
     */
    public function providerTest(Request $request): Response
    {
        try {
            $data = (array) $request->post();
            $provider = (string) ($data['provider'] ?? '');
            if ($provider === '') {
                return json(['code' => 1, 'msg' => 'provider is required']);
            }
            // 测试发信不使用任何模板字段，仅使用 subject/text/html；不做模板渲染
            // 若仅有 text，可由前端或调用方自行构造 html
            // 直接调用 MailWorker 的发送方法（构造一次实例）
            $worker = new \app\process\MailWorker();
            $ref = new \ReflectionClass($worker);
            $method = $ref->getMethod('sendViaProvider');
            $method->setAccessible(true);
            $ok = (bool) $method->invoke($worker, $data, $provider);
            $err = method_exists($worker, 'getLastError') ? (string) $worker->getLastError() : '';

            return json(['code' => $ok ? 0 : 1, 'msg' => $ok ? 'ok' : ($err !== '' ? $err : 'failed')]);
        } catch (\Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 获取/保存配置所用的键名清单
     */
    protected function mailConfigKeys(): array
    {
        return [
            'mail_transport', 'mail_host', 'mail_port',
            'mail_username', 'mail_password', 'mail_encryption',
            'mail_from_address', 'mail_from_name', 'mail_reply_to',
            // 队列相关键
            'rabbitmq_mail_exchange', 'rabbitmq_mail_routing_key', 'rabbitmq_mail_queue',
            'rabbitmq_mail_dlx_exchange', 'rabbitmq_mail_dlx_queue',
            // MQ连接
            'rabbitmq_host', 'rabbitmq_port', 'rabbitmq_user', 'rabbitmq_password', 'rabbitmq_vhost',
        ];
    }

    /**
     * 配置读取
     * GET /app/admin/mail/config
     * 保留队列命名与MQ连接项读取；mail_* 将逐步淡化至仅兼容，不再在index页编辑
     */
    public function configGet(Request $request): Response
    {
        $keys = [
            'rabbitmq_mail_exchange', 'rabbitmq_mail_routing_key', 'rabbitmq_mail_queue',
            'rabbitmq_mail_dlx_exchange', 'rabbitmq_mail_dlx_queue',
            'rabbitmq_host', 'rabbitmq_port', 'rabbitmq_user', 'rabbitmq_password', 'rabbitmq_vhost',
        ];
        $data = [];
        foreach ($keys as $k) {
            $data[$k] = blog_config($k, '', false, true, false);
        }
        if (!empty($data['rabbitmq_password'])) {
            $data['rabbitmq_password'] = '******';
        }

        return json(['code' => 0, 'data' => $data]);
    }

    /**
     * 配置保存（仅队列命名）
     * POST /app/admin/mail/config-save
     * body: rabbitmq_mail_*（可选）
     */
    public function configSave(Request $request): Response
    {
        try {
            $post = (array) $request->post();
            $rabbitKeys = [
                'rabbitmq_mail_exchange'      => (string) ($post['rabbitmq_mail_exchange'] ?? ''),
                'rabbitmq_mail_routing_key'   => (string) ($post['rabbitmq_mail_routing_key'] ?? ''),
                'rabbitmq_mail_queue'         => (string) ($post['rabbitmq_mail_queue'] ?? ''),
                'rabbitmq_mail_dlx_exchange'  => (string) ($post['rabbitmq_mail_dlx_exchange'] ?? ''),
                'rabbitmq_mail_dlx_queue'     => (string) ($post['rabbitmq_mail_dlx_queue'] ?? ''),
            ];
            foreach ($rabbitKeys as $k => $v) {
                if ($v !== '') {
                    blog_config($k, $v, false, true, true);
                }
            }

            return json(['code' => 0, 'msg' => '保存成功']);
        } catch (\Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 策略读取
     * GET /app/admin/mail/strategy-get
     */
    public function strategyGet(Request $request): Response
    {
        $st = (string) blog_config('mail_strategy', 'weighted', false, true, false) ?: 'weighted';
        if (!in_array($st, ['weighted', 'rr'], true)) {
            $st = 'weighted';
        }

        return json(['code' => 0, 'data' => ['mail_strategy' => $st]]);
    }

    /**
     * 策略保存
     * POST /app/admin/mail/strategy-save
     * body: { mail_strategy: 'weighted'|'rr' }
     */
    public function strategySave(Request $request): Response
    {
        try {
            $payload = (array) $request->post();
            $st = (string) ($payload['mail_strategy'] ?? 'weighted');
            if (!in_array($st, ['weighted', 'rr'], true)) {
                return json(['code' => 1, 'msg' => 'invalid strategy']);
            }
            blog_config('mail_strategy', $st, false, true, true);

            return json(['code' => 0, 'msg' => '保存成功']);
        } catch (\Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 设置页
     * GET /app/admin/mail/index
     */
    public function index(Request $request): Response
    {
        $path = base_path() . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'mail' . DIRECTORY_SEPARATOR . 'index.html';
        if (is_file($path)) {
            return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], (string) file_get_contents($path));
        }

        return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8'], 'mail index template not found');
    }

    /**
     * 模板预览页
     * GET /app/admin/mail/preview
     */
    public function pagePreview(Request $request): Response
    {
        $path = base_path() . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'mail' . DIRECTORY_SEPARATOR . 'preview.html';
        if (is_file($path)) {
            return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], (string) file_get_contents($path));
        }

        return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8'], 'mail preview template not found');
    }

    /**
     * 预览模板渲染（仅渲染返回HTML，不发送）
     * GET /app/admin/mail/preview-render?view=emails/example&vars[username]=Alice
     */
    public function previewRender(Request $request): Response
    {
        try {
            // 优先支持内联模板
            $inline = (string) $request->get('inline_template', '');
            if ($inline !== '') {
                $inlineVars = (array) $request->get('inline_vars', []);
                $html = MailService::renderInline($inline, $inlineVars);

                return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
            }

            // 回退到视图模板渲染
            $view = (string) $request->get('view', '');
            if ($view === '') {
                return json(['code' => 1, 'msg' => 'view or inline_template is required']);
            }
            $vars = (array) $request->get('vars', []);
            $html = MailService::renderView($view, $vars, app: null, plugin: null);

            return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
        } catch (\Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 发信测试页
     * GET /app/admin/mail/send
     */
    public function pageSend(Request $request): Response
    {
        $path = base_path() . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'mail' . DIRECTORY_SEPARATOR . 'send.html';
        if (is_file($path)) {
            return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], (string) file_get_contents($path));
        }

        return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8'], 'mail send template not found');
    }

    /**
     * 入队一封测试邮件（便于后台联通性检查）
     * POST /app/admin/mail/enqueue-test
     * body: { "to": "test@example.com", "subject": "Test", "view": "emails/example", "view_vars": {"name":"Alice"}, "text": "..." }
     */
    public function enqueueTest(Request $request): Response
    {
        try {
            $data = (array) $request->post();
            if (empty($data['to'])) {
                return json(['code' => 1, 'msg' => 'to is required']);
            }
            // 后台测试不使用模板渲染，直接使用 subject/text/html
            if (empty($data['html']) && !empty($data['text'])) {
                $safeText = (string) $data['text'];
                $data['html'] = '<pre style="font-family: inherit; white-space: pre-wrap;">' . htmlspecialchars($safeText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
            }
            // 移除模板相关字段，避免 MailService 读取视图目录
            unset($data['view'], $data['view_vars'], $data['inline_template'], $data['inline_vars']);

            $ok = MailService::enqueue($data);

            return json(['code' => $ok ? 0 : 1, 'msg' => $ok ? 'enqueued' : 'failed']);
        } catch (\Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 队列统计
     * GET /app/admin/mail/queue-stats
     */
    public function queueStats(): Response
    {
        try {
            $exchange = (string) blog_config('rabbitmq_mail_exchange', 'mail_exchange', true);
            $routingKey = (string) blog_config('rabbitmq_mail_routing_key', 'mail_send', true);
            $queue = (string) blog_config('rabbitmq_mail_queue', 'mail_queue', true);
            $dlx = (string) blog_config('rabbitmq_mail_dlx_exchange', 'mail_dlx_exchange', true);
            $dlq = (string) blog_config('rabbitmq_mail_dlx_queue', 'mail_dlx_queue', true);

            $host = (string) blog_config('rabbitmq_host', '127.0.0.1', true);
            $port = (int) blog_config('rabbitmq_port', 5672, true);
            $user = (string) blog_config('rabbitmq_user', 'guest', true);
            $pass = (string) blog_config('rabbitmq_password', 'guest', true);
            $vhost = (string) blog_config('rabbitmq_vhost', '/', true);

            $conn = new \PhpAmqpLib\Connection\AMQPStreamConnection($host, $port, $user, $pass, $vhost);
            $ch = $conn->channel();

            // 被动声明获取队列深度（返回[queue, messageCount, consumerCount]）
            [$qName, $qCount] = (function () use ($ch, $queue) {
                try {
                    $result = $ch->queue_declare($queue, true, true, false, false);

                    return [$result[0] ?? $queue, (int) ($result[1] ?? 0)];
                } catch (\Throwable $e) {
                    return [$queue, 0];
                }
            })();

            [$dlqName, $dlqCount] = (function () use ($ch, $dlq) {
                try {
                    $result = $ch->queue_declare($dlq, true, true, false, false);

                    return [$result[0] ?? $dlq, (int) ($result[1] ?? 0)];
                } catch (\Throwable $e) {
                    return [$dlq, 0];
                }
            })();

            $ch->close();
            $conn->close();

            return json([
                'code' => 0,
                'data' => [
                    'exchange' => $exchange,
                    'routingKey' => $routingKey,
                    'queue' => $qName,
                    'queue_depth' => $qCount,
                    'dlx' => $dlx,
                    'dlq' => $dlqName,
                    'dlq_depth' => $dlqCount,
                ],
            ]);
        } catch (\Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * SMTP连接测试（不发送）
     * POST /app/admin/mail/config-test
     */
    public function configTest(Request $request): Response
    {
        try {
            $post = (array) $request->post();
            $host = (string) ($post['mail_host'] ?? blog_config('mail_host', '', false, true, false));
            $port = (int) ($post['mail_port'] ?? blog_config('mail_port', 587, false, true, false));
            $enc = (string) ($post['mail_encryption'] ?? blog_config('mail_encryption', 'tls', false, true, false));

            if ($host === '' || $port <= 0) {
                return json(['code' => 1, 'msg' => '请填写有效的主机与端口']);
            }

            $scheme = ($enc === 'ssl') ? 'ssl://' : '';
            $errno = 0;
            $errstr = '';
            $fp = @stream_socket_client($scheme . $host . ':' . $port, $errno, $errstr, 5, STREAM_CLIENT_CONNECT);
            if ($fp) {
                @fclose($fp);

                return json(['code' => 0, 'msg' => 'TCP连接成功']);
            }

            return json(['code' => 1, 'msg' => $errstr ?: '连接失败']);
        } catch (\Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }
}
