<?php

namespace app\middleware;

use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Redis\Events\CommandExecuted;
use RuntimeException;
use support\Context;
use support\Db;
use support\Log;
use support\Redis;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 请求日志记录中间件
 * - 数据库查询记录为 debug 级别（仅支持 Illuminate/Database）
 * - 请求耗时可配置为 info/debug 级别
 * - debug 模式下支持堆栈追踪
 * - 支持链路追踪和助记词格式 trace ID
 */
class RequestLogger implements MiddlewareInterface
{
    /**
     * @param Request  $request
     * @param callable $handler
     *
     * @return Response
     */
    public function process(Request $request, callable $handler): Response
    {
        static $initialized_db;

        $conf = config('request_logger', []);

        // 跳过配置的模块
        if (!empty($conf['dontReport']['app']) && is_array($conf['dontReport']['app']) && in_array($request->app, $conf['dontReport']['app'], true)) {
            return $handler($request);
        }

        // 跳过配置的path
        if (!empty($conf['dontReport']['path']) && is_array($conf['dontReport']['path'])) {
            $requestPath = $request->path();
            foreach ($conf['dontReport']['path'] as $_path) {
                if (strpos($requestPath, $_path) === 0) {
                    return $handler($request);
                }
            }
        }

        // 跳过配置的控制器日志记录
        if (!empty($conf['dontReport']['controller']) && is_array($conf['dontReport']['controller']) && in_array($request->controller, $conf['dontReport']['controller'], true)) {
            return $handler($request);
        }

        // 跳过配置的方法
        if (!empty($conf['dontReport']['action']) && is_array($conf['dontReport']['action'])) {
            foreach ($conf['dontReport']['action'] as $_action) {
                if ($_action[0] === $request->controller && $_action[1] === $request->action) {
                    return $handler($request);
                }
            }
        }

        // 请求开始时间
        $start_time = microtime(true);

        // 生成或获取 trace ID（链路追踪标识）
        $traceId = $this->getTraceId($request);

        // 将 trace ID 存储到上下文中，供其他日志使用
        Context::get()->traceId = $traceId;
        Context::get()->requestLoggerSqlLogs = '';
        Context::get()->requestLoggerRedisLogs = '';
        Context::get()->requestLoggerTraceLogs = [];

        // 是否启用 debug 模式
        $debugMode = $conf['debug_mode'] ?? false;

        // 记录请求开始
        $this->addTraceLog('REQUEST_START', [
            'ip' => $request->getRealIp(),
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'path' => $request->path(),
            'headers' => $this->getSafeHeaders($request),
            'query' => $request->get(),
        ]);

        // 记录中间件和初始化
        $this->addTraceLog('MIDDLEWARE_INIT', ['debug_mode' => $debugMode]);

        // 初始化数据库监听
        if (!$initialized_db) {
            $initialized_db = true;
            $this->initDbListen();
            $this->addTraceLog('DB_LISTENER_INIT', ['status' => 'initialized']);
        }

        // 记录业务处理开始
        $this->addTraceLog('BUSINESS_START', [
            'controller' => $request->controller ?? 'unknown',
            'action' => $request->action ?? 'unknown',
        ]);

        // 得到响应
        $response = $handler($request);
        $time_diff = round((microtime(true) - $start_time) * 1000, 2);

        // 记录业务处理结束
        $this->addTraceLog('BUSINESS_END', [
            'status_code' => method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 200,
            'duration_ms' => $time_diff,
        ]);

        // 请求耗时日志级别（可配置为 info 或 debug）
        $timingLogLevel = $conf['timing_log_level'] ?? 'info';
        $logChannel = $conf['channel'] ?? 'default';

        // 构建基础日志（带 trace ID）
        $logPrefix = "[{$traceId}]";
        $timingLog = $logPrefix . ' ' . $request->getRealIp() . ' ' . $request->method() . ' ' . trim($request->fullUrl(), '/') . " [{$time_diff}ms]" . PHP_EOL;

        // 记录 POST 数据
        if ($request->method() === 'POST') {
            $postData = $request->post();
            // 脱敏处理
            $postData = $this->sanitizeData($postData);
            $timingLog .= $logPrefix . " [POST]\t" . var_export($postData, true) . PHP_EOL;
        }

        // 获取 SQL 日志（添加 trace ID 前缀）
        $sqlLogs = Context::get()->requestLoggerSqlLogs ?? '';
        if (!empty($sqlLogs)) {
            $sqlLogs = $this->addPrefixToLogs($sqlLogs, $logPrefix);
        }

        // 获取 Redis 日志（添加 trace ID 前缀）
        $redisLogs = Context::get()->requestLoggerRedisLogs ?? '';
        if (!empty($redisLogs)) {
            $redisLogs = $this->addPrefixToLogs($redisLogs, $logPrefix);
        }

        // 初始化 redis 监听
        $new_names = $this->tryInitRedisListen();
        foreach ($new_names as $name) {
            Context::get()->requestLoggerRedisLogs = (Context::get()->requestLoggerRedisLogs ?? '') . "[Redis]\t[connection:{$name}] initialized..." . PHP_EOL;
        }

        // 判断业务是否出现异常
        $exception = null;
        if (method_exists($response, 'exception')) {
            $exception = $response->exception();
        }

        // 记录异常
        $exceptionLog = '';
        if ($exception && ($conf['exception']['enable'] ?? true) && !$this->shouldntReport($exception)) {
            $this->addTraceLog('EXCEPTION', [
                'type' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);
            $exceptionLog = $logPrefix . ' ' . $exception . PHP_EOL;
        }

        // 判断 Db 是否有未提交的事务
        $has_uncommitted_transaction = false;
        $transactionLog = '';
        if (class_exists(Connection::class, false)) {
            if ($log = $this->checkDbUncommittedTransaction()) {
                $has_uncommitted_transaction = true;
                $transactionLog .= $this->addPrefixToLogs($log, $logPrefix);
                $this->addTraceLog('TRANSACTION_ERROR', ['type' => 'db', 'status' => 'uncommitted_rollback']);
            }
        }

        // 生成链路追踪日志
        $traceLog = $this->buildTraceLog($logPrefix);

        // debug 模式下添加堆栈追踪
        $stackTraceLog = '';
        if ($debugMode) {
            $stackTrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            $stackTraceLog = $logPrefix . ' [STACK_TRACE]' . PHP_EOL;
            foreach ($stackTrace as $index => $trace) {
                $file = $trace['file'] ?? 'unknown';
                $line = $trace['line'] ?? '?';
                $function = $trace['function'] ?? '';
                $class = $trace['class'] ?? '';
                $type = $trace['type'] ?? '';
                $stackTraceLog .= $logPrefix . sprintf(' #%d %s%s%s() called at [%s:%s]', $index, $class, $type, $function, $file, $line) . PHP_EOL;
            }
        }

        // 记录请求耗时（按配置的级别）
        call_user_func([Log::channel($logChannel), $timingLogLevel], $timingLog);

        // 记录链路追踪（debug 级别）
        if (($conf['enable_trace'] ?? true) && !empty($traceLog)) {
            Log::channel($logChannel)->debug($traceLog);
        }

        // 记录 SQL 日志为 debug 级别
        if (!empty($sqlLogs)) {
            Log::channel($logChannel)->debug($sqlLogs);
        }

        // 记录 Redis 日志为 debug 级别
        if (!empty($redisLogs)) {
            Log::channel($logChannel)->debug($redisLogs);
        }

        // 记录异常为 error 级别
        if (!empty($exceptionLog)) {
            Log::channel($logChannel)->error($exceptionLog);
        }

        // 记录事务问题为 error 级别
        if (!empty($transactionLog)) {
            Log::channel($logChannel)->error($transactionLog);
        }

        // 记录堆栈追踪为 debug 级别
        if (!empty($stackTraceLog)) {
            Log::channel($logChannel)->debug($stackTraceLog);
        }

        if ($has_uncommitted_transaction) {
            throw new RuntimeException('Uncommitted transactions found');
        }

        return $response;
    }

    /**
     * 获取或生成 trace ID
     *
     * @param Request $request
     * @return string
     */
    protected function getTraceId(Request $request): string
    {
        // 尝试从请求头中获取 trace ID
        $traceIdHeaders = [
            'x-request-id',
            'x-trace-id',
            'request-id',
            'trace-id',
            'x-correlation-id',
        ];

        foreach ($traceIdHeaders as $header) {
            $traceId = $request->header($header);
            if ($traceId) {
                return (string) $traceId;
            }
        }

        // 如果没有，生成一个
        return $this->generateTraceId();
    }

    /**
     * 生成唯一 trace ID（助记词格式）
     *
     * @return string
     */
    protected function generateTraceId(): string
    {
        // 助记词列表
        $adjectives = [
            'swift', 'bright', 'calm', 'bold', 'warm', 'cool', 'brave', 'wise',
            'kind', 'wild', 'pure', 'noble', 'gentle', 'grand', 'silent', 'happy',
            'lucky', 'proud', 'clever', 'mighty', 'fleet', 'fierce', 'keen', 'quick',
            'blue', 'red', 'green', 'gold', 'silver', 'purple', 'orange', 'pink',
        ];

        $nouns = [
            'eagle', 'tiger', 'dragon', 'phoenix', 'wolf', 'bear', 'lion', 'hawk',
            'fox', 'deer', 'horse', 'falcon', 'raven', 'swan', 'crane', 'panda',
            'cobra', 'shark', 'whale', 'orca', 'lynx', 'jaguar', 'leopard', 'cheetah',
            'falcon', 'sparrow', 'robin', 'dove', 'owl', 'crow', 'heron', 'kingfisher',
        ];

        // 随机选择形容词和名词
        $adj = $adjectives[array_rand($adjectives)];
        $noun = $nouns[array_rand($nouns)];

        // 添加一个随机数字后缀（使用微秒时间戳后4位）
        $suffix = substr((string) (microtime(true) * 10000), -4);

        // 格式：adjective-noun-suffix
        return sprintf('%s-%s-%s', $adj, $noun, $suffix);
    }

    /**
     * 添加链路追踪日志
     *
     * @param string $event
     * @param array $context
     * @return void
     */
    protected function addTraceLog(string $event, array $context = []): void
    {
        $traceLogs = Context::get()->requestLoggerTraceLogs ?? [];
        $traceLogs[] = [
            'timestamp' => microtime(true),
            'event' => $event,
            'context' => $context,
        ];
        Context::get()->requestLoggerTraceLogs = $traceLogs;
    }

    /**
     * 获取安全的请求头（过滤敏感信息）
     *
     * @param Request $request
     * @return array
     */
    protected function getSafeHeaders(Request $request): array
    {
        $headers = $request->header();
        $sensitiveHeaders = [
            'authorization',
            'cookie',
            'x-api-key',
            'api-key',
            'token',
        ];

        foreach ($sensitiveHeaders as $sensitive) {
            if (isset($headers[$sensitive])) {
                $headers[$sensitive] = '***REDACTED***';
            }
        }

        return $headers;
    }

    /**
     * 初始化数据库日志监听
     *
     * @return void
     */
    protected function initDbListen()
    {
        if (!class_exists(QueryExecuted::class) || !class_exists(Db::class)) {
            return;
        }
        try {
            $capsule = $this->getCapsule();
            if (!$capsule) {
                return;
            }
            $dispatcher = $capsule->getEventDispatcher();
            if (!$dispatcher) {
                if (!class_exists(Dispatcher::class)) {
                    return;
                }
                $dispatcher = new Dispatcher(new Container());
            }
            $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $query) {
                $sql = trim($query->sql);
                if (strtolower($sql) === 'select 1') {
                    return;
                }
                $sql = str_replace('?', '%s', $sql);
                foreach ($query->bindings as $i => $binding) {
                    if ($binding instanceof \DateTime) {
                        $query->bindings[$i] = $binding->format("'Y-m-d H:i:s'");
                    } else {
                        if (is_string($binding)) {
                            $query->bindings[$i] = "'$binding'";
                        }
                    }
                }
                $log = $sql;
                try {
                    $log = vsprintf($sql, $query->bindings);
                } catch (\Throwable $e) {
                }
                Context::get()->requestLoggerSqlLogs = (Context::get()->requestLoggerSqlLogs ?? '') . "[SQL]\t[connection:{$query->connectionName}] $log [{$query->time} ms]" . PHP_EOL;
            });
            $capsule->setEventDispatcher($dispatcher);
        } catch (\Throwable $e) {
            echo $e;
        }
    }

    /**
     * 获得 Db 的 Manager
     *
     * @return \Webman\Database\Manager
     */
    protected function getCapsule()
    {
        static $capsule;
        if (!$capsule) {
            $reflect = new \ReflectionClass(Db::class);
            $property = $reflect->getProperty('instance');
            $property->setAccessible(true);
            $capsule = $property->getValue();
        }

        return $capsule;
    }

    /**
     * 数据脱敏处理
     *
     * @param array $data
     * @return array
     */
    protected function sanitizeData(array $data): array
    {
        $sensitiveKeys = [
            'password',
            'passwd',
            'pwd',
            'secret',
            'token',
            'api_key',
            'apikey',
            'access_token',
            'refresh_token',
            'private_key',
            'credit_card',
            'card_number',
        ];

        foreach ($data as $key => $value) {
            $lowerKey = strtolower($key);
            foreach ($sensitiveKeys as $sensitive) {
                if (strpos($lowerKey, $sensitive) !== false) {
                    $data[$key] = '***REDACTED***';
                    break;
                }
            }

            // 递归处理数组
            if (is_array($value)) {
                $data[$key] = $this->sanitizeData($value);
            }
        }

        return $data;
    }

    /**
     * 为日志添加前缀
     *
     * @param string $logs
     * @param string $prefix
     * @return string
     */
    protected function addPrefixToLogs(string $logs, string $prefix): string
    {
        if (empty($logs)) {
            return '';
        }

        $lines = explode(PHP_EOL, $logs);
        $prefixedLines = [];

        foreach ($lines as $line) {
            if (trim($line) !== '') {
                $prefixedLines[] = $prefix . ' ' . $line;
            }
        }

        return implode(PHP_EOL, $prefixedLines) . PHP_EOL;
    }

    /**
     * 尝试初始化 redis 日志监听
     *
     * @return array
     */
    protected function tryInitRedisListen(): array
    {
        static $listened;
        if (!class_exists(CommandExecuted::class) || !class_exists(Redis::class)) {
            return [];
        }
        $new_names = [];
        $listened ??= new \WeakMap();
        try {
            foreach (Redis::instance()->connections() ?: [] as $connection) {
                /* @var \Illuminate\Redis\Connections\Connection $connection */
                $name = $connection->getName();
                if (isset($listened[$connection])) {
                    continue;
                }
                $connection->listen(function (CommandExecuted $command) {
                    foreach ($command->parameters as &$item) {
                        if (is_array($item)) {
                            $item = implode('\', \'', $item);
                        }
                    }
                    Context::get()->requestLoggerRedisLogs = (Context::get()->requestLoggerRedisLogs ?? '') . "[Redis]\t[connection:{$command->connectionName}] Redis::{$command->command}('" . implode('\', \'', $command->parameters) . "') ({$command->time} ms)" . PHP_EOL;
                });
                $listened[$connection] = $name;
                $new_names[] = $name;
            }
        } catch (Throwable $e) {
        }

        return $new_names;
    }

    /**
     * 判断是否需要记录异常
     *
     * @param Throwable $e
     * @return bool
     */
    protected function shouldntReport($e): bool
    {
        foreach (config('request_logger.exception.dontReport', []) as $type) {
            if ($e instanceof $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * 检查 Db 是否有未提交的事务
     *
     * @return string
     */
    protected function checkDbUncommittedTransaction(): string
    {
        $logs = '';
        $context = Context::get();
        foreach ($context as $item) {
            if ($item instanceof Connection) {
                if ($item->transactionLevel() > 0) {
                    $item->rollBack();
                    $logs .= "[ERROR]\tUncommitted transaction found and try to rollback" . PHP_EOL;
                }
            }
        }

        return $logs;
    }

    /**
     * 构建链路追踪日志
     *
     * @param string $prefix
     * @return string
     */
    protected function buildTraceLog(string $prefix): string
    {
        $traceLogs = Context::get()->requestLoggerTraceLogs ?? [];
        if (empty($traceLogs)) {
            return '';
        }

        $output = $prefix . ' [REQUEST_TRACE]' . PHP_EOL;
        $startTime = $traceLogs[0]['timestamp'] ?? microtime(true);

        foreach ($traceLogs as $index => $log) {
            $elapsed = round(($log['timestamp'] - $startTime) * 1000, 2);
            $contextStr = !empty($log['context']) ? json_encode($log['context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '{}';
            $output .= $prefix . sprintf(
                ' [+%sms] %s: %s',
                str_pad($elapsed, 8, ' ', STR_PAD_LEFT),
                str_pad($log['event'], 20),
                $contextStr
            ) . PHP_EOL;
        }

        return $output;
    }
}
