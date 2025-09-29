<?php

namespace app\service;

use support\Log;

class ElasticService
{
    // 暴露配置与请求的代理方法，供同步服务复用（避免重复实现）
    public static function getConfigProxy(): array
    {
        return self::getConfig();
    }

    public static function curlProxy(string $method, string $url, array $payload = [], int $timeout = 3): array
    {
        return self::curlRequest($method, $url, $payload, $timeout);
    }
    /**
     * 从博客配置读取 ES 基本信息
     */
    protected static function getConfig(): array
    {
        try {
            $enabled = BlogService::getConfig('es.enabled', false);
            $host = rtrim((string)BlogService::getConfig('es.host', 'http://127.0.0.1:9200'), '/');
            $index = (string)BlogService::getConfig('es.index', 'windblog-posts');
            $timeout = (int)BlogService::getConfig('es.timeout', 3);
            $basic_user = (string)BlogService::getConfig('es.basic.username', '');
            $basic_pass = (string)BlogService::getConfig('es.basic.password', '');
            $ssl_ca_content = (string)BlogService::getConfig('es.ssl.ca_content', '');
            $ssl_ignore = (bool)BlogService::getConfig('es.ssl.ignore_errors', false);
            $ssl_client_cert_content = (string)BlogService::getConfig('es.ssl.client_cert_content', '');
            $ssl_client_key_content = (string)BlogService::getConfig('es.ssl.client_key_content', '');
            return compact('enabled', 'host', 'index', 'timeout', 'basic_user', 'basic_pass', 'ssl_ca_content', 'ssl_ignore', 'ssl_client_cert_content', 'ssl_client_key_content');
        } catch (\Throwable $e) {
            Log::error('[ElasticService] Read config failed: ' . $e->getMessage());
            return [
                'enabled' => false,
                'host' => 'http://127.0.0.1:9200',
                'index' => 'windblog-posts',
                'timeout' => 3,
                'basic_user' => '',
                'basic_pass' => '',
                'ssl_ca_content' => '',
                'ssl_ignore' => false,
                'ssl_client_cert_content' => '',
                'ssl_client_key_content' => '',
            ];
        }
    }

    /**
     * 基础 curl 请求
     */
    protected static function curlRequest(string $method, string $url, array $payload = [], int $timeout = 3): array
    {
        $ch = curl_init();
        $headers = [
            'Content-Type: application/json',
        ];
        // 附加Basic认证（如果配置存在）
        $cfg = self::getConfig();
        if (!empty($cfg['basic_user'])) {
            $basic = base64_encode($cfg['basic_user'] . ':' . ($cfg['basic_pass'] ?? ''));
            $headers[] = 'Authorization: Basic ' . $basic;
        }
        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
        ];

        // 将证书内容写入临时文件以供curl使用（按内容md5缓存）
        $materialize = function (string $content, string $suffix): ?string {
            if ($content === '') {
                return null;
            }
            $hash = md5($content);
            $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'windblog_es';
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            $file = $dir . DIRECTORY_SEPARATOR . $hash . $suffix;
            if (!file_exists($file)) {
                @file_put_contents($file, $content);
            }
            return $file;
        };

        // SSL处理：忽略错误或使用证书校验
        if (!empty($cfg['ssl_ignore'])) {
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        } else {
            $opts[CURLOPT_SSL_VERIFYPEER] = true;
            $opts[CURLOPT_SSL_VERIFYHOST] = 2;
            $caFile = $materialize($cfg['ssl_ca_content'] ?? '', '.ca.pem');
            if (!empty($caFile)) {
                $opts[CURLOPT_CAINFO] = $caFile;
            }
            // 客户端证书/私钥（双向认证）
            $certFile = $materialize($cfg['ssl_client_cert_content'] ?? '', '.cert.pem');
            if (!empty($certFile)) {
                $opts[CURLOPT_SSLCERT] = $certFile;
            }
            $keyFile = $materialize($cfg['ssl_client_key_content'] ?? '', '.key.pem');
            if (!empty($keyFile)) {
                $opts[CURLOPT_SSLKEY] = $keyFile;
            }
        }

        if (!empty($payload)) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            Log::error(sprintf('[ElasticService] curl error(%d): %s url=%s', $errno, $error, $url));
            return ['ok' => false, 'status' => $status, 'error' => $error, 'body' => null];
        }
        $data = null;
        if (is_string($resp) && $resp !== '') {
            $data = json_decode($resp, true);
        }
        return ['ok' => ($status >= 200 && $status < 300), 'status' => $status, 'error' => null, 'body' => $data];
    }

    /**
     * ES 搜索文章，返回命中 ID 顺序与总数
     */
    public static function searchPosts(string $keyword, int $page, int $perPage): array
    {
        $cfg = self::getConfig();
        if (!$cfg['enabled']) {
            return ['ids' => [], 'total' => 0, 'used' => false];
        }
        $from = max(0, ($page - 1) * $perPage);
        $payload = [
            'from' => $from,
            'size' => $perPage,
            'track_total_hits' => true,
            'query' => [
                'multi_match' => [
                    'query' => $keyword,
                    'fields' => ['title^5', 'excerpt^3', 'content^1'],
                    'type' => 'best_fields',
                    'operator' => 'and'
                ]
            ],
            'highlight' => [
                'fields' => [
                    'title' => new \stdClass(),
                    'content' => new \stdClass(),
                ]
            ]
        ];

        $url = sprintf('%s/%s/_search', $cfg['host'], $cfg['index']);
        $resp = self::curlRequest('POST', $url, $payload, $cfg['timeout']);
        if (!$resp['ok'] || !is_array($resp['body'])) {
            Log::warning('[ElasticService] search fallback to DB, status=' . $resp['status']);
            return ['ids' => [], 'total' => 0, 'used' => false];
        }

        $body = $resp['body'];
        $hits = $body['hits']['hits'] ?? [];
        $total = isset($body['hits']['total']['value']) ? (int)$body['hits']['total']['value'] : count($hits);
        $ids = [];
        foreach ($hits as $h) {
            if (isset($h['_source']['id'])) {
                $ids[] = (int)$h['_source']['id'];
            } elseif (isset($h['_id'])) {
                $ids[] = (int)$h['_id'];
            }
        }
        return ['ids' => $ids, 'total' => $total, 'used' => true];
    }

    /**
     * 标题联想，返回若干标题字符串
     */
    public static function suggestTitles(string $prefix, int $limit = 10): array
    {
        $cfg = self::getConfig();
        if (!$cfg['enabled']) {
            return [];
        }
        $usePinyin = (bool)BlogService::getConfig('es.suggest.pinyin', false);
        // 优先使用 title.pinyin（若映射存在且开启），否则使用 title 前缀匹配
        $field = $usePinyin ? 'title.pinyin' : 'title';
        $payload = [
            'size' => $limit,
            'query' => [
                'match_phrase_prefix' => [
                    $field => [
                        'query' => $prefix
                    ]
                ]
            ],
            '_source' => ['id', 'title']
        ];
        $url = sprintf('%s/%s/_search', $cfg['host'], $cfg['index']);
        $resp = self::curlRequest('POST', $url, $payload, $cfg['timeout']);
        if (!$resp['ok'] || !is_array($resp['body'])) {
            return [];
        }
        $hits = $resp['body']['hits']['hits'] ?? [];
        $titles = [];
        foreach ($hits as $h) {
            $t = $h['_source']['title'] ?? null;
            if ($t && !in_array($t, $titles, true)) {
                $titles[] = $t;
            }
        }
        return $titles;
    }
}