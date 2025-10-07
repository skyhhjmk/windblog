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
     */
    public function configGet(Request $request): Response
    {
        $keys = $this->mailConfigKeys();
        $data = [];
        foreach ($keys as $k) {
            $data[$k] = blog_config($k, '', false, true, false);
        }
        // 不回显密码原文
        if (!empty($data['mail_password'])) {
            $data['mail_password'] = '******';
        }
        if (!empty($data['rabbitmq_password'])) {
            $data['rabbitmq_password'] = '******';
        }
        return json(['code' => 0, 'data' => $data]);
    }

    /**
     * 配置保存（含队列命名）
     * POST /app/admin/mail/config-save
     * body: mail_* + rabbitmq_mail_*（可选）
     */
    public function configSave(Request $request): Response
    {
        try {
            $post = (array)$request->post();

            $transport = (string)($post['mail_transport'] ?? 'smtp');
            $host      = (string)($post['mail_host'] ?? '');
            $port      = (int)($post['mail_port'] ?? 587);
            $username  = (string)($post['mail_username'] ?? '');
            $password  = $post['mail_password'] ?? null; // 允许不传或传占位
            $enc       = (string)($post['mail_encryption'] ?? 'tls'); // tls/ssl/none
            $fromAddr  = (string)($post['mail_from_address'] ?? '');
            $fromName  = (string)($post['mail_from_name'] ?? '');
            $replyTo   = (string)($post['mail_reply_to'] ?? '');

            if ($host === '' || $fromAddr === '') {
                return json(['code' => 1, 'msg' => 'mail_host 与 mail_from_address 不能为空']);
            }
            if (!in_array($transport, ['smtp'], true)) {
                return json(['code' => 1, 'msg' => '暂仅支持 smtp']);
            }

            // 保存 mail_* 配置
            blog_config('mail_transport', $transport, false, true, true);
            blog_config('mail_host', $host, false, true, true);
            blog_config('mail_port', $port, false, true, true);
            blog_config('mail_username', $username, false, true, true);
            if ($password !== null && $password !== '******') {
                blog_config('mail_password', (string)$password, false, true, true);
            }
            blog_config('mail_encryption', $enc, false, true, true);
            blog_config('mail_from_address', $fromAddr, false, true, true);
            blog_config('mail_from_name', $fromName, false, true, true);
            blog_config('mail_reply_to', $replyTo, false, true, true);

            // 保存队列命名（允许更新）
            $rabbitKeys = [
                'rabbitmq_mail_exchange'      => (string)($post['rabbitmq_mail_exchange'] ?? ''),
                'rabbitmq_mail_routing_key'   => (string)($post['rabbitmq_mail_routing_key'] ?? ''),
                'rabbitmq_mail_queue'         => (string)($post['rabbitmq_mail_queue'] ?? ''),
                'rabbitmq_mail_dlx_exchange'  => (string)($post['rabbitmq_mail_dlx_exchange'] ?? ''),
                'rabbitmq_mail_dlx_queue'     => (string)($post['rabbitmq_mail_dlx_queue'] ?? ''),
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
     * 设置页
     * GET /app/admin/mail/index
     */
    public function index(Request $request): Response
    {
        $path = base_path() . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'mail' . DIRECTORY_SEPARATOR . 'index.html';
        if (is_file($path)) {
            return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], (string)file_get_contents($path));
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
            return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], (string)file_get_contents($path));
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
            $inline = (string)$request->get('inline_template', '');
            if ($inline !== '') {
                $inlineVars = (array)$request->get('inline_vars', []);
                $html = MailService::renderInline($inline, $inlineVars);
                return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
            }

            // 回退到视图模板渲染
            $view = (string)$request->get('view', '');
            if ($view === '') {
                return json(['code' => 1, 'msg' => 'view or inline_template is required']);
            }
            $vars = (array)$request->get('vars', []);
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
            return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], (string)file_get_contents($path));
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
        $data = (array)$request->post();
        if (empty($data['to'])) {
            return json(['code' => 1, 'msg' => 'to is required']);
        }
        $ok = MailService::enqueue($data);
        return json(['code' => $ok ? 0 : 1, 'msg' => $ok ? 'enqueued' : 'failed']);
    }

    /**
     * 队列统计
     * GET /app/admin/mail/queue-stats
     */
    public function queueStats(): Response
    {
        try {
            $exchange   = (string)blog_config('rabbitmq_mail_exchange', 'mail_exchange', true);
            $routingKey = (string)blog_config('rabbitmq_mail_routing_key', 'mail_send', true);
            $queue      = (string)blog_config('rabbitmq_mail_queue', 'mail_queue', true);
            $dlx        = (string)blog_config('rabbitmq_mail_dlx_exchange', 'mail_dlx_exchange', true);
            $dlq        = (string)blog_config('rabbitmq_mail_dlx_queue', 'mail_dlx_queue', true);

            $host   = (string)blog_config('rabbitmq_host', '127.0.0.1', true);
            $port   = (int)blog_config('rabbitmq_port', 5672, true);
            $user   = (string)blog_config('rabbitmq_user', 'guest', true);
            $pass   = (string)blog_config('rabbitmq_password', 'guest', true);
            $vhost  = (string)blog_config('rabbitmq_vhost', '/', true);

            $conn = new \PhpAmqpLib\Connection\AMQPStreamConnection($host, $port, $user, $pass, $vhost);
            $ch = $conn->channel();

            // 被动声明获取队列深度（返回[queue, messageCount, consumerCount]）
            [$qName, $qCount] = (function() use ($ch, $queue) {
                try {
                    $result = $ch->queue_declare($queue, true, true, false, false);
                    return [$result[0] ?? $queue, (int)($result[1] ?? 0)];
                } catch (\Throwable $e) {
                    return [$queue, 0];
                }
            })();

            [$dlqName, $dlqCount] = (function() use ($ch, $dlq) {
                try {
                    $result = $ch->queue_declare($dlq, true, true, false, false);
                    return [$result[0] ?? $dlq, (int)($result[1] ?? 0)];
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
                ]
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
            $post = (array)$request->post();
            $host = (string)($post['mail_host'] ?? blog_config('mail_host', '', false, true, false));
            $port = (int)($post['mail_port'] ?? blog_config('mail_port', 587, false, true, false));
            $enc  = (string)($post['mail_encryption'] ?? blog_config('mail_encryption', 'tls', false, true, false));

            if ($host === '' || $port <= 0) {
                return json(['code' => 1, 'msg' => '请填写有效的主机与端口']);
            }

            $scheme = ($enc === 'ssl') ? 'ssl://' : '';
            $errno = 0; $errstr = '';
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