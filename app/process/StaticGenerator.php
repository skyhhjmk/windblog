<?php

namespace app\process;

use app\model\Post;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use support\Log;

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

    // MQ 命名
    private string $exchange = 'windblog_static_gen';

    private string $routingKey = 'static_gen';

    private string $queueName = 'windblog_static_queue';

    private string $dlxExchange = 'windblog_static_dlx';

    private string $dlxQueue = 'windblog_static_dlq';

    public function onWorkerStart(): void
    {
        // 检查系统是否已安装
        if (!is_installed()) {
            Log::warning('StaticGenerator 检测到系统未安装，已跳过启动');

            return;
        }

        $this->initMq();
        $this->startConsumer();

        // 周期增量：每小时执行一次
        if (class_exists(\Workerman\Timer::class)) {
            \Workerman\Timer::add(3600, function () {
                $this->enqueueIncrementalPosts(24);
            });
            // 每60秒进行一次 MQ 健康检查
            \Workerman\Timer::add(60, function () {
                try {
                    \app\service\MQService::checkAndHeal();
                } catch (\Throwable $e) {
                    \support\Log::warning('MQ 健康检查异常(StaticGenerator): ' . $e->getMessage());
                }
            });
        }
    }

    protected function initMq(): void
    {
        try {
            // 使用 MQService 通道
            $this->mqChannel = \app\service\MQService::getChannel();

            // 允许通过配置覆盖命名
            $this->exchange = (string) blog_config('rabbitmq_static_exchange', $this->exchange, true) ?: $this->exchange;
            $this->routingKey = (string) blog_config('rabbitmq_static_routing_key', $this->routingKey, true) ?: $this->routingKey;
            $this->queueName = (string) blog_config('rabbitmq_static_queue', $this->queueName, true) ?: $this->queueName;
            $this->dlxExchange = (string) blog_config('rabbitmq_static_dlx_exchange', $this->dlxExchange, true) ?: $this->dlxExchange;
            $this->dlxQueue = (string) blog_config('rabbitmq_static_dlx_queue', $this->dlxQueue, true) ?: $this->dlxQueue;

            // 使用 MQService 的通用初始化（专属 DLX/DLQ）
            \app\service\MQService::declareDlx($this->mqChannel, $this->dlxExchange, $this->dlxQueue);
            \app\service\MQService::setupQueueWithDlx($this->mqChannel, $this->exchange, $this->routingKey, $this->queueName, $this->dlxExchange, $this->dlxQueue);

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
        $this->mqChannel->basic_qos(0, 1, null);
        $this->mqChannel->basic_consume($this->queueName, '', false, false, false, false, function (AMQPMessage $message) {
            $this->handleMessage($message);
        });
        // 在 worker 循环中轮询消费
        if (class_exists(\Workerman\Timer::class)) {
            \Workerman\Timer::add(1, function () {
                if ($this->mqChannel) {
                    try {
                        // 增大超时时间并忽略超时异常
                        $this->mqChannel->wait(null, false, 1.0);
                    } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
                        // 无数据到达的正常超时，忽略
                    } catch (\Throwable $e) {
                        Log::warning('StaticGenerator 消费轮询异常: ' . $e->getMessage());
                    }
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
            $force = (bool) ($options['force'] ?? false);
            $jobId = (string) ($options['job_id'] ?? ('auto_' . date('Ymd_His')));

            if ($type === 'url') {
                $url = (string) $payload['value'];
                $this->generateByUrl($url, $force, $jobId);
            } elseif ($type === 'scope') {
                $scope = (string) $payload['value'];
                $pages = (int) ($options['pages'] ?? 1);
                $this->generateByScope($scope, $pages, $force, $jobId);
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
            $native = method_exists($headers, 'getNativeData') ? $headers->getNativeData() : (array) $headers;
            $retry = (int) ($native['x-retry-count'] ?? 0);
        }
        if ($retry < 2) { // 第1、2次失败重试；第3次进入死信
            $newHeaders = $headers ? clone $headers : new \PhpAmqpLib\Wire\AMQPTable();
            $newHeaders->set('x-retry-count', $retry + 1);
            $newMsg = new AMQPMessage($message->getBody(), [
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'application_headers' => $newHeaders,
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

    // 生成：按URL（HTTP自调用）
    protected function generateByUrl(string $url, bool $force = false, ?string $jobId = null): void
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        if ($jobId) {
            $this->progressStart($jobId, 'url', 1);
        }
        [$code, $body] = $this->httpFetch($path);
        $this->writeHtml($path, $code, $body, $force);
        if ($jobId) {
            $this->progressTick($jobId, 1, $path);
            $this->progressFinish($jobId);
        }
    }

    // 生成：按范围（带进度）
    protected function generateByScope(string $scope, int $pages = 1, bool $force = false, ?string $jobId = null): void
    {
        // 估算总数
        $total = 0;
        if ($scope === 'index') {
            $total = max(1, $pages) + 1; // /page/1..N + /
        } elseif ($scope === 'list') {
            $total = max(1, $pages) + 1; // /link/page/1..N + /link
        } elseif ($scope === 'post') {
            $total = (int) Post::where('status', 'published')->count('*');
        } elseif ($scope === 'all') {
            $total = (max(1, $pages) + 1) // index
                   + (max(1, $pages) + 1) // list
                   + (int) Post::where('status', 'published')->count('*')
                   + 1; // /search
        }
        if ($jobId) {
            $this->progressStart($jobId, $scope, $total);
        }

        $done = 0;
        switch ($scope) {
            case 'index':
                for ($p = 1; $p <= $pages; $p++) {
                    $path = "/page/$p";
                    [$code, $body] = $this->httpFetch($path);
                    $this->writeHtml($path, $code, $body, $force);
                    $done++;
                    if ($jobId) {
                        $this->progressTick($jobId, $done, $path);
                    }
                }
                $path = '/';
                [$code, $body] = $this->httpFetch($path);
                $this->writeHtml($path, $code, $body, $force);
                $done++;
                if ($jobId) {
                    $this->progressTick($jobId, $done, $path);
                }
                break;
            case 'list':
                for ($p = 1; $p <= $pages; $p++) {
                    $path = "/link/page/$p";
                    [$code, $body] = $this->httpFetch($path);
                    $this->writeHtml($path, $code, $body, $force);
                    $done++;
                    if ($jobId) {
                        $this->progressTick($jobId, $done, $path);
                    }
                }
                $path = '/link';
                [$code, $body] = $this->httpFetch($path);
                $this->writeHtml($path, $code, $body, $force);
                $done++;
                if ($jobId) {
                    $this->progressTick($jobId, $done, $path);
                }
                break;
            case 'post':
                $posts = Post::where('status', 'published')->select(['slug', 'id'])->get();
                foreach ($posts as $post) {
                    $keyword = $post->slug ?? $post->id;
                    $path = "/post/$keyword";
                    [$code, $body] = $this->httpFetch($path);
                    $this->writeHtml($path, $code, $body, $force);
                    $done++;
                    if ($jobId) {
                        $this->progressTick($jobId, $done, $path);
                    }
                }
                break;
            case 'all':
                $this->generateByScope('index', $pages, $force, $jobId);
                $this->generateByScope('list', $pages, $force, $jobId);
                $this->generateByScope('post', $pages, $force, $jobId);
                $path = '/search';
                [$code, $body] = $this->httpFetch($path);
                $this->writeHtml($path, $code, $body, $force);
                $done++;
                if ($jobId) {
                    $this->progressTick($jobId, $done, $path);
                }
                break;
            default:
                Log::warning('未知静态化范围: ' . $scope);
        }

        if ($jobId) {
            $this->progressFinish($jobId);
        }
    }

    protected function writeHtml(string $urlPath, int $code, string $body, bool $force = false): void
    {
        if ($code !== 200) {
            Log::warning("渲染非200，跳过: {$urlPath}, code={$code}");

            return;
        }

        // 按策略决定是否做JS/CSS压缩替换（若库缺失则自动跳过）
        $body = $this->maybeMinifyHtml($urlPath, $body);

        $target = $this->mapPath($urlPath);

        // 目录：正式与临时
        $final = public_path() . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'static' . DIRECTORY_SEPARATOR . $target;
        $stage = public_path() . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'static_tmp' . DIRECTORY_SEPARATOR . $target;

        // 确保目录存在
        $finalDir = dirname($final);
        $stageDir = dirname($stage);
        if (!is_dir($finalDir)) {
            @mkdir($finalDir, 0o775, true);
        }
        if (!is_dir($stageDir)) {
            @mkdir($stageDir, 0o775, true);
        }

        // 1) 将页面写入临时目录文件
        if (file_exists($stage)) {
            @unlink($stage);
        }
        file_put_contents($stage, $body);

        // 2) 若非强制且正式文件已存在，则保留旧缓存，丢弃临时文件
        if (!$force && file_exists($final)) {
            @unlink($stage);

            return;
        }

        // 3) 删除旧正式文件（若有），再将临时文件移动到正式目录
        if (file_exists($final)) {
            @unlink($final);
        }
        // 跨目录移动（同盘rename即为移动）
        @rename($stage, $final);

        Log::info("静态页面生成(临时->正式，逐页替换): {$final}");
    }

    /**
     * 若URL策略启用minify，则对HTML中的本地JS/CSS进行压缩与引用改写
     * - 需要 matthiasmullie/minify 库；若不存在则跳过并记录日志
     */
    protected function maybeMinifyHtml(string $urlPath, string $html): string
    {
        try {
            $strategies = (array) (blog_config('static_url_strategies', [], true) ?: []);
            $needMinify = false;
            $pathVariants = [$urlPath, rtrim($urlPath, '/'), $this->mapPath($urlPath)];
            foreach ($strategies as $it) {
                $u = (string) ($it['url'] ?? '');
                if (!$u) {
                    continue;
                }
                // 允许匹配 /path 与 /path.html 两种写法
                if (in_array($u, $pathVariants, true) || $u === $urlPath) {
                    if (!empty($it['enabled']) && !empty($it['minify'])) {
                        $needMinify = true;
                    }
                    break;
                }
            }
            if (!$needMinify) {
                return $html;
            }

            if (!class_exists(\MatthiasMullie\Minify\JS::class) || !class_exists(\MatthiasMullie\Minify\CSS::class)) {
                Log::warning('minify库未安装，已跳过JS/CSS压缩（composer require matthiasmullie/minify）');

                return $html;
            }

            // 仅处理本地资源：/ 开头的 src/href
            $public = rtrim((string) public_path(), DIRECTORY_SEPARATOR);
            $minBaseUrl = '/assets/min';
            $minBaseDir = $public . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'min';
            if (!is_dir($minBaseDir)) {
                @mkdir($minBaseDir, 0o775, true);
            }

            // 替换 <script src="..."> 与 <link rel="stylesheet" href="...">
            $replaced = $html;

            // JS
            $replaced = preg_replace_callback('#(<script[^>]+src=["\'])(/[^"\']+?\.js)(["\'][^>]*>\s*</script>)#i', function ($m) use ($public, $minBaseDir, $minBaseUrl) {
                $src = $m[2];
                $srcPath = $public . str_replace('/', DIRECTORY_SEPARATOR, $src);
                if (!is_file($srcPath)) {
                    return $m[0];
                }

                $hash = md5($srcPath . '|' . (string) @filemtime($srcPath));
                $outRel = $minBaseUrl . '/' . $hash . '.js';
                $outPath = $minBaseDir . DIRECTORY_SEPARATOR . $hash . '.js';

                if (!file_exists($outPath)) {
                    try {
                        $minifier = new \MatthiasMullie\Minify\JS($srcPath);
                        $minifier->minify($outPath);
                    } catch (\Throwable $e) {
                        Log::warning('JS压缩失败，回退原始: ' . $e->getMessage());

                        return $m[0];
                    }
                }

                return $m[1] . $outRel . $m[3];
            }, $replaced);

            // CSS
            $replaced = preg_replace_callback('#(<link[^>]+href=["\'])(/[^"\']+?\.css)(["\'][^>]*>)#i', function ($m) use ($public, $minBaseDir, $minBaseUrl) {
                $href = $m[2];
                $hrefPath = $public . str_replace('/', DIRECTORY_SEPARATOR, $href);
                if (!is_file($hrefPath)) {
                    return $m[0];
                }

                $hash = md5($hrefPath . '|' . (string) @filemtime($hrefPath));
                $outRel = $minBaseUrl . '/' . $hash . '.css';
                $outPath = $minBaseDir . DIRECTORY_SEPARATOR . $hash . '.css';

                if (!file_exists($outPath)) {
                    try {
                        $minifier = new \MatthiasMullie\Minify\CSS($hrefPath);
                        $minifier->minify($outPath);
                    } catch (\Throwable $e) {
                        Log::warning('CSS压缩失败，回退原始: ' . $e->getMessage());

                        return $m[0];
                    }
                }

                return $m[1] . $outRel . $m[3];
            }, $replaced);

            return $replaced;
        } catch (\Throwable $e) {
            Log::warning('HTML压缩替换异常，已回退原始: ' . $e->getMessage());

            return $html;
        }
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

    // 计算基础访问地址：优先 static_base_url，其次 scheme://host[:port]
    protected function getBaseUrl(): string
    {
        $base = (string) blog_config('static_base_url', '', true);
        if ($base !== '') {
            return rtrim($base, '/');
        }
        $scheme = (string) blog_config('site_scheme', 'http', true);
        $host = (string) blog_config('site_host', '127.0.0.1', true);
        $port = (int) blog_config('site_port', 8787, true);
        // 常见端口省略
        $portPart = ($port === 80 && $scheme === 'http') || ($port === 443 && $scheme === 'https') ? '' : ':' . $port;

        return $scheme . '://' . $host . $portPart;
    }

    // 通过 HTTP 获取页面内容（返回 [code, body]）
    protected function httpFetch(string $path): array
    {
        $url = $this->getBaseUrl() . (str_starts_with($path, '/') ? $path : '/' . $path);
        $ch = curl_init();
        $headers = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'User-Agent: StaticGenerator/1.0',
        ];
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            Log::warning("HTTP 获取失败: {$url}, error={$err}");

            return [0, ''];
        }
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [$code, (string) $body];
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

            // 同步刷新常用页面（首页与友链页 + 分页2-5）
            $this->publish([
                'type' => 'url',
                'value' => '/',
                'options' => ['force' => true],
            ]);
            $this->publish([
                'type' => 'url',
                'value' => '/link',
                'options' => ['force' => true],
            ]);

            Log::info('增量静态化任务入队: ' . count($posts) . ' 篇（含首页/友链及其分页2-5刷新）');
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
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        ]);
        $this->mqChannel->basic_publish($msg, $this->exchange, $this->routingKey);
    }

    // 进度：开始
    protected function progressStart(string $jobId, string $scope, int $total): void
    {
        $data = [
            'job_id' => $jobId,
            'scope' => $scope,
            'total' => max(0, $total),
            'current' => 0,
            'status' => 'running',
            'started_at' => time(),
            'updated_at' => time(),
            'current_path' => '',
        ];
        // 将最新任务ID与详情写入缓存，延长TTL以便短任务仍可被查询到
        cache('static_progress_latest', $jobId, true, 3600);
        cache('static_progress_' . $jobId, $data, true, 900);
    }

    // 进度：步进
    protected function progressTick(string $jobId, int $current, string $path): void
    {
        $key = 'static_progress_' . $jobId;
        $data = cache($key) ?: [];
        $data['current'] = $current;
        $data['updated_at'] = time();
        $data['current_path'] = $path;
        // 步进更新，刷新TTL
        cache($key, $data, true, 900);
    }

    // 进度：结束
    protected function progressFinish(string $jobId): void
    {
        $key = 'static_progress_' . $jobId;
        $data = cache($key) ?: [];
        $data['status'] = 'finished';
        $data['finished_at'] = time();
        $data['updated_at'] = time();
        // 保持完成后的进度条可查询一段时间（15分钟）
        cache($key, $data, true, 900);

        // 写入历史列表（保留最近10条）
        $histKey = 'static_progress_history';
        $history = cache($histKey) ?: [];
        $summary = [
            'job_id' => $data['job_id'] ?? $jobId,
            'scope' => $data['scope'] ?? '',
            'total' => $data['total'] ?? 0,
            'finished_at' => $data['finished_at'] ?? time(),
            'duration' => isset($data['started_at']) ? (($data['finished_at'] ?? time()) - $data['started_at']) : null,
            'status' => $data['status'] ?? 'finished',
        ];
        // 将最新记录插入列表头部
        array_unshift($history, $summary);
        // 仅保留最近10条
        if (count($history) > 10) {
            $history = array_slice($history, 0, 10);
        }
        // 历史列表保留时长更久（1小时）
        cache($histKey, $history, true, 3600);
    }

    public function onWorkerStop(): void
    {
        try {
            if ($this->mqChannel) {
                $this->mqChannel->close();
            }
            // 统一通过 MQService 关闭
            \app\service\MQService::closeConnection();
            Log::info('StaticGenerator MQ连接已关闭');
        } catch (\Throwable $e) {
            Log::warning('关闭MQ连接失败: ' . $e->Message());
        }
    }
}
