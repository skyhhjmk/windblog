<?php

namespace plugin\admin\app\controller;

use app\service\ElasticRebuildService;
use app\service\ElasticService;
use app\service\ElasticSyncService;
use support\Log;
use support\Request;
use support\Response;

/**
 * 搜索设置（Elasticsearch）
 * 仅管理员可见
 */
class ElasticController extends Base
{
    protected $noNeedLogin = [];

    protected array $noNeedRight = [];

    public function index(Request $request): Response
    {
        // 纯HTML视图，使用AJAX获取配置
        return raw_view('elastic/index');
    }

    // 获取当前配置（AJAX）
    public function get(Request $request): Response
    {
        $cfg = ElasticService::getConfigProxy();

        return json([
            'enabled' => (bool) ($cfg['enabled'] ?? false),
            'host' => (string) ($cfg['host'] ?? 'http://127.0.0.1:9200'),
            'index' => (string) ($cfg['index'] ?? 'windblog-posts'),
            'timeout' => (int) ($cfg['timeout'] ?? 3),
            'basic_user' => (string) ($cfg['basic_user'] ?? ''),
            'basic_pass' => (string) ($cfg['basic_pass'] ?? ''),
            'ssl_ca_content' => (string) ($cfg['ssl_ca_content'] ?? (string) blog_config('es.ssl.ca_content', '', true)),
            'ssl_ignore' => (bool) ($cfg['ssl_ignore'] ?? (bool) blog_config('es.ssl.ignore_errors', false, true)),
            'ssl_client_cert_content' => (string) ($cfg['ssl_client_cert_content'] ?? (string) blog_config('es.ssl.client_cert_content', '', true)),
            'ssl_client_key_content' => (string) ($cfg['ssl_client_key_content'] ?? (string) blog_config('es.ssl.client_key_content', '', true)),
            'analyzer' => (string) ($cfg['analyzer'] ?? (string) blog_config('es.analyzer', 'standard', true)),
        ]);
    }

    // 保存配置
    public function save(Request $request): Response
    {
        $payload = [
            'es.enabled' => (bool) $request->post('enabled', false),
            'es.host' => (string) $request->post('host', 'http://127.0.0.1:9200'),
            'es.index' => (string) $request->post('index', 'windblog-posts'),
            'es.timeout' => (int) $request->post('timeout', 3),
            'es.basic.username' => (string) $request->post('basic_user', ''),
            'es.basic.password' => (string) $request->post('basic_pass', ''),
            'es.ssl.ca_content' => (string) $request->post('ssl_ca_content', ''),
            'es.ssl.ignore_errors' => (bool) $request->post('ssl_ignore', 0),
            'es.ssl.client_cert_content' => (string) $request->post('ssl_client_cert_content', ''),
            'es.ssl.client_key_content' => (string) $request->post('ssl_client_key_content', ''),
            'es.analyzer' => (string) $request->post('analyzer', 'standard'),
        ];
        foreach ($payload as $k => $v) {
            // 使用 blog_config 写入
            blog_config($k, $v, true, true, true);
        }

        return json(['success' => true]);
    }

    // 创建索引（根据 analyzer）
    public function createIndex(Request $request): Response
    {
        $analyzer = (string) blog_config('es.analyzer', 'standard', true);
        $ok = ElasticSyncService::createIndex($analyzer);

        return json(['success' => $ok]);
    }

    // 重建索引（全量）
    public function rebuild(Request $request): Response
    {
        $pageSize = (int) $request->post('page_size', 200);
        $ok = ElasticRebuildService::rebuildAll($pageSize);

        return json(['success' => $ok]);
    }

    /**
     * 增量同步 ES 索引（同步全部标签/分类 + 最近文章页）
     */
    public function sync(Request $request): Response
    {
        try {
            $pageSize = (int) $request->post('page_size', 200);
            if ($pageSize < 1) {
                $pageSize = 200;
            }

            // 同步标签
            $tpage = 1;
            while (true) {
                $tBatch = \app\model\Tag::orderBy('id')->forPage($tpage, $pageSize)->get(['id', 'name', 'slug', 'description']);
                if ($tBatch->isEmpty()) {
                    break;
                }
                foreach ($tBatch as $tag) {
                    ElasticSyncService::indexTag($tag);
                }
                $tpage++;
            }

            // 同步分类
            $cpage = 1;
            while (true) {
                $cBatch = \app\model\Category::orderBy('id')->forPage($cpage, $pageSize)->get(['id', 'name', 'slug', 'description']);
                if ($cBatch->isEmpty()) {
                    break;
                }
                foreach ($cBatch as $cat) {
                    ElasticSyncService::indexCategory($cat);
                }
                $cpage++;
            }

            // 同步最近文章页（按 id 倒序取前两页）
            $pp = \app\service\BlogService::getPostsPerPage();
            $recent = \app\model\Post::published()->orderByDesc('id')->forPage(1, max(1, $pp * 2))->get();
            foreach ($recent as $post) {
                ElasticSyncService::indexPost($post);
            }

            return json(['success' => true, 'message' => '同步完成']);
        } catch (\Throwable $e) {
            Log::warning('[ElasticController] sync error: ' . $e->getMessage());

            return json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    // 测试连接（调用 _cluster/health）
    public function testConnection(Request $request): Response
    {
        try {
            $client = ElasticService::client();
            $response = $client->cluster()->health();
            $body = $response->asArray();

            return json([
                'success' => true,
                'status' => 200,
                'cluster_name' => $body['cluster_name'] ?? null,
                'health_status' => $body['status'] ?? null,
                'number_of_nodes' => $body['number_of_nodes'] ?? null,
                'number_of_data_nodes' => $body['number_of_data_nodes'] ?? null,
                'active_primary_shards' => $body['active_primary_shards'] ?? null,
                'active_shards' => $body['active_shards'] ?? null,
                'relocating_shards' => $body['relocating_shards'] ?? null,
                'initializing_shards' => $body['initializing_shards'] ?? null,
                'unassigned_shards' => $body['unassigned_shards'] ?? null,
                'error' => null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[ElasticController] Elastic connection test failed: ' . $e);
            Log::debug('[ElasticController] Elastic connection test using config: ' . var_export(ElasticService::getConfigProxy(), true));

            return json([
                'success' => false,
                'status' => 0,
                'cluster_name' => null,
                'health_status' => null,
                'number_of_nodes' => null,
                'number_of_data_nodes' => null,
                'active_primary_shards' => null,
                'active_shards' => null,
                'relocating_shards' => null,
                'initializing_shards' => null,
                'unassigned_shards' => null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // 查看最近同步日志
    public function logs(Request $request): Response
    {
        $offset = (int) $request->get('offset', 0);
        if ($offset < 0) {
            $offset = 0;
        }
        $limit = (int) $request->get('limit', 50);
        // 限制最大limit，避免一次拉取过多
        if ($limit < 1) {
            $limit = 1;
        } elseif ($limit > 500) {
            $limit = 500;
        }
        $total = (int) (\support\Redis::lLen('es:sync:logs') ?: 0);
        $start = $offset;
        $end = $offset + $limit - 1;
        $logs = \support\Redis::lRange('es:sync:logs', $start, $end) ?: [];

        return json([
            'success' => true,
            'logs' => $logs,
            'limit' => $limit,
            'offset' => $offset,
            'total' => $total,
        ]);
    }

    // 清空同步日志
    public function clearLogs(Request $request): Response
    {
        \support\Redis::del('es:sync:logs');

        return json(['success' => true]);
    }

    // 获取同义词规则（换行分隔）
    public function getSynonyms(Request $request): Response
    {
        $text = (string) blog_config('es.synonyms', '', true);
        // 统一换行为 LF，过滤空行
        $normalized = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = array_values(array_filter(array_map(function ($line) {
            $line = trim($line);

            return $line === '' ? null : $line;
        }, preg_split('/\n/', $normalized))));

        return json([
            'success' => true,
            'text' => $text,
            'lines' => $lines,
            'count' => count($lines),
            'filter_type' => 'synonym_graph',
            'analyzer' => 'wb_synonym_search',
        ]);
    }

    // 保存同义词规则
    public function saveSynonyms(Request $request): Response
    {
        $text = (string) $request->post('synonyms', '');
        // 限制最大长度以避免过大payload（可按需调整）
        if (strlen($text) > 200_000) {
            return json(['success' => false, 'error' => 'synonyms too large']);
        }
        // 写入 blog_config
        blog_config('es.synonyms', $text, true, true, true);

        return json(['success' => true]);
    }

    // 应用同义词到索引（关闭→更新analysis→打开）
    public function applySynonyms(Request $request): Response
    {
        $cfg = ElasticService::getConfigProxy();
        $host = rtrim((string) ($cfg['host'] ?? 'http://127.0.0.1:9200'), '/');
        $index = (string) ($cfg['index'] ?? 'windblog-posts');
        $timeout = (int) ($cfg['timeout'] ?? 3);
        $tokenizer = (string) blog_config('es.analyzer', 'standard', true) ?: 'standard';

        $text = (string) blog_config('es.synonyms', '', true);
        // 统一换行为 LF，过滤空行
        $normalized = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = array_values(array_filter(array_map(function ($line) {
            $line = trim($line);

            return $line === '' ? null : $line;
        }, preg_split('/\n/', $normalized))));

        // 如果没有规则也允许应用（移除过滤器），但这里简单返回错误
        if (empty($lines)) {
            return json(['success' => false, 'error' => 'no synonyms']);
        }

        // 1) 关闭索引（使用官方客户端）
        try {
            $client = ElasticService::client();
            $client->indices()->close(['index' => $index]);
        } catch (\Throwable $e) {
            return json(['success' => false, 'step' => 'close', 'status' => 0, 'error' => $e->getMessage()]);
        }

        // 2) 更新分析设置（官方客户端）
        try {
            $client->indices()->putSettings([
                'index' => $index,
                'body' => [
                    'settings' => [
                        'analysis' => [
                            'filter' => [
                                'wb_synonyms' => [
                                    'type' => 'synonym_graph',
                                    'lenient' => true,
                                    'synonyms' => $lines,
                                ],
                            ],
                            'analyzer' => [
                                'wb_synonym_search' => [
                                    'tokenizer' => $tokenizer,
                                    'filter' => ['lowercase', 'wb_synonyms'],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            try {
                $client->indices()->open(['index' => $index]);
            } catch (\Throwable $ignore) {
            }

            return $this->applySynonymsRebuild($request);
        }

        // 3) 打开索引（官方客户端）
        try {
            $client->indices()->open(['index' => $index]);
        } catch (\Throwable $e) {
            return json(['success' => false, 'step' => 'open', 'status' => 0, 'error' => $e->getMessage()]);
        }

        return json(['success' => true]);
    }

    // 预览分词（_analyze 使用同义词搜索分析器）
    public function tokenizePreview(Request $request): Response
    {
        $text = (string) $request->post('text', '');
        if ($text === '') {
            return json(['success' => false, 'error' => 'text required']);
        }
        $cfg = ElasticService::getConfigProxy();
        $host = rtrim((string) ($cfg['host'] ?? 'http://127.0.0.1:9200'), '/');
        $index = (string) ($cfg['index'] ?? 'windblog-posts');
        $timeout = (int) ($cfg['timeout'] ?? 3);
        $tokenizer = (string) blog_config('es.analyzer', 'standard', true) ?: 'standard';

        try {
            $client = ElasticService::client();
            $response = $client->indices()->analyze([
                'index' => $index,
                'body' => [
                    'text' => $text,
                    'analyzer' => 'wb_synonym_search',
                ],
            ]);
            $body = $response->asArray();

            return json([
                'success' => true,
                'status' => 200,
                'tokens' => $body['tokens'] ?? [],
                'error' => null,
            ]);
        } catch (\Throwable $e) {
            return json([
                'success' => false,
                'status' => 0,
                'tokens' => [],
                'error' => $e->getMessage(),
            ]);
        }
    }

    // 恢复默认查询分析器为 standard（关闭→更新settings→打开）
    public function restoreSynonyms(Request $request): Response
    {
        $cfg = ElasticService::getConfigProxy();
        $host = rtrim((string) ($cfg['host'] ?? 'http://127.0.0.1:9200'), '/');
        $index = (string) ($cfg['index'] ?? 'windblog-posts');
        $timeout = (int) ($cfg['timeout'] ?? 3);

        // 1) 关闭索引（使用官方客户端）
        try {
            $client = ElasticService::client();
            $client->indices()->close(['index' => $index]);
        } catch (\Throwable $e) {
            return json(['success' => false, 'step' => 'close', 'status' => 0, 'error' => $e->getMessage()]);
        }

        // 2) 更新 settings：恢复默认查询分析器为 standard（官方客户端）
        try {
            $client->indices()->putSettings([
                'index' => $index,
                'body' => [
                    'settings' => [
                        'index' => [
                            'search' => [
                                'default_analyzer' => 'standard',
                            ],
                        ],
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            try {
                $client->indices()->open(['index' => $index]);
            } catch (\Throwable $ignore) {
            }

            return json(['success' => false, 'step' => 'settings', 'status' => 0, 'error' => $e->getMessage()]);
        }

        // 3) 打开索引（官方客户端）
        try {
            $client->indices()->open(['index' => $index]);
        } catch (\Throwable $e) {
            return json(['success' => false, 'step' => 'open', 'status' => 0, 'error' => $e->getMessage()]);
        }

        return json(['success' => true]);
    }

    // 安全重建索引并应用同义词（创建新索引→_reindex→别名切换/更新配置→删除旧索引）
    public function applySynonymsRebuild(Request $request): Response
    {
        $cfg = ElasticService::getConfigProxy();
        $host = rtrim((string) ($cfg['host'] ?? 'http://127.0.0.1:9200'), '/');
        $index = (string) ($cfg['index'] ?? 'windblog-posts');
        $timeout = (int) ($cfg['timeout'] ?? 3);

        // 统一换行为 LF
        $text = (string) blog_config('es.synonyms', '', true);
        $normalized = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = array_values(array_filter(array_map(function ($line) {
            $line = trim($line);

            return $line === '' ? null : $line;
        }, preg_split('/\n/', $normalized))));
        if (empty($lines)) {
            return json(['success' => false, 'error' => 'no synonyms']);
        }

        // 读取旧索引 mapping
        try {
            $client = ElasticService::client();
            $mapResponse = $client->indices()->getMapping(['index' => $index]);
            $mapBody = $mapResponse->asArray();
            $mapping = $mapBody[$index]['mappings'] ?? ($mapBody['mappings'] ?? []);
        } catch (\Throwable $e) {
            Log::warning("[ElasticController] getMapping failed for index={$index}: {$e->getMessage()}");

            return json(['success' => false, 'step' => 'get_mapping', 'status' => 0, 'error' => $e->getMessage()]);
        }

        // 新索引名（A/B轮换）
        $base = preg_replace('/(-syn-\d{14}|-[AB])$/', '', $index);
        $nextSuffix = (str_ends_with($index, '-A')) ? '-B' : '-A';
        $newIndex = $base . $nextSuffix;

        // 初始化 tokenizer（缺失问题修复）
        $tokenizer = (string) blog_config('es.analyzer', 'standard', true) ?: 'standard';

        // 创建新索引并应用同义词
        try {
            $client->indices()->create([
                'index' => $newIndex,
                'body' => [
                    'settings' => [
                        'analysis' => [
                            'filter' => [
                                'wb_synonyms' => [
                                    'type' => 'synonym_graph',
                                    'lenient' => true,
                                    'synonyms' => $lines,
                                ],
                            ],
                            'analyzer' => [
                                'wb_synonym_search' => [
                                    'tokenizer' => $tokenizer,
                                    'filter' => ['lowercase', 'wb_synonyms'],
                                ],
                            ],
                        ],
                    ],
                    'mappings' => $mapping,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning("[ElasticController] create index failed newIndex={$newIndex}: {$e->getMessage()}");

            return json(['success' => false, 'step' => 'create_index', 'status' => 0, 'error' => $e->getMessage()]);
        }

        // 迁移数据 _reindex
        try {
            $client->reindex([
                'wait_for_completion' => true,
                'body' => [
                    'source' => ['index' => $index],
                    'dest' => ['index' => $newIndex],
                    'conflicts' => 'proceed',
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning("[ElasticController] reindex failed from {$index} to {$newIndex}: {$e->getMessage()}");

            return json(['success' => false, 'step' => 'reindex', 'status' => 0, 'error' => $e->getMessage()]);
        }

        // 别名策略：优先切换别名；若无别名则更新 es.index 指向新索引
        try {
            $aliasInfo = $client->indices()->getAlias(['index' => $index])->asArray();
            $aliases = array_keys($aliasInfo[$index]['aliases'] ?? []);
            if (!empty($aliases)) {
                $aliasName = $aliases[0];
                $client->indices()->updateAliases([
                    'body' => [
                        'actions' => [
                            ['remove' => ['index' => $index, 'alias' => $aliasName]],
                            ['add' => ['index' => $newIndex, 'alias' => $aliasName]],
                        ],
                    ],
                ]);
            } else {
                blog_config('es.index', $newIndex, true, true, true);
            }
        } catch (\Elastic\Elasticsearch\Exception\ClientResponseException $e) {
            Log::info("[ElasticController] getAlias/updateAliases degraded to config update: {$e->getMessage()}");
            blog_config('es.index', $newIndex, true, true, true);
        } catch (\Throwable $e) {
            Log::warning("[ElasticController] switch_alias failed: {$e->getMessage()}");

            return json(['success' => false, 'step' => 'switch_alias', 'status' => 0, 'error' => $e->getMessage()]);
        }

        // 删除旧索引
        try {
            $client->indices()->delete(['index' => $index]);
        } catch (\Throwable $e) {
            Log::info("[ElasticController] old index delete failed index={$index}: {$e->getMessage()}");

            return json(['success' => true, 'warning' => 'old index not deleted', 'status' => 0, 'error' => $e->getMessage(), 'new_index' => $newIndex]);
        }

        return json(['success' => true, 'new_index' => $newIndex]);
    }
}
