<?php

/**
 * Here is your custom functions.
 */

use app\service\CacheService;
use app\service\MailService;
use app\service\TwigTemplateService;
use Illuminate\Support\Carbon;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use support\Log;
use support\Response;

/**
 * 获取缓存处理器实例
 * 根据环境变量配置动态选择缓存器类型
 *
 * @return object|null 缓存处理器实例
 * @throws Exception
 */
function get_cache_handler(): ?object
{
    return CacheService::getCacheHandler();
}

/**
 * get cache or set cache(and return set value)
 * 获取或设置缓存（并返回设置的值），不存在时返回默认值或false。
 * 输入原始值，输出原始值，内部序列化存储，外部反序列化返回
 * 支持多种缓存器类型：Redis、APCU、Memcached、无缓存模式
 *
 * @param string|null $key   cache key 缓存键
 * @param mixed|null  $value cache value|default 缓存值|默认返回值
 * @param bool        $set   set cache 是否设置缓存
 * @param int|null    $ttl   过期时间（秒），0表示永不过期，null使用默认配置
 *
 * @return mixed
 */
function cache(?string $key = null, mixed $value = null, bool $set = false, ?int $ttl = null): mixed
{
    if (is_null($key)) {
        return new CacheService();
    }

    return CacheService::cache($key, $value, $set, $ttl);
}

/**
 * 获取博客配置，获取所有配置项不需要传参，并且不使用缓存
 * 输入原始值，输出原始值。内部序列化存储，外部反序列化返回
 *
 * @param string     $key       key in database
 * @param mixed|null $default   default value
 * @param bool       $set       set default value to database
 * @param bool       $use_cache use cache
 * @param bool       $init
 *
 * @return mixed
 * @throws Throwable
 */
function blog_config(string $key, mixed $default = null, bool $init = false, bool $use_cache = true, bool $set = false): mixed
{
    $key = trim($key);
    $fullCacheKey = 'blog_config_' . $key;

    // 优先处理写操作
    if ($set) {
        return blog_config_write($key, $fullCacheKey, $default, $use_cache);
    }

    // 读操作主流程
    return blog_config_read($key, $fullCacheKey, $default, $init, $use_cache);
}

/**
 * 处理配置写入操作（单一职责）
 */
function blog_config_write(string $cache_key, string $fullCacheKey, mixed $value, bool $use_cache): mixed
{
    // 边缘模式下不访问数据库，直接写入缓存
    $dbDefault = getenv('DB_DEFAULT');
    if ($dbDefault === 'edge') {
        if ($use_cache && $value !== null) {
            cache($fullCacheKey, $value, true);
        }

        return $value;
    }

    try {
        // 使用 updateOrCreate 确保原子性操作
        $setting = app\model\Setting::updateOrCreate(
            ['key' => $cache_key],
            ['value' => blog_config_convert_to_storage($value)]
        );

        // 更新缓存（不缓存 null，避免后续读到 null）
        if ($use_cache && $value !== null) {
            cache($fullCacheKey, $value, true);
        }

        return $value;
    } catch (Exception|Error $e) {
        Log::error("[blog_config] 写入失败 (key: {$cache_key}): " . $e->getMessage());

        return $value;
    }
}

/**
 * 处理配置读取操作（单一职责）
 */
function blog_config_read(string $cache_key, string $fullCacheKey, mixed $default, bool $init, bool $use_cache): mixed
{
    // 1. 尝试从缓存读取
    if ($use_cache) {
        $cachedValue = cache($fullCacheKey);
        // 将 null 和空字符串视为未命中，避免把 json:null 或 "" 当作有效值返回
        if ($cachedValue !== false) {
            if ($cachedValue === null || (is_string($cachedValue) && trim($cachedValue) === '')) {
                // 命中到空值则清理该键，避免后续误命中
                try {
                    $handler = get_cache_handler();
                    if ($handler) {
                        $handler->del(CacheService::prefixKey($fullCacheKey));
                    }
                } catch (Throwable $e) {
                    Log::warning("[blog_config] cleanup empty cache warn: {$fullCacheKey} - " . $e->getMessage());
                }
            } else {
                Log::debug("[blog_config] cache hit: {$fullCacheKey}");

                return $cachedValue;
            }
        }
        Log::debug("[blog_config] cache miss: {$fullCacheKey}");
    }

    // 2. 从数据库读取
    $dbValue = blog_config_get_from_db($cache_key);
    if ($dbValue !== null) {
        // 缓存数据库结果
        if ($use_cache) {
            cache($fullCacheKey, $dbValue, true);
        }
        Log::debug("[blog_config] db hit: {$cache_key}=" . var_export($dbValue, true));

        return $dbValue;
    }

    // 3. 数据库无记录，处理初始化
    Log::debug("[blog_config] db miss: {$cache_key}, init=" . ($init ? 'true' : 'false') . ', default=' . var_export($default, true));

    return blog_config_handle_init($cache_key, $fullCacheKey, $default, $init, $use_cache);
}

/**
 * 从数据库获取配置并转换（单一职责）
 */
function blog_config_get_from_db(string $cache_key): mixed
{
    // 边缘模式下不访问数据库
    $dbDefault = getenv('DB_DEFAULT');
    if ($dbDefault === 'edge') {
        return null;
    }

    $setting = app\model\Setting::where('key', $cache_key)->first();
    if (!$setting) {
        return null;
    }
    $val = blog_config_convert_from_storage($setting->value);

    // 全局：存储为 json:null 或空字符串，都视为未配置
    if ($val === null) {
        return null;
    }
    if (is_string($val) && trim($val) === '') {
        return null;
    }

    // RabbitMQ 端口必须为有效端口号（1-65535）
    if ($cache_key === 'rabbitmq_port') {
        if (!is_numeric($val)) {
            return null;
        }
        $port = (int) $val;
        if ($port <= 0 || $port > 65535) {
            return null;
        }

        return $port;
    }

    // 其他端口配置项的通用验证
    if (strpos($cache_key, '_port') !== false && $cache_key !== 'rabbitmq_port') {
        if (!is_numeric($val)) {
            return null;
        }
        $port = (int) $val;
        if ($port <= 0 || $port > 65535) {
            return null;
        }

        return $port;
    }

    return $val;
}

/**
 * 处理初始化逻辑（单一职责）
 */
function blog_config_handle_init(string $cache_key, string $fullCacheKey, mixed $default, bool $init, bool $use_cache): mixed
{
    // 为URL模式设置默认值
    if ($cache_key === 'url_mode' && $default === null) {
        $default = 'slug'; // 默认使用slug模式
    }

    if (!$init) {
        return blog_config_normalize_default($default); // 不初始化,直接返回默认值
    }

    $default = blog_config_normalize_default($default);

    // 边缘模式下不访问数据库，直接写入缓存返回默认值
    $dbDefault = getenv('DB_DEFAULT');
    if ($dbDefault === 'edge') {
        if ($use_cache && $default !== null) {
            cache($fullCacheKey, $default, true);
        }

        return $default;
    }

    try {
        // 使用 updateOrCreate 确保原子性,避免并发冲突
        // updateOrCreate 会在数据库层面加锁,避免竞态条件
        $setting = app\model\Setting::updateOrCreate(
            ['key' => $cache_key],
            ['value' => blog_config_convert_to_storage($default)]
        );

        // 写入缓存（不缓存 null）
        if ($use_cache && $default !== null) {
            cache($fullCacheKey, $default, true);
        }

        return $default;
    } catch (Exception|Error $e) {
        // 如果仍然失败（极少情况）,记录日志并返回默认值
        Log::error("[blog_config] 初始化失败 (key: {$cache_key}): " . $e->getMessage());

        // 尝试重新从数据库读取（可能其他进程已成功创建）
        // 添加延迟重试机制以处理并发情况
        try {
            usleep(50000); // 等待50ms,让其他进程完成创建
            $dbValue = blog_config_get_from_db($cache_key);
            if ($dbValue !== null) {
                if ($use_cache) {
                    cache($fullCacheKey, $dbValue, true);
                }

                return $dbValue;
            }
        } catch (Exception $e2) {
            Log::error("[blog_config] 重新读取也失败 (key: {$cache_key}): " . $e2->getMessage());
        }

        return $default;
    }
}

/**
 * 转换值为存储格式（复用逻辑）
 */
function blog_config_convert_to_storage(mixed $value): string
{
    if ($value === null) {
        return json_encode(null, JSON_UNESCAPED_UNICODE);
    }
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $value;
        }
    }

    return json_encode($value, JSON_UNESCAPED_UNICODE);
}

/**
 * 从存储格式转换值（复用逻辑）
 */
function blog_config_convert_from_storage(mixed $value): mixed
{
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
    }

    return $value;
}

/**
 * 归一化默认值：全局不返回 null
 * 当前策略：当 default 为 null 时回退为空字符串 ''
 */
function blog_config_normalize_default(mixed $value): mixed
{
    return $value === null ? '' : $value;
}

/**
 * 获取UTC当前时间（Carbon对象）
 *
 * @return Carbon
 */
function utc_now(): Carbon
{
    return Carbon::now('UTC');
}

/**
 * 获取UTC当前时间字符串
 *
 * @param string $format 格式化模板
 *
 * @return string
 */
function utc_now_string(string $format = 'Y-m-d H:i:s'): string
{
    return Carbon::now('UTC')->format($format);
}

/**
 * 解析UTC时间字符串为Carbon对象
 *
 * @param string|null $time 时间字符串
 *
 * @return Carbon|null
 */
function utc_parse(?string $time): ?Carbon
{
    if (empty($time)) {
        return null;
    }

    return Carbon::parse($time, 'UTC');
}

/**
 * 格式化日期时间（已废弃，使用 utc_now_string 或 Carbon）
 *
 * @param string $time   时间字符串
 * @param string $format 格式化模板
 *
 * @return string
 * @deprecated 使用 utc_now_string() 或 Carbon::now('UTC')->format()
 */
function format_time(string $time, string $format = 'Y-m-d H:i:s'): string
{
    // 修复：强制按UTC解析时间
    $carbon = Carbon::parse($time, 'UTC');

    return $carbon->format($format);
}

/**
 * 格式化文件大小
 *
 * @param int $bytes 文件大小（字节）
 *
 * @return string
 */
function format_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    } elseif ($bytes < 1048576) {
        return round($bytes / 1024, 2) . ' KB';
    } elseif ($bytes < 1073741824) {
        return round($bytes / 1048576, 2) . ' MB';
    } else {
        return round($bytes / 1073741824, 2) . ' GB';
    }
}

/**
 * 生成随机字符串
 *
 * @param int    $length 字符串长度
 * @param string $type   字符串类型：'number', 'letter', 'mix'
 *
 * @return string
 */
function random_string(int $length = 10, string $type = 'mix'): string
{
    $numberChars = '0123456789';
    $letterChars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $mixChars = $numberChars . $letterChars;

    switch ($type) {
        case 'number':
            $chars = $numberChars;
            break;
        case 'letter':
            $chars = $letterChars;
            break;
        case 'mix':
        default:
            $chars = $mixChars;
            break;
    }

    $result = '';
    $charsLength = strlen($chars);
    for ($i = 0; $i < $length; $i++) {
        $result .= $chars[rand(0, $charsLength - 1)];
    }

    return $result;
}

/**
 * 发布静态化任务到 RabbitMQ（供后台“手动刷新缓存”等调用）
 * 期望数据格式示例：
 *  - ['type' => 'scope', 'value' => 'all', 'options' => ['pages' => 50, 'force' => true]]
 *  - ['type' => 'url', 'value' => '/link', 'options' => ['force' => true]]
 *
 * @param array $data
 *
 * @return bool 发布是否成功
 */
function publish_static(array $data): bool
{
    $conn = null;
    $ch = null;
    try {
        $host = (string) blog_config('rabbitmq_host', '127.0.0.1', true);
        $port = (int) blog_config('rabbitmq_port', 5672, true);
        $user = (string) blog_config('rabbitmq_user', 'guest', true);
        $pass = (string) blog_config('rabbitmq_password', 'guest', true);
        $vhost = (string) blog_config('rabbitmq_vhost', '/', true);

        $exchange = (string) blog_config('rabbitmq_static_exchange', 'static_exchange', true);
        $routingKey = (string) blog_config('rabbitmq_static_routing_key', 'static_routing', true);
        $queueName = (string) blog_config('rabbitmq_static_queue', 'static_queue', true);

        $dlxExchange = (string) blog_config('rabbitmq_static_dlx_exchange', 'static_dlx', true);
        $dlxQueue = (string) blog_config('rabbitmq_static_dlx_queue', 'static_dlx_queue', true);

        $conn = new AMQPStreamConnection($host, $port, $user, $pass, $vhost);
        $ch = $conn->channel();

        // 声明死信交换机与队列
        $ch->exchange_declare($dlxExchange, 'direct', false, true, false);
        $ch->queue_declare($dlxQueue, false, true, false, false);
        $ch->queue_bind($dlxQueue, $dlxExchange, $dlxQueue);

        // 主交换机
        $ch->exchange_declare($exchange, 'direct', false, true, false);

        // 队列（带死信参数，尽量与 StaticGenerator 保持一致）
        $args = new AMQPTable([
            'x-dead-letter-exchange' => $dlxExchange,
            'x-dead-letter-routing-key' => $dlxQueue,
            'x-max-priority' => 10, // 开启队列优先级（0-9）
        ]);
        try {
            $ch->queue_declare($queueName, false, true, false, false, false, $args);
        } catch (Throwable $e) {
            // 兼容不支持参数的场景，回退无参声明
            Log::warning('publish_static 队列声明失败，尝试无参重建: ' . $e->getMessage());
            // 如果是因为参数不匹配导致的错误，则删除队列后重新声明
            if (strpos($e->getMessage(), 'inequivalent arg') !== false) {
                try {
                    // 删除已存在的队列
                    $ch->queue_delete($queueName);
                    // 重新声明队列
                    $ch->queue_declare($queueName, false, true, false, false, false, $args);
                } catch (Throwable $e2) {
                    Log::error('publish_static 队列重建失败: ' . $e2->getMessage());
                    throw $e2;
                }
            } else {
                // 其他错误则尝试无参声明
                try {
                    $ch->queue_declare($queueName, false, true, false, false, false);
                } catch (Throwable $e3) {
                    Log::error('publish_static 队列无参重建失败: ' . $e3->getMessage());
                    throw $e3;
                }
            }
        }
        $ch->queue_bind($queueName, $exchange, $routingKey);

        // 发布消息
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE);
        $msg = new AMQPMessage($payload, [
            'content_type' => 'application/json',
            'delivery_mode' => 2, // 持久化
        ]);
        $ch->basic_publish($msg, $exchange, $routingKey);

        return true;
    } catch (Throwable $e) {
        Log::error('publish_static 失败: ' . $e->getMessage());

        return false;
    } finally {
        // 确保资源被正确关闭
        try {
            if ($ch !== null) {
                $ch->close();
            }
        } catch (Throwable $e) {
            Log::warning('publish_static 关闭通道失败: ' . $e->getMessage());
        }
        try {
            if ($conn !== null) {
                $conn->close();
            }
        } catch (Throwable $e) {
            Log::warning('publish_static 关闭连接失败: ' . $e->getMessage());
        }
    }
}

/**
 * 渲染邮件 HTML（返回字符串而非 Response）
 */
function mail_view(mixed $template = null, array $vars = [], ?string $app = null, ?string $plugin = null): string
{
    return TwigTemplateService::render((string) $template, $vars, $app, $plugin);
}

/**
 * 统一发信入口：将邮件任务入队到 RabbitMQ，由 MailWorker 异步发送
 *
 * 用法示例：
 *  - sendmail('user@example.com', '欢迎', '<b>Hello</b>');
 *  - sendmail(['a@x.com', 'b@y.com'], '通知', ['text' => '纯文本'], ['cc' => ['c@z.com']]);
 *  - sendmail('user@example.com', '模板渲染', ['view' => 'emails/welcome', 'view_vars' => ['name' => '张三']]);
 *
 * @param string|array      $to               收件人：字符串或数组（支持 ['email'=>'','name'=>'']）
 * @param string            $subject          主题
 * @param string|array|null $contentOrOptions 第三参为字符串时视为 html；为数组时与 $options 合并
 * @param array             $options          其他选项（headers, attachments, cc, bcc, text, view, view_vars,
 *                                            inline_template, inline_vars, priority）
 *
 * @return bool 入队是否成功
 */
function sendmail(string|array $to, string $subject, string|array|null $contentOrOptions = null, array $options = []): bool
{
    $payload = [
        'to' => $to,
        'subject' => $subject,
    ];

    // 兼容第三参数：字符串 => 作为 HTML；数组 => 与 options 合并
    if (is_string($contentOrOptions) && $contentOrOptions !== '') {
        $payload['html'] = $contentOrOptions;
    } elseif (is_array($contentOrOptions)) {
        $options = array_merge($contentOrOptions, $options);
    }

    // 允许的可选键
    $allowedKeys = [
        'html', 'text',
        'headers', 'attachments',
        'cc', 'bcc',
        'view', 'view_vars',
        'inline_template', 'inline_vars',
        'from_name', 'reply_to',
        'priority',
    ];
    foreach ($allowedKeys as $k) {
        if (array_key_exists($k, $options)) {
            $payload[$k] = $options[$k];
        }
    }

    // 默认优先级：normal（5）
    if (!array_key_exists('priority', $payload)) {
        $payload['priority'] = 'normal';
    }

    // 委托入队，由 MailWorker 异步发送
    return MailService::enqueue($payload);
}

/**
 * 快捷函数：发送HTML邮件
 */
function sendhtml(string|array $to, string $subject, string $html, array $options = []): bool
{
    return sendmail($to, $subject, $html, $options);
}

/**
 * 快捷函数：发送纯文本邮件
 */
function sendtext(string|array $to, string $subject, string $text, array $options = []): bool
{
    $opts = array_merge($options, ['text' => $text, 'html' => '']);

    return sendmail($to, $subject, null, $opts);
}

if (!function_exists('is_installed')) {
    /**
     * 检测系统是否已安装
     * 生产环境优先检查环境变量（如 DB_DEFAULT），使用 install.lock 作为后备方案
     *
     * @return bool
     */
    function is_installed(): bool
    {
        // 优先检查环境变量：如果设置了 DB_DEFAULT 或其他关键数据库配置，视为已安装
        $dbDefault = getenv('DB_DEFAULT');
        if ($dbDefault !== false && !empty($dbDefault)) {
            // 检查对应数据库的关键配置是否存在
            switch ($dbDefault) {
                case 'mysql':
                    $dbHost = getenv('DB_MYSQL_HOST');
                    if ($dbHost !== false && !empty($dbHost)) {
                        return true;
                    }
                    break;
                case 'pgsql':
                    $dbHost = getenv('DB_PGSQL_HOST');
                    if ($dbHost !== false && !empty($dbHost)) {
                        return true;
                    }
                    break;
                case 'sqlite':
                    $dbPath = getenv('DB_SQLITE_DATABASE');
                    if ($dbPath !== false && !empty($dbPath)) {
                        return true;
                    }
                    break;
            }
        }

        // 后备方案：检查 install.lock 文件
        $lockFile = base_path() . '/runtime/install.lock';
        if (file_exists($lockFile)) {
            return true;
        }

        return false;
    }
}

if (!function_exists('container_info')) {
    /**
     * 检查当前环境是否在Docker或K8s中运行
     * 优先检查IN_CONTAINER环境变量，再验证系统特征
     *
     * @return array 包含是否在容器、是否在Docker、是否在K8s的结果
     *               'in_container' => bool, 是否在容器环境
     *               'in_docker'    => bool, 是否在Docker环境
     *               'in_k8s'      => bool, 是否在K8s环境
     */
    function container_info()
    {
        // 检查IN_CONTAINER环境变量（默认值为true）
        $inContainerEnv = getenv('IN_CONTAINER');
        if ($inContainerEnv !== false) {
            // 转换字符串为布尔值（支持true/false/1/0/yes/no，不区分大小写）
            $lowerEnv = strtolower(trim($inContainerEnv));
            $inContainerEnv = in_array($lowerEnv, ['true', '1', 'yes'], true);
        }

        // 检查Docker特征
        $isDocker = false;
        // 检查/.dockerenv文件
        if (file_exists('/.dockerenv')) {
            $isDocker = true;
        } // 检查/proc/1/cgroup是否包含docker关键词
        elseif (file_exists('/proc/1/cgroup') && str_contains(file_get_contents('/proc/1/cgroup'), 'docker')) {
            $isDocker = true;
        }

        // 检查K8s特征（K8s基于Docker，需先满足Docker特征或环境变量）
        $isK8s = false;
        if (($inContainerEnv || $isDocker) && is_dir('/var/run/secrets/kubernetes.io/serviceaccount/')) {
            // 检查K8s服务账户文件（ca.crt、token、namespace）
            $requiredFiles = ['ca.crt', 'token', 'namespace'];
            $hasAllFiles = true;
            foreach ($requiredFiles as $file) {
                // 缺失任意一个文件都认为不是K8s环境
                if (!file_exists("/var/run/secrets/kubernetes.io/serviceaccount/{$file}")) {
                    $hasAllFiles = false;
                    break;
                }
            }
            if ($hasAllFiles) {
                $isK8s = true;
            }
        }

        // 综合判断结果
        $result['in_docker'] = $isDocker;
        $result['in_k8s'] = $isK8s;
        // 容器环境：满足环境变量为true，或在Docker/K8s中
        $result['in_container'] = $inContainerEnv || $isDocker || $isK8s;

        return $result;
    }
}

if (!function_exists('is_install_lock_exists')) {
    /**
     * 检查安装锁文件是否存在
     *
     * @return bool
     */
    function is_install_lock_exists(): bool
    {
        return file_exists(runtime_path('install.lock'));
    }
}

if (!function_exists('is_install_tmp_lock_exists')) {
    /**
     * 检查安装锁文件是否存在
     *
     * @return bool
     */
    function is_install_tmp_lock_exists(): bool
    {
        return file_exists(runtime_path('install_tmp.lock'));
    }
}

if (!function_exists('view_error')) {
    /**
     * View response
     *
     * @param mixed       $template
     * @param array       $vars
     * @param int         $code
     * @param string|null $app
     * @param string|null $plugin
     *
     * @return Response
     */
    function view_error(mixed $template = null, array $vars = [], int $code = 500, ?string $app = null, ?string $plugin = null): Response
    {
        [$template, $vars, $app, $plugin] = template_inputs($template, $vars, $app, $plugin);
        $handler = config($plugin ? "plugin.$plugin.view.handler" : 'view.handler');

        return new Response($code, [], $handler::render($template, $vars, $app, $plugin));
    }
}

if (!function_exists('json_error')) {
    /**
     * Json response
     *
     * @param     $data
     * @param int $code
     * @param int $options
     *
     * @return Response
     */
    function json_error($data, int $code = 500, int $options = JSON_UNESCAPED_UNICODE): Response
    {
        return new Response($code, ['Content-Type' => 'application/json'], json_encode($data, $options));
    }
}

if (!function_exists('get_windblog_version')) {
    /**
     * 获取WindBlog版本信息
     *
     * @return array|false
     */
    function get_windblog_version()
    {
        $versionFile = base_path() . '/version.json';

        if (!file_exists($versionFile)) {
            return false;
        }

        $content = file_get_contents($versionFile);
        if ($content === false) {
            return false;
        }

        $versionInfo = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }

        return $versionInfo;
    }
}
