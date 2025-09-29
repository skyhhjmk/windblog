<?php

namespace plugin\admin\app\controller;

use plugin\admin\app\controller\Base;
use support\Request;
use support\Response;
use app\service\ElasticService;
use app\service\ElasticSyncService;
use app\service\ElasticRebuildService;

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
            'enabled' => (bool)($cfg['enabled'] ?? false),
            'host' => (string)($cfg['host'] ?? 'http://127.0.0.1:9200'),
            'index' => (string)($cfg['index'] ?? 'windblog-posts'),
            'timeout' => (int)($cfg['timeout'] ?? 3),
            'basic_user' => (string)($cfg['basic_user'] ?? ''),
            'basic_pass' => (string)($cfg['basic_pass'] ?? ''),
            'ssl_ca_content' => (string)($cfg['ssl_ca_content'] ?? (string)blog_config('es.ssl.ca_content', '', true)),
            'ssl_ignore' => (bool)($cfg['ssl_ignore'] ?? (bool)blog_config('es.ssl.ignore_errors', false, true)),
            'ssl_client_cert_content' => (string)($cfg['ssl_client_cert_content'] ?? (string)blog_config('es.ssl.client_cert_content', '', true)),
            'ssl_client_key_content' => (string)($cfg['ssl_client_key_content'] ?? (string)blog_config('es.ssl.client_key_content', '', true)),
            'analyzer' => (string)($cfg['analyzer'] ?? (string)blog_config('es.analyzer', 'standard', true))
        ]);
    }

    // 保存配置
    public function save(Request $request): Response
    {
        $payload = [
            'es.enabled' => (bool)$request->post('enabled', false),
            'es.host' => (string)$request->post('host', 'http://127.0.0.1:9200'),
            'es.index' => (string)$request->post('index', 'windblog-posts'),
            'es.timeout' => (int)$request->post('timeout', 3),
            'es.basic.username' => (string)$request->post('basic_user', ''),
            'es.basic.password' => (string)$request->post('basic_pass', ''),
            'es.ssl.ca_content' => (string)$request->post('ssl_ca_content', ''),
            'es.ssl.ignore_errors' => (bool)$request->post('ssl_ignore', 0),
            'es.ssl.client_cert_content' => (string)$request->post('ssl_client_cert_content', ''),
            'es.ssl.client_key_content' => (string)$request->post('ssl_client_key_content', ''),
            'es.analyzer' => (string)$request->post('analyzer', 'standard'),
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
        $analyzer = (string)blog_config('es.analyzer', 'standard', true);
        $ok = ElasticSyncService::createIndex($analyzer);
        return json(['success' => $ok]);
    }

    // 重建索引（全量）
    public function rebuild(Request $request): Response
    {
        $pageSize = (int)$request->post('page_size', 200);
        $ok = ElasticRebuildService::rebuildAll($pageSize);
        return json(['success' => $ok]);
    }

    // 测试连接（调用 _cluster/health）
    public function testConnection(Request $request): Response
    {
        $cfg = ElasticService::getConfigProxy();
        $url = rtrim($cfg['host'], '/') . '/_cluster/health';
        $resp = ElasticService::curlProxy('GET', $url, [], $cfg['timeout']);
        $body = is_array($resp['body']) ? $resp['body'] : [];
        return json([
            'success' => $resp['ok'],
            'status' => $resp['status'],
            'cluster_name' => $body['cluster_name'] ?? null,
            'health_status' => $body['status'] ?? null,
            'number_of_nodes' => $body['number_of_nodes'] ?? null,
            'number_of_data_nodes' => $body['number_of_data_nodes'] ?? null,
            'active_primary_shards' => $body['active_primary_shards'] ?? null,
            'active_shards' => $body['active_shards'] ?? null,
            'relocating_shards' => $body['relocating_shards'] ?? null,
            'initializing_shards' => $body['initializing_shards'] ?? null,
            'unassigned_shards' => $body['unassigned_shards'] ?? null,
            'error' => $resp['error'] ?? null
        ]);
    }

    // 查看最近同步日志
    public function logs(Request $request): Response
    {
        $offset = (int)$request->get('offset', 0);
        if ($offset < 0) {
            $offset = 0;
        }
        $limit = (int)$request->get('limit', 50);
        // 限制最大limit，避免一次拉取过多
        if ($limit < 1) {
            $limit = 1;
        } elseif ($limit > 500) {
            $limit = 500;
        }
        $total = (int)(\support\Redis::lLen('es:sync:logs') ?: 0);
        $start = $offset;
        $end = $offset + $limit - 1;
        $logs = \support\Redis::lRange('es:sync:logs', $start, $end) ?: [];
        return json([
            'success' => true,
            'logs' => $logs,
            'limit' => $limit,
            'offset' => $offset,
            'total' => $total
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
        $text = (string)blog_config('es.synonyms', '', true);
        // 规范化行尾，过滤空行
        $lines = array_values(array_filter(array_map(function ($line) {
            $line = trim(str_replace(["

", "
"], "
", $line));
            return $line === '' ? null : $line;
        }, explode("
", $text))));
        return json([
            'success' => true,
            'text' => $text,
            'lines' => $lines,
            'count' => count($lines),
            'filter_type' => 'synonym_graph',
            'analyzer' => 'wb_synonym_search'
        ]);
    }

    // 保存同义词规则
    public function saveSynonyms(Request $request): Response
    {
        $text = (string)$request->post('synonyms', '');
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
        $host = rtrim((string)($cfg['host'] ?? 'http://127.0.0.1:9200'), '/');
        $index = (string)($cfg['index'] ?? 'windblog-posts');
        $timeout = (int)($cfg['timeout'] ?? 3);
        $tokenizer = (string)blog_config('es.analyzer', 'standard', true) ?: 'standard';

        $text = (string)blog_config('es.synonyms', '', true);
        $lines = array_values(array_filter(array_map(function ($line) {
            $line = trim(str_replace(["

", "
"], "
", $line));
            return $line === '' ? null : $line;
        }, explode("
", $text))));

        // 如果没有规则也允许应用（移除过滤器），但这里简单返回错误
        if (empty($lines)) {
            return json(['success' => false, 'error' => 'no synonyms']);
        }

        // 1) 关闭索引
        $closeUrl = $host . '/' . rawurlencode($index) . '/_close';
        $closeResp = ElasticService::curlProxy('POST', $closeUrl, [], $timeout);
        if (!$closeResp['ok']) {
            return json(['success' => false, 'step' => 'close', 'status' => $closeResp['status'], 'error' => $closeResp['error'] ?? null]);
        }

        // 2) 更新分析设置（添加 synonym_graph 过滤器与搜索分析器）
        $settingsUrl = $host . '/' . rawurlencode($index) . '/_settings';
        $payload = [
            'analysis' => [
                'filter' => [
                    'wb_synonyms' => [
                        'type' => 'synonym_graph',
                        'lenient' => true,
                        'synonyms' => $lines
                    ]
                ],
                'analyzer' => [
                    'wb_synonym_search' => [
                        'tokenizer' => $tokenizer,
                        'filter' => ['lowercase', 'wb_synonyms']
                    ]
                ]
            ]
        ];
        $settingsResp = ElasticService::curlProxy('PUT', $settingsUrl, $payload, $timeout);
        if (!$settingsResp['ok']) {
            // 尝试重新打开索引以避免卡住
            $openUrl = $host . '/' . rawurlencode($index) . '/_open';
            ElasticService::curlProxy('POST', $openUrl, [], $timeout);
            // 自动回退到重建流程：创建新索引并应用同义词、迁移数据、切换别名/索引
            return $this->applySynonymsRebuild($request);
        }

        // 3) 打开索引
        $openUrl = $host . '/' . rawurlencode($index) . '/_open';
        $openResp = ElasticService::curlProxy('POST', $openUrl, [], $timeout);
        if (!$openResp['ok']) {
            return json(['success' => false, 'step' => 'open', 'status' => $openResp['status'], 'error' => $openResp['error'] ?? null]);
        }

        return json(['success' => true]);
    }

    // 预览分词（_analyze 使用同义词搜索分析器）
    public function tokenizePreview(Request $request): Response
    {
        $text = (string)$request->post('text', '');
        if ($text === '') {
            return json(['success' => false, 'error' => 'text required']);
        }
        $cfg = ElasticService::getConfigProxy();
        $host = rtrim((string)($cfg['host'] ?? 'http://127.0.0.1:9200'), '/');
        $index = (string)($cfg['index'] ?? 'windblog-posts');
        $timeout = (int)($cfg['timeout'] ?? 3);
        $tokenizer = (string)blog_config('es.analyzer', 'standard', true) ?: 'standard';

        $url = $host . '/' . rawurlencode($index) . '/_analyze';
        $payload = [
            'text' => $text,
            'analyzer' => 'wb_synonym_search'
        ];
        $resp = ElasticService::curlProxy('POST', $url, $payload, $timeout);
        $body = is_array($resp['body']) ? $resp['body'] : [];
        return json([
            'success' => $resp['ok'],
            'status' => $resp['status'],
            'tokens' => $body['tokens'] ?? [],
            'error' => $resp['error'] ?? null
        ]);
    }

    // 恢复默认查询分析器为 standard（关闭→更新settings→打开）
    public function restoreSynonyms(Request $request): Response
    {
        $cfg = ElasticService::getConfigProxy();
        $host = rtrim((string)($cfg['host'] ?? 'http://127.0.0.1:9200'), '/');
        $index = (string)($cfg['index'] ?? 'windblog-posts');
        $timeout = (int)($cfg['timeout'] ?? 3);

        // 1) 关闭索引
        $closeUrl = $host . '/' . rawurlencode($index) . '/_close';
        $closeResp = ElasticService::curlProxy('POST', $closeUrl, [], $timeout);
        if (!$closeResp['ok']) {
            return json(['success' => false, 'step' => 'close', 'status' => $closeResp['status'], 'error' => $closeResp['error'] ?? null]);
        }

        // 2) 更新 settings：恢复默认查询分析器为 standard
        $settingsUrl = $host . '/' . rawurlencode($index) . '/_settings';
        $payload = [
            'index' => [
                'search' => [
                    'default_analyzer' => 'standard'
                ]
            ]
        ];
        $settingsResp = ElasticService::curlProxy('PUT', $settingsUrl, $payload, $timeout);
        if (!$settingsResp['ok']) {
            // 尝试重新打开索引以避免卡住
            $openUrl = $host . '/' . rawurlencode($index) . '/_open';
            ElasticService::curlProxy('POST', $openUrl, [], $timeout);
            return json(['success' => false, 'step' => 'settings', 'status' => $settingsResp['status'], 'error' => $settingsResp['error'] ?? null]);
        }

        // 3) 打开索引
        $openUrl = $host . '/' . rawurlencode($index) . '/_open';
        $openResp = ElasticService::curlProxy('POST', $openUrl, [], $timeout);
        if (!$openResp['ok']) {
            return json(['success' => false, 'step' => 'open', 'status' => $openResp['status'], 'error' => $openResp['error'] ?? null]);
        }

        return json(['success' => true]);
    }

    // 安全重建索引并应用同义词（创建新索引→_reindex→别名切换/更新配置→删除旧索引）
    public function applySynonymsRebuild(Request $request): Response
    {
        $cfg = ElasticService::getConfigProxy();
        $host = rtrim((string)($cfg['host'] ?? 'http://127.0.0.1:9200'), '/');
        $index = (string)($cfg['index'] ?? 'windblog-posts');
        $timeout = (int)($cfg['timeout'] ?? 3);

        $text = (string)blog_config('es.synonyms', '', true);
        $lines = array_values(array_filter(array_map(function ($line) {
            $line = trim(str_replace(["

", "
"], "
", $line));
            return $line === '' ? null : $line;
        }, explode("
", $text))));
        if (empty($lines)) {
            return json(['success' => false, 'error' => 'no synonyms']);
        }

        // 1) 读取旧索引 mapping
        $mapUrl = $host . '/' . rawurlencode($index) . '/_mapping';
        $mapResp = ElasticService::curlProxy('GET', $mapUrl, [], $timeout);
        if (!$mapResp['ok'] || !is_array($mapResp['body'])) {
            return json(['success' => false, 'step' => 'get_mapping', 'status' => $mapResp['status'] ?? 0, 'error' => $mapResp['error'] ?? 'mapping not available']);
        }
        $mapping = $mapResp['body'][$index]['mappings'] ?? ($mapResp['body']['mappings'] ?? []);

        // 2) 新索引名
        // 基于基础索引名进行 -A/-B 轮换，避免后缀累加
        $base = preg_replace('/(-syn-\\d{14}|-[AB])$/', '', $index);
        $nextSuffix = (preg_match('/-A$/', $index)) ? '-B' : '-A';
        $newIndex = $base . $nextSuffix;

        // 3) 创建新索引（包含同义词 analysis 与默认查询分析器）
        $createUrl = $host . '/' . rawurlencode($newIndex);
        $createPayload = [
            'settings' => [
                'analysis' => [
                    'filter' => [
                        'wb_synonyms' => [
                            'type' => 'synonym_graph',
                            'lenient' => true,
                            'synonyms' => $lines
                        ]
                    ],
                    'analyzer' => [
                        'wb_synonym_search' => [
                            'tokenizer' => $tokenizer,
                            'filter' => ['lowercase', 'wb_synonyms']
                        ]
                    ]
                ]
            ],
            'mappings' => $mapping
        ];
        $createResp = ElasticService::curlProxy('PUT', $createUrl, $createPayload, $timeout);
        if (!$createResp['ok']) {
            return json(['success' => false, 'step' => 'create_index', 'status' => $createResp['status'], 'error' => $createResp['error'] ?? null]);
        }

        // 4) 迁移数据 _reindex（等待完成）
        $reindexUrl = $host . '/_reindex?wait_for_completion=true';
        $reindexPayload = [
            'source' => ['index' => $index],
            'dest' => ['index' => $newIndex],
            'conflicts' => 'proceed'
        ];
        $reindexResp = ElasticService::curlProxy('POST', $reindexUrl, $reindexPayload, max($timeout, 10));
        if (!$reindexResp['ok']) {
            return json(['success' => false, 'step' => 'reindex', 'status' => $reindexResp['status'], 'error' => $reindexResp['error'] ?? null]);
        }

        // 5) 别名策略：优先切换别名；若无别名则更新 es.index 指向新索引
        $aliasUrl = $host . '/' . rawurlencode($index) . '/_alias';
        $aliasResp = ElasticService::curlProxy('GET', $aliasUrl, [], $timeout);
        $hasAlias = $aliasResp['ok'] && isset($aliasResp['body']) && is_array($aliasResp['body']) && !empty($aliasResp['body']);
        if ($hasAlias) {
            // 取第一个别名名
            $aliases = array_keys($aliasResp['body'][$index]['aliases'] ?? []);
            if (!empty($aliases)) {
                $aliasName = $aliases[0];
                $actionsUrl = $host . '/_aliases';
                $actionsPayload = [
                    'actions' => [
                        ['remove' => ['index' => $index, 'alias' => $aliasName]],
                        ['add' => ['index' => $newIndex, 'alias' => $aliasName]]
                    ]
                ];
                $aliasActResp = ElasticService::curlProxy('POST', $actionsUrl, $actionsPayload, $timeout);
                if (!$aliasActResp['ok']) {
                    return json(['success' => false, 'step' => 'switch_alias', 'status' => $aliasActResp['status'], 'error' => $aliasActResp['error'] ?? null]);
                }
            } else {
                // 没有别名条目，降级为更新配置
                blog_config('es.index', $newIndex, true, true, true);
            }
        } else {
            blog_config('es.index', $newIndex, true, true, true);
        }

        // 6) 删除旧索引
        $delUrl = $host . '/' . rawurlencode($index);
        $delResp = ElasticService::curlProxy('DELETE', $delUrl, [], $timeout);
        if (!$delResp['ok']) {
            // 不致命，返回部分成功并提示
            return json(['success' => true, 'warning' => 'old index not deleted', 'status' => $delResp['status'], 'error' => $delResp['error'] ?? null, 'new_index' => $newIndex]);
        }

        return json(['success' => true, 'new_index' => $newIndex]);
    }
}