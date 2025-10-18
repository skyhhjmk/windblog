<?php

namespace app\command;

use app\service\EnhancedCacheService;
use app\service\QueryOptimizer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 性能分析命令
 *
 * 用于分析系统性能、查询效率和缓存状态
 */
class PerformanceAnalyzer extends Command
{
    protected static $defaultName = 'performance:analyze';

    protected static $defaultDescription = 'Analyze system performance including database queries and cache efficiency';

    private EnhancedCacheService $cacheService;

    protected function configure()
    {
        $this->addOption('detail', 'd', InputOption::VALUE_NONE, 'Show detailed analysis')
             ->addOption('queries', 'u', InputOption::VALUE_NONE, 'Analyze database queries')
             ->addOption('cache', 'c', InputOption::VALUE_NONE, 'Analyze cache performance')
             ->addOption('indexes', 'i', InputOption::VALUE_NONE, 'Generate index suggestions')
             ->addOption('monitor', 'm', InputOption::VALUE_NONE, 'Monitor current performance');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // 初始化缓存服务实例
        $this->cacheService = new EnhancedCacheService();

        $output->writeln('<info>🔍 Performance Analysis Starting...</info>');
        $output->writeln('');

        $showDetail = (bool) $input->getOption('detail');
        $analyzeQueries = (bool) $input->getOption('queries');
        $analyzeCache = (bool) $input->getOption('cache');
        $generateIndexes = (bool) $input->getOption('indexes');
        $monitorMode = (bool) $input->getOption('monitor');

        // 如果没有指定具体分析类型，则执行全部
        if (!$analyzeQueries && !$analyzeCache && !$generateIndexes && !$monitorMode) {
            $analyzeQueries = $analyzeCache = $generateIndexes = true;
        }

        $results = [];

        // 查询性能分析
        if ($analyzeQueries) {
            $output->writeln('<comment>📊 Analyzing Database Queries...</comment>');
            $queryAnalysis = $this->analyzeQueries($showDetail);
            $results['queries'] = $queryAnalysis;

            if ($showDetail) {
                $this->displayQueryAnalysis($output, $queryAnalysis);
            }
        }

        // 缓存性能分析
        if ($analyzeCache) {
            $output->writeln('<comment>💾 Analyzing Cache Performance...</comment>');
            $cacheAnalysis = $this->analyzeCache();
            $results['cache'] = $cacheAnalysis;

            if ($showDetail) {
                $this->displayCacheAnalysis($output, $cacheAnalysis);
            }
        }

        // 索引建议生成
        if ($generateIndexes) {
            $output->writeln('<comment>🔧 Generating Index Suggestions...</comment>');
            $indexSuggestions = $this->generateIndexSuggestions();
            $results['indexes'] = $indexSuggestions;

            if ($showDetail) {
                $this->displayIndexSuggestions($output, $indexSuggestions);
            }
        }

        // 实时监控模式
        if ($monitorMode) {
            $output->writeln('<comment>📈 Starting Performance Monitor...</comment>');

            return $this->runMonitor($input, $output);
        }

        // 显示总体建议
        $this->displayRecommendations($output, $results);

        return Command::SUCCESS;
    }

    /**
     * 分析数据库查询性能
     */
    private function analyzeQueries(bool $detail): array
    {
        QueryOptimizer::enableQueryLogging();

        // 执行一些典型的查询来收集数据
        $this->executeSampleQueries();

        $analysis = QueryOptimizer::analyzeQueryPerformance();
        $nPlusOne = QueryOptimizer::detectNPlusOneQueries();

        return [
            'performance' => $analysis,
            'n_plus_one' => $nPlusOne,
            'total_queries' => $analysis['total_queries'],
            'slow_queries' => count($analysis['slow_queries']),
            'duplicated_queries' => count($analysis['duplicated_queries']),
        ];
    }

    /**
     * 执行示例查询以收集性能数据
     */
    private function executeSampleQueries(): void
    {
        try {
            // 首页查询
            \app\service\BlogService::getBlogPosts(1, []);

            // 分类查询
            \app\model\Category::where('status', true)->limit(10)->get();

            // 标签查询
            \app\model\Tag::limit(20)->get();

            // 评论查询
            \app\model\Comment::where('status', 'approved')->limit(10)->get();

        } catch (\Exception $e) {
            // 忽略示例查询中的错误
        }
    }

    /**
     * 分析缓存性能
     */
    private function analyzeCache(): array
    {
        $cacheStats = $this->cacheService->getStats();

        // 测试缓存性能
        $testKey = 'performance_test_' . time();
        $testData = ['test' => 'data', 'timestamp' => time()];

        $startTime = microtime(true);
        $this->cacheService->set($testKey, $testData, 60);
        $setTime = (microtime(true) - $startTime) * 1000;

        $startTime = microtime(true);
        $retrieved = $this->cacheService->get($testKey);
        $getTime = (microtime(true) - $startTime) * 1000;

        // 清理测试数据
        $this->cacheService->delete($testKey);

        return array_merge($cacheStats, [
            'set_performance_ms' => round($setTime, 2),
            'get_performance_ms' => round($getTime, 2),
            'test_success' => $retrieved === $testData,
        ]);
    }

    /**
     * 生成索引建议
     */
    private function generateIndexSuggestions(): array
    {
        return QueryOptimizer::generateIndexSuggestions();
    }

    /**
     * 运行监控模式
     */
    private function runMonitor(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>📊 Starting real-time performance monitoring...</info>');
        $output->writeln('<info>Press Ctrl+C to stop monitoring</info>');
        $output->writeln('');

        $startTime = time();
        $iteration = 0;

        while (true) {
            $iteration++;
            $currentTime = time();

            // 每10秒输出一次统计
            if (($currentTime - $startTime) % 10 === 0) {
                $this->displayCurrentStats($output, $iteration);
            }

            // 每分钟输出详细分析
            if (($currentTime - $startTime) % 60 === 0) {
                $this->displayDetailedAnalysis($output);
            }

            sleep(1);
        }

        return Command::SUCCESS;
    }

    /**
     * 显示查询分析结果
     */
    private function displayQueryAnalysis(OutputInterface $output, array $analysis): void
    {
        $output->writeln('<info>Query Performance Summary:</info>');
        $output->writeln("  Total Queries: {$analysis['total_queries']}");
        $output->writeln("  Slow Queries (>100ms): {$analysis['slow_queries']}");
        $output->writeln("  Duplicated Queries: {$analysis['duplicated_queries']}");
        $output->writeln('');

        if (!empty($analysis['performance']['slow_queries'])) {
            $output->writeln('<comment>Slow Queries:</comment>');
            foreach ($analysis['performance']['slow_queries'] as $query) {
                $output->writeln('  SQL: ' . substr($query['sql'], 0, 100) . '...');
                $output->writeln("  Time: {$query['time']}ms");
                $output->writeln('');
            }
        }

        if (!empty($analysis['n_plus_one'])) {
            $output->writeln('<comment>N+1 Query Issues Detected:</comment>');
            foreach ($analysis['n_plus_one'] as $issue) {
                $output->writeln("  Table: {$issue['table']}");
                $output->writeln("  Query Count: {$issue['query_count']}");
                $output->writeln("  Suggestion: {$issue['suggestion']}");
                $output->writeln('');
            }
        }
    }

    /**
     * 显示缓存分析结果
     */
    private function displayCacheAnalysis(OutputInterface $output, array $analysis): void
    {
        $output->writeln('<info>Cache Performance Summary:</info>');
        $output->writeln("  Hits: {$analysis['hits']}");
        $output->writeln("  Requests: {$analysis['requests']}");
        $output->writeln("  Hit Rate: {$analysis['hit_rate']}");
        $output->writeln("  Set Performance: {$analysis['set_performance_ms']}ms");
        $output->writeln("  Get Performance: {$analysis['get_performance_ms']}ms");
        $output->writeln('');
    }

    /**
     * 显示索引建议
     */
    private function displayIndexSuggestions(OutputInterface $output, array $suggestions): void
    {
        if (empty($suggestions)) {
            $output->writeln('<info>No index suggestions generated.</info>');

            return;
        }

        $output->writeln('<info>Database Index Suggestions:</info>');
        foreach ($suggestions as $suggestion) {
            $output->writeln("  Type: {$suggestion['type']}");
            $output->writeln("  Table: {$suggestion['table']}");
            $output->writeln('  Columns: ' . implode(', ', $suggestion['columns']));
            $output->writeln("  Reason: {$suggestion['reason']}");
            $output->writeln('');
        }
    }

    /**
     * 显示当前统计信息
     */
    private function displayCurrentStats(OutputInterface $output, int $iteration): void
    {
        $cacheStats = $this->cacheService->getStats();
        $queryLog = QueryOptimizer::getQueryLog();

        $output->writeln("<info>Iteration {$iteration} - Current Stats:</info>");
        $output->writeln("  Cache Hit Rate: {$cacheStats['hit_rate']}");
        $output->writeln('  Active Queries: ' . count($queryLog));
        $output->writeln('  Secondary Cache Size: ' . ($cacheStats['secondary_cache_size'] ?? 0));
        $output->writeln('');
    }

    /**
     * 显示详细分析
     */
    private function displayDetailedAnalysis(OutputInterface $output): void
    {
        $output->writeln('<comment>Detailed Analysis:</comment>');

        $queryAnalysis = QueryOptimizer::analyzeQueryPerformance();
        $this->displayQueryAnalysis($output, [
            'performance' => $queryAnalysis,
            'total_queries' => $queryAnalysis['total_queries'],
            'slow_queries' => count($queryAnalysis['slow_queries']),
            'duplicated_queries' => count($queryAnalysis['duplicated_queries']),
            'n_plus_one' => [],
        ]);

        $cacheAnalysis = $this->analyzeCache();
        $this->displayCacheAnalysis($output, $cacheAnalysis);
    }

    /**
     * 显示优化建议
     */
    private function displayRecommendations(OutputInterface $output, array $results): void
    {
        $output->writeln('<comment>🚀 Performance Recommendations:</comment>');

        // 查询优化建议
        if (!empty($results['queries'])) {
            $queries = $results['queries'];

            if ($queries['slow_queries'] > 0) {
                $output->writeln('  ❌ Fix slow queries (>100ms)');
            }

            if ($queries['duplicated_queries'] > 0) {
                $output->writeln('  ❌ Eliminate duplicate queries');
            }

            if (!empty($queries['n_plus_one'])) {
                $output->writeln('  ❌ Fix N+1 query problems');
            }
        }

        // 缓存优化建议
        if (!empty($results['cache'])) {
            $cache = $results['cache'];

            // 将命中率字符串转换为数字进行比较，例如 '82.5%' -> 82.5
            $hitRate = isset($cache['hit_rate']) ? (float) rtrim($cache['hit_rate'], '%') : 0.0;
            if ($hitRate < 80) {
                $output->writeln('  ⚠️  Consider improving cache hit rate (currently: ' . ($cache['hit_rate'] ?? '0%') . ')');
            }

            if (($cache['set_performance_ms'] ?? 0) > 10) {
                $output->writeln('  ⚠️  Optimize cache write performance');
            }
        }

        // 索引建议
        if (!empty($results['indexes']) && count($results['indexes']) > 0) {
            $output->writeln('  💡 Add ' . count($results['indexes']) . ' recommended database indexes');
        }

        $output->writeln('');
        $output->writeln('<info>Run with --monitor flag for real-time performance tracking</info>');
        $output->writeln('<info>Run with --detail flag for comprehensive analysis</info>');
    }
}
