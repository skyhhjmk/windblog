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
}