<?php

namespace app\process;

use support\Log;
use support\Redis;
use Throwable;
use Workerman\Timer;
use Workerman\Worker;

/**
 * 性能采集进程
 * - 定期采集 Redis 与 OPcache 状态
 * - 快照写入 Redis cache 连接
 * - 时间序列写入 Redis default 连接（保留窗口）
 * - 概要写入 PGSQL settings（使用 Setting 模型）
 */
class Performance
{
    protected int $interval = 60;

    protected int $maxSeries = 500;

    protected ?int $timerId = null;

    /**
     * 兼容两种构造传参方式：
     * - 位置参数：__construct(60, 500)
     * - 数组参数：__construct(['interval' => 60, 'max_series' => 500])
     */
    public function __construct($interval = 60, $maxSeries = 500)
    {
        // 如果第一个参数是数组，则解析数组
        if (is_array($interval)) {
            $arr = $interval;
            $ival = $arr['interval'] ?? 60;
            $mseries = $arr['max_series'] ?? 500;
            if (is_numeric($ival) && (int) $ival > 0) {
                $this->interval = (int) $ival;
            }
            if (is_numeric($mseries) && (int) $mseries > 0) {
                $this->maxSeries = (int) $mseries;
            }

            return;
        }

        // 位置参数形式
        if (is_numeric($interval) && (int) $interval > 0) {
            $this->interval = (int) $interval;
        }
        if (is_numeric($maxSeries) && (int) $maxSeries > 0) {
            $this->maxSeries = (int) $maxSeries;
        }
    }

    public function onWorkerStart(Worker $worker): void
    {
        // 检查系统是否已安装
        if (!is_installed()) {
            Log::warning('Performance 进程检测到系统未安装，已跳过启动');

            return;
        }

        // 环境变量检查：仅当 CACHE_DRIVER=redis 时启用采集
        $driver = getenv('CACHE_DRIVER');
        if (!$driver || strtolower(trim($driver)) !== 'redis') {
            Log::info("Performance 进程未启用：CACHE_DRIVER={$driver}，仅在 redis 时启用");

            return;
        }

        Log::info('Performance 进程启动 - PID: ' . getmypid());
        $this->collect(); // 立即采集一次
        $this->timerId = Timer::add($this->interval, [$this, 'collect']);
    }

    public function onWorkerStop(Worker $worker): void
    {
        if ($this->timerId) {
            Timer::del($this->timerId);
        }
    }

    public function collect(): void
    {
        $t = utc_now_string('Y-m-d H:i:s');
        try {
            $conns = ['default', 'cache'];
            $statsByConn = [];

            // Redis 采集（分别采集并写入各自连接）
            foreach ($conns as $conn) {
                /** @var mixed $r */
                $r = Redis::connection($conn);
                if (!$r) {
                    Log::warning("Redis 连接不可用，跳过采集: {$conn}");
                    continue;
                }
                $stats = $this->collectRedisStats($r);
                $statsByConn[$conn] = $stats;

                // 写入当前连接的快照与序列
                /** @phpstan-ignore-next-line */
                $r->set('perf:redis:snapshot', json_encode($stats, JSON_UNESCAPED_UNICODE));
                /** @phpstan-ignore-next-line */
                $r->rPush('perf:redis:series', json_encode(array_merge($stats, ['t' => $t])));
                $this->trimList($r, 'perf:redis:series', $this->maxSeries);

                // 调试日志：标注哪个连接采集到了什么
                Log::debug(sprintf(
                    'Redis采集: conn=%s used_memory_mb=%s keys=%s clients=%s ops=%s',
                    $conn,
                    $stats['used_memory_mb'] ?? 'null',
                    $stats['keys'] ?? 'null',
                    $stats['connected_clients'] ?? 'null',
                    $stats['instantaneous_ops_per_sec'] ?? 'null'
                ));
            }

            // OPcache 采集（保持原逻辑：snapshot写入cache，series写入default）
            /** @var mixed $cache */
            $cache = Redis::connection('cache');
            /** @var mixed $default */
            $default = Redis::connection('default');
            $opStats = $this->collectOpcacheStats();
            if ($cache) {
                $cache->set('perf:opcache:snapshot', json_encode($opStats, JSON_UNESCAPED_UNICODE));
            }
            if ($default) {
                $default->rPush('perf:opcache:series', json_encode(array_merge($opStats, ['t' => $t])));
                $this->trimList($default, 'perf:opcache:series', $this->maxSeries);
            }

            Log::debug("Performance 采集完成: {$t}");
        } catch (Throwable $e) {
            Log::error('Performance 采集失败: ' . $e->getMessage());
        }
    }

    protected function collectRedisStats($r): array
    {
        try {
            $info = $r ? $r->info() : [];
            // 强制使用 DBSIZE，兼容 Predis 与 PhpRedis
            $keys = null;
            $method = null;
            try {
                // 优先尝试 PhpRedis 的 rawCommand
                if (method_exists($r, 'rawCommand')) {
                    $res = $r->rawCommand('DBSIZE');
                    if (is_numeric($res)) {
                        $keys = (int) $res;
                        $method = 'rawCommand(DBSIZE)';
                    }
                }
                // 其次尝试 Predis 的 executeRaw
                if ($keys === null && method_exists($r, 'executeRaw')) {
                    $res = $r->executeRaw(['DBSIZE']);
                    if (is_numeric($res)) {
                        $keys = (int) $res;
                        $method = 'executeRaw([DBSIZE])';
                    }
                }
                // 再尝试常规方法调用（Predis 魔术方法、PhpRedis 显式方法）
                if ($keys === null) {
                    try {
                        $res = $r->dbSize();
                        if (is_numeric($res)) {
                            $keys = (int) $res;
                            $method = 'dbSize()';
                        }
                    } catch (Throwable $e) {
                    }
                    try {
                        if ($keys === null) {
                            $res = $r->dbsize();
                            if (is_numeric($res)) {
                                $keys = (int) $res;
                                $method = 'dbsize()';
                            }
                        }
                    } catch (Throwable $e) {
                    }
                }
            } catch (Throwable $e) {
                // 忽略，进入兜底
            }
            // 兜底：若仍无法获取，置为 null，避免误用所有库总和
            if ($keys === null) {
                Log::warning('Redis键数统计失败，DBSIZE不可用，返回null作为键数');
            }

            return [
                't' => utc_now_string('Y-m-d H:i:s'),
                'version' => $info['redis_version'] ?? null,
                'used_memory_mb' => isset($info['used_memory']) ? round($info['used_memory'] / 1024 / 1024, 2) : null,
                'connected_clients' => $info['connected_clients'] ?? null,
                'instantaneous_ops_per_sec' => $info['instantaneous_ops_per_sec'] ?? null,
                'keys' => $keys,
                'keys_method' => $method,
            ];
        } catch (Throwable $e) {
            return ['error' => $e->getMessage(), 't' => utc_now_string('Y-m-d H:i:s')];
        }
    }

    protected function collectOpcacheStats(): array
    {
        try {
            if (!function_exists('opcache_get_status')) {
                return ['enabled' => false, 't' => utc_now_string('Y-m-d H:i:s')];
            }
            $status = opcache_get_status(false);
            $mem = $status['memory_usage'] ?? [];
            $stats = $status['opcache_statistics'] ?? [];
            $freeMb = isset($mem['free_memory']) ? round($mem['free_memory'] / 1024 / 1024, 2) : null;
            $usedMb = isset($mem['used_memory']) ? round($mem['used_memory'] / 1024 / 1024, 2) : null;
            $hitRate = $stats['opcache_hit_rate'] ?? null;

            return [
                't' => utc_now_string('Y-m-d H:i:s'),
                'enabled' => $status['opcache_enabled'] ?? null,
                'memory_free_mb' => $freeMb,
                'memory_used_mb' => $usedMb,
                'hits' => $stats['hits'] ?? null,
                'misses' => $stats['misses'] ?? null,
                'hit_rate' => is_numeric($hitRate) ? round($hitRate, 2) : null,
                'cached_scripts' => $stats['cached_scripts'] ?? null,
            ];
        } catch (Throwable $e) {
            return ['error' => $e->getMessage(), 't' => utc_now_string('Y-m-d H:i:s')];
        }
    }

    protected function trimList($redis, string $key, int $max): void
    {
        try {
            $len = (int) $redis->lLen($key);
            if ($len > $max) {
                $redis->lTrim($key, $len - $max, -1);
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
}
