<?php

namespace plugin\admin\app\controller;

use support\Request;
use support\Redis;
use app\model\Setting;

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
     * Redis 当前状态快照（来自 cache 连接）
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

            $snapshot = $redis ? $redis->get($snapshotKey) : null;
            $series = $redis ? $redis->lRange($seriesKey, -100, -1) : [];

            $data = [
                'connection' => $conn,
                'snapshot' => $snapshot ? json_decode($snapshot, true) : null,
                'series' => array_map(function ($row) {
                    return json_decode($row, true);
                }, $series ?: []),
            ];
            return $this->success('ok', $data, count($data['series']));
        } catch (\Throwable $e) {
            return $this->fail('获取Redis状态失败: ' . $e->getMessage());
        }
    }

    /**
     * OPcache 当前状态快照（来自 cache 连接）
     */
    public function opcacheStatus(Request $request)
    {
        try {
            $cache = Redis::connection('cache');
            $default = Redis::connection('default');

            $snapshotKey = 'perf:opcache:snapshot';
            $seriesKey = 'perf:opcache:series';

            $snapshot = $cache ? $cache->get($snapshotKey) : null;
            $series = $default ? $default->lRange($seriesKey, -100, -1) : [];

            $data = [
                'snapshot' => $snapshot ? json_decode($snapshot, true) : null,
                'series' => array_map(function ($row) {
                    return json_decode($row, true);
                }, $series ?: []),
            ];
            return $this->success('ok', $data, count($data['series']));
        } catch (\Throwable $e) {
            return $this->fail('获取OPcache状态失败: ' . $e->getMessage());
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