<?php

namespace plugin\admin\app\controller;

use support\Request;
use support\Redis;
use app\model\Setting;
use app\service\EnhancedCacheService;

/**
 * 性能监控控制器
 * - 页面：多Tab展示缓存状态与分析
 * - API：提供Redis/OPcache状态与时间序列数据
 */
class PerformanceController extends Base
{
    /**
     * 页面入口
     */
    public function index(Request $request)
    {
        return view('performance/index');
    }

    /**
     * Redis 当前状态快照
     * 在缓存驱动为null时仍然能够获取系统自带缓存器的当前状态信息
     */
    public function redisStatus(Request $request)
    {
        try {
            $conn = $request->get('conn', 'default');
            if (!in_array($conn, ['default', 'cache'], true)) {
                return $this->fail('非法连接参数，允许值为 default 或 cache');
            }
            
            $redis = Redis::connection($conn);
            $snapshotKey = 'perf:redis:snapshot';
            $seriesKey = 'perf:redis:series';
               
              // 初始化返回数据和默认值
              $snapshot = null;
              $series = [];
              $defaultSnapshot = [
                  't' => date('Y-m-d H:i:s'),
                  'version' => '未知',
                  'used_memory_mb' => 0,
                  'connected_clients' => 0,
                  'instantaneous_ops_per_sec' => 0,
                  'keys' => 0,
                  'keys_method' => '直接获取',
                  'status' => '不可用'
              ];
            
            try {
                // 尝试从Redis获取预存的快照和时间序列数据
                if ($redis) {
                    $snapshot = $redis->get($snapshotKey);
                    $series = $redis->lRange($seriesKey, -100, -1);
                }
            } catch (\Throwable $e) {
                // 忽略Redis操作异常
            }
            
            // 如果没有预存的快照数据，或者Redis不可用，尝试直接获取当前状态
            if (!$snapshot || !$redis) {
                try {
                    // 尝试直接获取Redis当前状态（如果可用）
                    if (class_exists('Redis') && $redis && method_exists($redis, 'info')) {
                        try {
                            $info = $redis->info();
                            if (is_array($info)) {
                                $currentStats = [
                                    't' => date('Y-m-d H:i:s'),
                                    'version' => isset($info['redis_version']) ? $info['redis_version'] : '',
                                    'used_memory_mb' => isset($info['used_memory']) ? round($info['used_memory'] / 1024 / 1024, 2) : 0,
                                    'connected_clients' => isset($info['connected_clients']) ? $info['connected_clients'] : 0,
                                    'instantaneous_ops_per_sec' => isset($info['instantaneous_ops_per_sec']) ? $info['instantaneous_ops_per_sec'] : 0,
                                    'keys' => 0,
                                    'keys_method' => '直接获取'
                                ];
                                $snapshot = json_encode($currentStats);
                            }
                        } catch (\Throwable $e) {
                            // 忽略Redis info获取异常
                        }
                    }
                } catch (\Throwable $e) {
                    // 忽略直接获取Redis状态的异常
                }
            }

            // 确保总是返回有效的数据结构，即使没有获取到任何数据
            $data = [
                'connection' => $conn,
                'snapshot' => $snapshot ? json_decode($snapshot, true) : $defaultSnapshot,
                'series' => array_map(function ($row) {
                    return json_decode($row, true);
                }, $series ?: []),
                'status' => class_exists('Redis') ? '可用' : '未安装'
            ];
            return $this->success('ok', $data, count($data['series']));
        } catch (\Throwable $e) {
            return $this->fail('获取Redis状态失败: ' . $e->getMessage());
        }
    }

    /**
     * OPcache 当前状态快照
     * 在缓存驱动为null时仍然能够直接获取OPcache的当前状态信息
     */
    public function opcacheStatus(Request $request)
    {
        try {
            $cache = Redis::connection('cache');
            $default = Redis::connection('default');

            $snapshotKey = 'perf:opcache:snapshot';
            $seriesKey = 'perf:opcache:series';

            // 初始化返回数据和默认值
            $snapshot = null;
            $series = [];
            $defaultSnapshot = [
                't' => date('Y-m-d H:i:s'),
                'hits' => 0,
                'misses' => 0,
                'memory_free_mb' => 0,
                'memory_used_mb' => 0,
                'hit_rate' => '0%', // 前端期望的字段名是hit_rate
                'status' => '不可用'
            ];
            
            try {
                // 尝试从Redis获取预存的快照和时间序列数据
                if ($cache) {
                    $snapshot = $cache->get($snapshotKey);
                }
                if ($default) {
                    $series = $default->lRange($seriesKey, -100, -1);
                }
            } catch (\Throwable $e) {
                // 忽略Redis操作异常
            }
            
            // 如果没有预存的快照数据，或者Redis不可用，尝试直接获取当前OPcache状态
            if (!$snapshot || (!$cache && !$default)) {
                try {
                    // 尝试直接获取OPcache当前状态（如果可用）
                    if (function_exists('opcache_get_status')) {
                        try {
                            $status = opcache_get_status(false); // false表示不返回脚本信息，提高性能
                            if (is_array($status) && isset($status['opcache_statistics'])) {
                                $stats = $status['opcache_statistics'];
                                $currentStats = [
                                    't' => date('Y-m-d H:i:s'),
                                    'hits' => isset($stats['hits']) ? $stats['hits'] : 0,
                                    'misses' => isset($stats['misses']) ? $stats['misses'] : 0,
                                    'memory_free_mb' => isset($stats['free_memory']) ? round($stats['free_memory'] / 1024 / 1024, 2) : 0,
                                    'memory_used_mb' => isset($stats['used_memory']) ? round($stats['used_memory'] / 1024 / 1024, 2) : 0,
                                    'hit_rate' => isset($stats['opcache_hit_rate']) ? round($stats['opcache_hit_rate'], 2) . '%' : '0%' // 前端期望的字段名是hit_rate
                                ];
                                $snapshot = json_encode($currentStats);
                            }
                        } catch (\Throwable $e) {
                            // 忽略OPcache状态获取异常
                        }
                    }
                } catch (\Throwable $e) {
                    // 忽略直接获取OPcache状态的异常
                }
            }

            // 确保总是返回有效的数据结构，即使没有获取到任何数据
            $data = [
                'snapshot' => $snapshot ? json_decode($snapshot, true) : $defaultSnapshot,
                'series' => array_map(function ($row) {
                    $item = json_decode($row, true);
                    // 确保时间序列数据中的字段名也正确
                    if (isset($item['cache_hit_rate']) && !isset($item['hit_rate'])) {
                        $item['hit_rate'] = $item['cache_hit_rate'];
                        unset($item['cache_hit_rate']);
                    }
                    return $item;
                }, $series ?: []),
                'status' => function_exists('opcache_get_status') ? '可用' : '未安装'
            ];
            return $this->success('ok', $data, count($data['series']));
        } catch (\Throwable $e) {
            return $this->fail('获取OPcache状态失败: ' . $e->getMessage());
        }
    }

    /**
     * 缓存统计数据
     */
    public function cacheStats(Request $request)
    {
        try {
            $cacheService = new EnhancedCacheService();
            $stats = $cacheService->getStats();
            
            // 保存统计数据到Redis，用于时间序列分析
            $snapshotKey = 'perf:cache:stats';
            $seriesKey = 'perf:cache:series';
            
            $redis = Redis::connection('cache');
            if ($redis) {
                // 添加时间戳
                $stats['t'] = date('Y-m-d H:i:s');
                
                // 保存当前快照
                $redis->set($snapshotKey, json_encode($stats));
                
                // 添加到时间序列（保留最近100条）
                $redis->rPush($seriesKey, json_encode($stats));
                $redis->lTrim($seriesKey, -100, -1);
            }
            
            // 获取时间序列数据
            $series = [];
            if ($redis) {
                $series = $redis->lRange($seriesKey, -100, -1);
                $series = array_map(function ($row) {
                    return json_decode($row, true);
                }, $series ?: []);
            }
            
            $data = [
                'stats' => $stats,
                'series' => $series
            ];
            
            return $this->success('ok', $data, count($data['series']));
        } catch (\Throwable $e) {
            return $this->fail('获取缓存统计失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 综合时间序列（用于图表分析，来自 default 连接）
     */
    public function series(Request $request)
    {
        try {
            // 仅对 Redis 部分支持 conn 参数；OPcache仍从 default 获取
            $conn = $request->get('conn', 'default');
            if (!in_array($conn, ['default', 'cache'], true)) {
                return $this->fail('非法连接参数，允许值为 default 或 cache');
            }
            $redisConn = Redis::connection($conn);
            $default = Redis::connection('default');

            $redisSeries = $redisConn ? $redisConn->lRange('perf:redis:series', -200, -1) : [];
            $opcacheSeries = $default ? $default->lRange('perf:opcache:series', -200, -1) : [];

            $data = [
                'connection' => $conn,
                'redis' => array_map(fn($v) => json_decode($v, true), $redisSeries ?: []),
                'opcache' => array_map(fn($v) => json_decode($v, true), $opcacheSeries ?: []),
            ];
            return $this->success('ok', $data, max(count($data['redis']), count($data['opcache'])));
        } catch (\Throwable $e) {
            return $this->fail('获取序列失败: ' . $e->getMessage());
        }
    }
}