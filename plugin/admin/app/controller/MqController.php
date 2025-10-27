<?php

namespace plugin\admin\app\controller;

use function blog_config;
use function json;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use support\Request;
use support\Response;
use Throwable;

class MqController
{
    // 菜单入口
    public function index(Request $request): Response
    {
        return view('mq/index');
    }

    // 获取配置（AJAX）
    public function get(Request $request): Response
    {
        $data = [
            // RabbitMQ 连接
            'rabbitmq_host'    => (string) blog_config('rabbitmq_host', '127.0.0.1', true),
            'rabbitmq_port'    => (int) blog_config('rabbitmq_port', 5672, true),
            'rabbitmq_user'    => (string) blog_config('rabbitmq_user', 'guest', true),
            'rabbitmq_password'=> (string) blog_config('rabbitmq_password', 'guest', true),
            'rabbitmq_vhost'   => (string) blog_config('rabbitmq_vhost', '/', true),

            // 静态化命名
            'rabbitmq_static_exchange'     => (string) blog_config('rabbitmq_static_exchange', 'windblog_static_gen', true),
            'rabbitmq_static_routing_key'  => (string) blog_config('rabbitmq_static_routing_key', 'static_gen', true),
            'rabbitmq_static_queue'        => (string) blog_config('rabbitmq_static_queue', 'windblog_static_queue', true),
            'rabbitmq_static_dlx_exchange' => (string) blog_config('rabbitmq_static_dlx_exchange', 'windblog_static_dlx', true),
            'rabbitmq_static_dlx_queue'    => (string) blog_config('rabbitmq_static_dlx_queue', 'windblog_static_dlq', true),
        ];

        return json(['success' => true, 'data' => $data]);
    }

    // 保存配置（AJAX）
    public function save(Request $request): Response
    {
        $cfg = [
            'rabbitmq_host'    => (string) $request->post('rabbitmq_host', '127.0.0.1'),
            'rabbitmq_port'    => (int) $request->post('rabbitmq_port', 5672),
            'rabbitmq_user'    => (string) $request->post('rabbitmq_user', 'guest'),
            'rabbitmq_password'=> (string) $request->post('rabbitmq_password', 'guest'),
            'rabbitmq_vhost'   => (string) $request->post('rabbitmq_vhost', '/'),

            'rabbitmq_static_exchange'     => (string) $request->post('rabbitmq_static_exchange', 'windblog_static_gen'),
            'rabbitmq_static_routing_key'  => (string) $request->post('rabbitmq_static_routing_key', 'static_gen'),
            'rabbitmq_static_queue'        => (string) $request->post('rabbitmq_static_queue', 'windblog_static_queue'),
            'rabbitmq_static_dlx_exchange' => (string) $request->post('rabbitmq_static_dlx_exchange', 'windblog_static_dlx'),
            'rabbitmq_static_dlx_queue'    => (string) $request->post('rabbitmq_static_dlx_queue', 'windblog_static_dlq'),
        ];

        // 写入 blog_config
        blog_config('rabbitmq_host', $cfg['rabbitmq_host'], true, true, true);
        blog_config('rabbitmq_port', $cfg['rabbitmq_port'], true, true, true);
        blog_config('rabbitmq_user', $cfg['rabbitmq_user'], true, true, true);
        blog_config('rabbitmq_password', $cfg['rabbitmq_password'], true, true, true);
        blog_config('rabbitmq_vhost', $cfg['rabbitmq_vhost'], true, true, true);

        blog_config('rabbitmq_static_exchange', $cfg['rabbitmq_static_exchange'], true, true, true);
        blog_config('rabbitmq_static_routing_key', $cfg['rabbitmq_static_routing_key'], true, true, true);
        blog_config('rabbitmq_static_queue', $cfg['rabbitmq_static_queue'], true, true, true);
        blog_config('rabbitmq_static_dlx_exchange', $cfg['rabbitmq_static_dlx_exchange'], true, true, true);
        blog_config('rabbitmq_static_dlx_queue', $cfg['rabbitmq_static_dlx_queue'], true, true, true);

        return json(['success' => true]);
    }

    // 测试RabbitMQ连接（AJAX）
    public function test(Request $request): Response
    {
        try {
            $host = (string) blog_config('rabbitmq_host', '127.0.0.1', true);
            $port = (int) blog_config('rabbitmq_port', 5672, true);
            $user = (string) blog_config('rabbitmq_user', 'guest', true);
            $pass = (string) blog_config('rabbitmq_password', 'guest', true);
            $vhost = (string) blog_config('rabbitmq_vhost', '/', true);

            $conn = new AMQPStreamConnection($host, $port, $user, $pass, $vhost);
            $ch = $conn->channel();
            $ch->close();
            $conn->close();

            return json(['success' => true, 'message' => 'RabbitMQ 连接成功']);
        } catch (Throwable $e) {
            return json(['success' => false, 'message' => '连接失败：' . $e->getMessage()]);
        }
    }
}
