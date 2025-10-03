<?php
namespace app\process;

use app\controller\IndexController;
use app\controller\LinkController;
use app\controller\PostController;
use app\controller\SearchController;
use app\model\Post;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use support\Log;
use support\Request;
use support\Response;

/**
 * 全站静态化生成进程
 * - 消费静态化任务队列，内部渲染并落盘至 public/static
 * - 周期增量：每小时检查最近24小时更新的文章
 * - 手动全量：向队列投递 scope=all 或具体范围
 */
class StaticGenerator
{
    /** @var AMQPStreamConnection|null */
    protected ?AMQPStreamConnection $mqConnection = null;

    /** @var \PhpAmqpLib\Channel\AMQPChannel|null */
    protected $mqChannel = null;

    // MQ 命名（按要求将 . 替换为 _）
    private string $exchange = 'windblog_static_gen';
    private string $routingKey = 'static_gen';
    private string $queueName = 'windblog_static_queue';
    private string $dlxExchange = 'windblog_static_dlx';
    private string $dlxQueue = 'windblog_static_dlq';

    public function onWorkerStart(): void
    {
        $this->initMq();
        $this->startConsumer();

        // 周期增量：每小时执行一次
        if (class_exists(\Workerman\Timer::class)) {
            \Workerman\Timer::add(3600, function () {
                $this->enqueueIncrementalPosts(24);
            });
        }
    }

    protected function initMq(): void
    {
        try {
            $this->mqConnection = new AMQPStreamConnection(
                blog_config('rabbitmq_host', '127.0.0.1', true),
                (int)blog_config('rabbitmq_port', 5672, true),
                blog_config('rabbitmq_user', 'guest', true),
                blog_config('rabbitmq_password', 'guest', true),
                blog_config('rabbitmq_vhost', '/', true)
            );
            $this->mqChannel = $this->mqConnection->channel();

            // 声明死信交换机/队列
            $this->mqChannel->exchange_declare($this->dlxExchange, 'direct', false, true, false);
            $this->mqChannel->queue_declare($this->dlxQueue, false, true, false, false);
            $this->mqChannel->queue_bind($this->dlxQueue, $this->dlxExchange, $this->dlxQueue);

            // 主交换机/队列（带死信）
            $this->mqChannel->exchange_declare($this->exchange, 'direct', false, true, false);
            $args = [
                'x-dead-letter-exchange' => ['S', $this->dlxExchange],
                'x-dead-letter-routing-key' => ['S', $this->dlxQueue],
            ];
            try {
                $this->mqChannel->queue_declare($this->queueName, false, true, false, false, false, $args);
            } catch (\Exception $e) {
                Log::warning('StaticGenerator 队列声明失败，尝试无参重建: ' . $e->getMessage());
                $this->mqChannel->queue_declare($this->queueName, false, true, false, false, false);
            }
            $this->mqChannel->queue_bind($this->queueName, $this->exchange, $this->routingKey);

            Log::info('StaticGenerator MQ 初始化成功');
        } catch (\Throwable $e) {
            Log::error('StaticGenerator MQ 初始化失败: ' . $e->getMessage());
        }
    }

    protected function startConsumer(): void
    {
        if (!$this->mqChannel) {
            return;
        }
        $this->mqChannel->basic_qos(null, 1, null);
        $this->mqChannel->basic_consume($this->queueName, '', false, false, false, false, function (AMQPMessage $message) {
            $this->handleMessage($message);
        });
        // 在 worker 循环中轮询消费
        if (class_exists(\Workerman\Timer::class)) {
            \Workerman\Timer::add(1, function () {
                if ($this->mqChannel) {
                    $this->mqChannel->wait(null, false, 0.1);
                }
            });
        }
    }

    protected function handleMessage(AMQPMessage $message): void
    {
        try {
            $payload = json_decode($message->getBody(), true);
            if (!is_array($payload)) {
                throw new \RuntimeException('消息体不是有效JSON');
            }
            $type = $payload['type'] ?? 'url';
            $options = $payload['options'] ?? [];
            $force = (bool)($options['force'] ?? false);

            if ($type === 'url') {
                $url = (string)$payload['value'];
                $this->generateByUrl($url, $force);
            } elseif ($type === 'scope') {
                $scope = (string)$payload['value'];
                $pages = (int)($options['pages'] ?? 50);
                $this->generateByScope($scope, $pages, $force);
            } else {
                throw new \RuntimeException('未知消息类型: ' . $type);
            }

            $message->ack();
        } catch (\Throwable $e) {
            Log::error('静态化消息处理失败: ' . $e->getMessage());
            $this->handleFailedMessage($message);
        }
    }

    protected function handleFailedMessage(AMQPMessage $message): void
    {
        $headers = $message->has('application_headers') ? $message->get('application_headers') : null;
        $retry = 0;
        if ($headers instanceof \PhpAmqpLib\Wire\AMQPTable) {
            $retry = (int)$headers->get('x-retry-count', 0);
        }
        if ($retry < 2) { // 第1、2次失败重试；第3次进入死信
            $newHeaders = $headers ? clone $headers : new \PhpAmqpLib\Wire\AMQPTable();
            $newHeaders->set('x-retry-count', $retry + 1);
            $newMsg = new AMQPMessage($message->getBody(), [
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'application_headers' => $newHeaders
            ]);
            $this->mqChannel->basic_publish($newMsg, $this->exchange, $this->routingKey);
            $message->ack();
            Log::warning('静态化消息重试: ' . ($retry + 1));
        } else {
            // 拒绝且不重入队，自动进入 DLQ（队列已配置DLX）
            $message->reject(false);
            Log::error('静态化消息进入死信队列');
        }
    }

    // 生成：按URL
    protected function generateByUrl(string $url, bool $force = false): void
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        // 映射到具体渲染
        if ($path === '/' || preg_match('#^/page/(\d+)(?:\.html)?$#', $path, $m)) {
            $page = isset($m[1]) ? (int)$m[1] : 1;
            $resp = $this->renderIndex($page);
            $this->writeHtml($path, $resp, $force);
            return;
        }
        if ($path === '/link' || preg_match('#^/link/page/(\d+)$#', $path, $m)) {
            $page = isset($m[1]) ? (int)$m[1] : 1;
            $resp = $this->renderLink($page);
            $this->writeHtml($path, $resp, $force);
            return;
        }
        if (preg_match('#^/post/(.+?)(?:\.html)?$#', $path, $m)) {
            $keyword = $m[1];
            $resp = $this->renderPost($keyword);
            $this->writeHtml($path, $resp, $force);
            return;
        }
        if ($path === '/search') {
            $resp = $this->renderSearch();
            $this->writeHtml($path, $resp, $force);
            return;
        }
        // 其他页面可按需扩展
        Log::warning('未匹配的静态化URL: ' . $url);
    }

    // 生成：按范围
    protected function generateByScope(string $scope, int $pages = 50, bool $force = false): void
    {
        switch ($scope) {
            case 'index':
                for ($p = 1; $p <= $pages; $p++) {
                    $resp = $this->renderIndex($p);
                    $this->writeHtml("/page/$p", $resp, $force);
                }
                // 首页单独
                $this->writeHtml('/', $this->renderIndex(1), $force);
                break;
            case 'list':
                for ($p = 1; $p <= $pages; $p++) {
                    $resp = $this->renderLink($p);
                    $this->writeHtml("/link/page/$p", $resp, $force);
                }
                $this->writeHtml('/link', $this->renderLink(1), $force);
                break;
            case 'post':
                // 遍历已发布文章
                $posts = Post::where('status', 'published')->select(['slug', 'id'])->get();
                foreach ($posts as $post) {
                    $keyword = $post->slug ?? $post->id;
                    $resp = $this->renderPost((string)$keyword);
                    $this->writeHtml("/post/$keyword", $resp, $force);
                }
                break;
            case 'all':
                $this->generateByScope('index', $pages, $force);
                $this->generateByScope('list', $pages, $force);
                $this->generateByScope('post', $pages, $force);
                $this->writeHtml('/search', $this->renderSearch(), $force);
                break;
            default:
                Log::warning('未知静态化范围: ' . $scope);
        }
    }

    // 内部渲染：Index
    protected function renderIndex(int $page = 1): Response
    {
        $controller = new IndexController();
        $req = $this->makeRequest('/', ['X-PJAX' => 'false']);
        return $controller->index($req, $page);
    }

    // 内部渲染：Link
    protected function renderLink(int $page = 1): Response
    {
        $controller = new LinkController();
        $req = $this->makeRequest('/link', ['X-PJAX' => 'false']);
        return $controller->index($req, $page);
    }

    // 内部渲染：Post
    protected function renderPost(string $keyword): Response
    {
        $controller = new PostController();
        $req = $this->makeRequest('/post/' . $keyword, ['X-PJAX' => 'false']);
        return $controller->index($req, $keyword);
    }

    // 内部渲染：Search（默认空关键词页）
    protected function renderSearch(): Response
    {
        $controller = new SearchController();
        $req = $this->makeRequest('/search', ['X-PJAX' => 'false']);
        return $controller->index($req, 1);
    }

    protected function makeRequest(string $path, array $headers = []): Request
    {
        $server = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $path,
            'HTTP_HOST' => blog_config('site_host', 'localhost', true),
        ];
        foreach ($headers as $k => $v) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
        }
        return new Request($server, [], [], [], '', '');
    }

    protected function writeHtml(string $urlPath, Response $resp, bool $force = false): void
    {
        $code = $resp->getStatusCode();
        if ($code !== 200) {
            Log::warning("渲染非200，跳过: {$urlPath}, code={$code}");
            return;
        }
        $body = $resp->rawBody();
        $target = $this->mapPath($urlPath);
        $full = public_path() . DIRECTORY_SEPARATOR . 'static' . DIRECTORY_SEPARATOR . $target;
        $dir = dirname($full);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (!$force && file_exists($full)) {
            // 简单命中跳过
            return;
        }
        file_put_contents($full, $body);
        Log::info("静态页面生成: {$full}");
    }

    // URL -> 相对文件路径（相对于 public/static）
    protected function mapPath(string $urlPath): string
    {
        $path = ltrim($urlPath, '/');
        if ($path === '' || $path === '/') {
            return 'index.html';
        }
        // 去除可能的 .html 后缀
        $path = preg_replace('#\.html$#', '', $path);
        // 归一化
        return $path . '.html';
    }

    // 每小时增量：最近 N 小时更新的文章
    protected function enqueueIncrementalPosts(int $hours): void
    {
        try {
            $since = date('Y-m-d H:i:s', time() - $hours * 3600);
            $posts = Post::where('status', 'published')
                ->where('updated_at', '>=', $since)
                ->select(['slug', 'id'])
                ->get();

            foreach ($posts as $post) {
                $keyword = $post->slug ?? $post->id;
                $payload = [
                    'type' => 'url',
                    'value' => '/post/' . $keyword,
                    'options' => ['force' => true],
                ];
                $this->publish($payload);
            }
            Log::info('增量静态化任务入队: ' . count($posts) . ' 篇');
        } catch (\Throwable $e) {
            Log::error('增量入队失败: ' . $e->getMessage());
        }
    }

    // 生产消息
    public function publish(array $data): void
    {
        if (!$this->mqChannel) {
            return;
        }
        $msg = new AMQPMessage(json_encode($data, JSON_UNESCAPED_UNICODE), [
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT
        ]);
        $this->mqChannel->basic_publish($msg, $this->exchange, $this->routingKey);
    }

    public function onWorkerStop(): void
    {
        try {
            if ($this->mqChannel) {
                $this->mqChannel->close();
            }
            if ($this->mqConnection) {
                $this->mqConnection->close();
            }
            Log::info('StaticGenerator MQ连接已关闭');
        } catch (\Throwable $e) {
            Log::warning('关闭MQ连接失败: ' . $e->getMessage());
        }
    }
}