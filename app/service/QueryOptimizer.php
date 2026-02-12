<?php

namespace app\service;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use support\Db;
use support\Log;

/**
 * 数据库查询优化助手
 *
 * 提供数据库查询优化、索引建议和N+1查询检测功能
 */
class QueryOptimizer
{
    /**
     * 查询日志
     */
    private static array $queryLog = [];

    /**
     * 启用查询日志记录
     */
    public static function enableQueryLogging(): void
    {
        Db::connection()->enableQueryLog();
    }

    /**
     * 获取查询日志
     */
    public static function getQueryLog(): array
    {
        return Db::connection()->getQueryLog();
    }

    /**
     * 清除查询日志
     */
    public static function clearQueryLog(): void
    {
        Db::connection()->flushQueryLog();
        self::$queryLog = [];
    }

    /**
     * 分析查询性能
     */
    public static function analyzeQueryPerformance(): array
    {
        $queries = self::getQueryLog();
        $slowQueries = [];
        $duplicatedQueries = [];
        $queryCount = [];

        foreach ($queries as $query) {
            $sql = $query['query'] ?? '';
            $time = $query['time'] ?? 0;

            // 记录查询次数
            $queryCount[$sql] = ($queryCount[$sql] ?? 0) + 1;

            // 找出慢查询（超过100ms）
            if ($time > 100) {
                $slowQueries[] = [
                    'sql' => $sql,
                    'time' => $time,
                    'bindings' => $query['bindings'] ?? [],
                ];
            }
        }

        // 找出重复查询（出现3次以上）
        foreach ($queryCount as $sql => $count) {
            if ($count >= 3) {
                $duplicatedQueries[] = [
                    'sql' => $sql,
                    'count' => $count,
                ];
            }
        }

        return [
            'total_queries' => count($queries),
            'slow_queries' => $slowQueries,
            'duplicated_queries' => $duplicatedQueries,
            'query_count' => $queryCount,
        ];
    }

    /**
     * 检测N+1查询问题
     */
    public static function detectNPlusOneQueries(): array
    {
        $queries = self::getQueryLog();
        $nPlusOnePatterns = [];
        $tableQueries = [];

        foreach ($queries as $query) {
            $sql = strtolower($query['query'] ?? '');

            // 提取表名和查询类型
            if (preg_match('/from\s+`?(\w+)`?/i', $sql, $matches)) {
                $table = $matches[1];

                if (!isset($tableQueries[$table])) {
                    $tableQueries[$table] = [];
                }

                $tableQueries[$table][] = [
                    'sql' => $query['query'],
                    'time' => $query['time'] ?? 0,
                    'bindings' => $query['bindings'] ?? [],
                ];
            }
        }

        // 分析每个表的查询模式
        foreach ($tableQueries as $table => $tableQueryList) {
            if (count($tableQueryList) > 5) {
                // 查询次数过多，可能存在N+1问题
                $nPlusOnePatterns[] = [
                    'table' => $table,
                    'query_count' => count($tableQueryList),
                    'queries' => $tableQueryList,
                    'suggestion' => '考虑使用 eager loading 或查询优化',
                ];
            }
        }

        return $nPlusOnePatterns;
    }

    /**
     * 优化Eloquent查询
     */
    public static function optimizeEloquentQuery(Builder $query): Builder
    {
        $sql = $query->toSql();

        // 添加必要的select子句
        if (strpos(strtolower($sql), 'select') === false) {
            $query->select($query->getModel()->getTable() . '.*');
        }

        return $query;
    }

    /**
     * 批量预加载关联查询（防止N+1问题）
     */
    public static function eagerLoadRelations(Builder $query, array $relations): Builder
    {
        foreach ($relations as $relation) {
            if (is_array($relation)) {
                $query->with($relation[0], function ($q) use ($relation) {
                    if (isset($relation[1]) && is_callable($relation[1])) {
                        $relation[1]($q);
                    }
                });
            } else {
                $query->with($relation);
            }
        }

        return $query;
    }

    /**
     * 智能分页查询
     */
    public static function smartPaginate(Builder $query, int $perPage = 15, array $columns = ['*'], string $pageName = 'page'): array
    {
        // 获取总数（如果需要）
        $total = null;
        if (config('database.smart_pagination_count', true)) {
            $total = $query->count();
        }

        // 执行分页查询
        $items = $query->select($columns)
            ->forPage(request()->input($pageName, 1), $perPage)
            ->get();

        return [
            'items' => $items,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => request()->input($pageName, 1),
            'last_page' => $total ? ceil($total / $perPage) : 1,
        ];
    }

    /**
     * 缓存查询结果
     */
    public static function cacheQuery(string $key, callable $callback, int $ttl = 300)
    {
        $cacheKey = 'query_cache:' . md5($key);

        return cache()->remember($cacheKey, $ttl, $callback);
    }

    /**
     * 生成数据库索引建议
     */
    public static function generateIndexSuggestions(): array
    {
        $suggestions = [];
        $queries = self::getQueryLog();

        // 分析WHERE条件
        foreach ($queries as $query) {
            $sql = strtolower($query['query'] ?? '');

            // 查找WHERE条件中的列
            if (preg_match_all('/where\s+`?(\w+)`?\s*[=<>]/i', $sql, $matches)) {
                foreach ($matches[1] as $column) {
                    $suggestions[] = [
                        'type' => 'index',
                        'table' => 'unknown', // 需要从查询中提取表名
                        'columns' => [$column],
                        'reason' => 'WHERE条件中的列应该建立索引',
                    ];
                }
            }

            // 查找ORDER BY列
            if (preg_match_all('/order\s+by\s+`?(\w+)`?/i', $sql, $matches)) {
                foreach ($matches[1] as $column) {
                    $suggestions[] = [
                        'type' => 'index',
                        'table' => 'unknown',
                        'columns' => [$column],
                        'reason' => 'ORDER BY列应该建立索引以提高排序性能',
                    ];
                }
            }
        }

        // 去重建议
        $uniqueSuggestions = [];
        foreach ($suggestions as $suggestion) {
            $key = $suggestion['table'] . ':' . implode(',', $suggestion['columns']);
            if (!isset($uniqueSuggestions[$key])) {
                $uniqueSuggestions[$key] = $suggestion;
            }
        }

        return array_values($uniqueSuggestions);
    }

    /**
     * 执行查询性能监控
     */
    public static function monitorQueryPerformance(callable $callback)
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        self::enableQueryLogging();

        $result = $callback();

        $endTime = microtime(true);
        $endMemory = memory_get_usage();

        $executionTime = ($endTime - $startTime) * 1000; // 毫秒
        $memoryUsed = ($endMemory - $startMemory) / 1024 / 1024; // MB

        $analysis = self::analyzeQueryPerformance();

        return [
            'result' => $result,
            'performance' => [
                'execution_time_ms' => round($executionTime, 2),
                'memory_used_mb' => round($memoryUsed, 2),
                'query_count' => $analysis['total_queries'],
                'slow_queries' => count($analysis['slow_queries']),
                'duplicated_queries' => count($analysis['duplicated_queries']),
            ],
            'analysis' => $analysis,
        ];
    }

    /**
     * 创建查询构建器助手
     */
    public static function createOptimizedQueryBuilder(string $table): Builder
    {
        return Db::table($table);
    }

    /**
     * 批量插入优化
     */
    public static function batchInsert(array $data, string $table, int $batchSize = 1000): bool
    {
        if (empty($data)) {
            return true;
        }

        $chunks = array_chunk($data, $batchSize);

        foreach ($chunks as $chunk) {
            try {
                Db::table($table)->insert($chunk);
            } catch (Exception $e) {
                Log::error('Batch insert failed: ' . $e->getMessage());

                return false;
            }
        }

        return true;
    }

    /**
     * 查询结果预热（缓存热门查询）
     */
    public static function warmUpCache(array $warmUpQueries): void
    {
        foreach ($warmUpQueries as $queryKey => $callback) {
            if (is_callable($callback)) {
                self::cacheQuery($queryKey, $callback, 3600); // 缓存1小时
            }
        }
    }
}
