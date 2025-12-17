<?php

namespace app\service;

use Elastic\Elasticsearch\ClientBuilder;
use Elastic\Elasticsearch\ClientInterface;
use stdClass;
use support\Log;
use Throwable;

class ElasticService
{
    protected static ?ClientInterface $client = null;

    /**
     * 在 ES 中按标签名称聚合并返回标准化标签列表（通过数据库补全id/slug）
     */
    public static function searchTags(string $keyword, int $limit = 10): array
    {
        $cfg = self::getConfig();
        if (!$cfg['enabled']) {
            return [];
        }
        $kw = trim($keyword);
        if ($kw === '') {
            return [];
        }
        $ckey = 'es_tags:' . md5($kw) . ':' . $limit;
        $cached = CacheService::cache($ckey);
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }

        $analyzer = (string) BlogService::getConfig('es.analyzer', 'standard');
        $payload = [
            'size' => max(10, $limit),
            'query' => [
                'bool' => [
                    'must' => [
                        ['term' => ['item_type' => 'tag']],
                        [
                    'bool' => [
                        'should' => [
                            ['match' => ['tag_name' => ['query' => $kw, 'operator' => 'and', 'fuzziness' => 'AUTO', 'analyzer' => $analyzer]]],
                            ['match_phrase_prefix' => ['tag_name' => ['query' => $kw]]],
                            ['match' => ['tag_slug' => ['query' => strtolower($kw), 'operator' => 'and']]],
                        ],
                        'minimum_should_match' => 1,
                    ],
                        ],
                    ],
                ],
            ],
            '_source' => ['tag_id', 'tag_name', 'tag_slug'],
        ];
        $url = sprintf('%s/%s/_search', $cfg['host'], $cfg['index']);
        $resp = self::curlRequest('POST', $url, $payload, $cfg['timeout']);
        if (!$resp['ok'] || !is_array($resp['body'])) {
            return [];
        }
        $hits = $resp['body']['hits']['hits'] ?? [];
        $results = [];
        foreach ($hits as $h) {
            $src = $h['_source'] ?? [];
            $results[] = [
                'id' => (int) ($src['tag_id'] ?? 0),
                'name' => (string) ($src['tag_name'] ?? ''),
                'slug' => (string) ($src['tag_slug'] ?? ''),
            ];
            if (count($results) >= $limit) {
                break;
            }
        }
        $total = isset($resp['body']['hits']['total']['value']) ? (int) $resp['body']['hits']['total']['value'] : count($hits);
        Log::debug(sprintf('[ElasticService] searchTags kw="%s" total=%d results=%d', $kw, $total, count($results)));
        CacheService::cache($ckey, $results, true, 45);

        return $results;
    }

    /**
     * 在 ES 中按分类名称聚合并返回标准化分类列表（通过数据库补全id/slug）
     */
    public static function searchCategories(string $keyword, int $limit = 10): array
    {
        $cfg = self::getConfig();
        if (!$cfg['enabled']) {
            return [];
        }
        $kw = trim($keyword);
        if ($kw === '') {
            return [];
        }
        $ckey = 'es_cats:' . md5($kw) . ':' . $limit;
        $cached = CacheService::cache($ckey);
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }

        $analyzer = (string) BlogService::getConfig('es.analyzer', 'standard');
        $payload = [
            'size' => max(10, $limit),
            'query' => [
                'bool' => [
                    'must' => [
                        ['term' => ['item_type' => 'category']],
                        [
                    'bool' => [
                        'should' => [
                            ['match' => ['category_name' => ['query' => $kw, 'operator' => 'and', 'fuzziness' => 'AUTO', 'analyzer' => $analyzer]]],
                            ['match_phrase_prefix' => ['category_name' => ['query' => $kw]]],
                            ['match' => ['category_slug' => ['query' => strtolower($kw), 'operator' => 'and']]],
                        ],
                        'minimum_should_match' => 1,
                    ],
                        ],
                    ],
                ],
            ],
            '_source' => ['category_id', 'category_name', 'category_slug'],
        ];
        $url = sprintf('%s/%s/_search', $cfg['host'], $cfg['index']);
        $resp = self::curlRequest('POST', $url, $payload, $cfg['timeout']);
        if (!$resp['ok'] || !is_array($resp['body'])) {
            return [];
        }
        $hits = $resp['body']['hits']['hits'] ?? [];
        $results = [];
        foreach ($hits as $h) {
            $src = $h['_source'] ?? [];
            $results[] = [
                'id' => (int) ($src['category_id'] ?? 0),
                'name' => (string) ($src['category_name'] ?? ''),
                'slug' => (string) ($src['category_slug'] ?? ''),
            ];
            if (count($results) >= $limit) {
                break;
            }
        }
        $total = isset($resp['body']['hits']['total']['value']) ? (int) $resp['body']['hits']['total']['value'] : count($hits);
        Log::debug(sprintf('[ElasticService] searchCategories kw="%s" total=%d results=%d', $kw, $total, count($results)));
        CacheService::cache($ckey, $results, true, 45);

        return $results;
    }

    // 暴露配置与请求的代理方法，供同步服务复用（避免重复实现）
    public static function getConfigProxy(): array
    {
        return self::getConfig();
    }

    /**
     * 获取统一的 ES 客户端（缓存构建）
     */
    public static function client(): ClientInterface
    {
        if (self::$client instanceof ClientInterface) {
            return self::$client;
        }
        $cfg = self::getConfig();

        $builder = ClientBuilder::create()
            ->setHosts([rtrim((string) $cfg['host'], '/')]);

        // Basic 认证
        if (!empty($cfg['basic_user'])) {
            $builder->setBasicAuthentication((string) $cfg['basic_user'], (string) ($cfg['basic_pass'] ?? ''));
        }

        // SSL 验证与证书配置
        $builder->setSSLVerification(!(bool) ($cfg['ssl_ignore'] ?? false));

        $caFile = self::materialize((string) ($cfg['ssl_ca_content'] ?? ''), '.ca.pem');
        if (!empty($caFile)) {
            $builder->setCABundle($caFile);
        }
        $certFile = self::materialize((string) ($cfg['ssl_client_cert_content'] ?? ''), '.cert.pem');
        if (!empty($certFile) && method_exists($builder, 'setSSLCert')) {
            $builder->setSSLCert($certFile);
        }
        $keyFile = self::materialize((string) ($cfg['ssl_client_key_content'] ?? ''), '.key.pem');
        if (!empty($keyFile) && method_exists($builder, 'setSSLKey')) {
            $builder->setSSLKey($keyFile);
        }

        self::$client = $builder->build();

        return self::$client;
    }

    /**
     * 将证书内容写入临时文件（按内容md5缓存）
     */
    protected static function materialize(string $content, string $suffix): ?string
    {
        if ($content === '') {
            return null;
        }
        $hash = md5($content);
        $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'windblog_es';
        if (!is_dir($dir)) {
            @mkdir($dir, 0o777, true);
        }
        $file = $dir . DIRECTORY_SEPARATOR . $hash . $suffix;
        if (!file_exists($file)) {
            @file_put_contents($file, $content);
        }

        return $file;
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
            $host = rtrim((string) BlogService::getConfig('es.host', 'http://127.0.0.1:9200'), '/');
            $index = (string) BlogService::getConfig('es.index', 'windblog-posts');
            $timeout = (int) BlogService::getConfig('es.timeout', 3);
            $basic_user = (string) BlogService::getConfig('es.basic.username', '');
            $basic_pass = (string) BlogService::getConfig('es.basic.password', '');
            $ssl_ca_content = (string) BlogService::getConfig('es.ssl.ca_content', '');
            $ssl_ignore = (bool) BlogService::getConfig('es.ssl.ignore_errors', false);
            $ssl_client_cert_content = (string) BlogService::getConfig('es.ssl.client_cert_content', '');
            $ssl_client_key_content = (string) BlogService::getConfig('es.ssl.client_key_content', '');

            return compact('enabled', 'host', 'index', 'timeout', 'basic_user', 'basic_pass', 'ssl_ca_content', 'ssl_ignore', 'ssl_client_cert_content', 'ssl_client_key_content');
        } catch (Throwable $e) {
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
                @mkdir($dir, 0o777, true);
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
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
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
     *
     * @param string      $keyword 搜索关键词
     * @param int         $page    页码
     * @param int         $perPage 每页数量
     * @param string|null $date    时间范围参数 (如 '7d', '30d', '365d')
     *
     * @return array 搜索结果
     */
    public static function searchPosts(string $keyword, int $page, int $perPage, ?string $date = null): array
    {
        $cfg = self::getConfig();
        if (!$cfg['enabled']) {
            return ['ids' => [], 'total' => 0, 'used' => false];
        }
        // 短TTL缓存（热点关键词）
        $ckey = 'es_search:' . md5($keyword) . ':' . $page . ':' . $perPage . ($date ? ':' . $date : '');
        $cached = CacheService::cache($ckey);
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }
        $from = max(0, ($page - 1) * $perPage);

        // 构建基础查询
        $queryPayload = [
            'bool' => [
                'must' => [
                    [
                        'multi_match' => [
                            'query' => $keyword,
                            'fields' => ['title^5', 'excerpt^3', 'content^1', 'categories_names^2', 'tags_names^2'],
                            'type' => 'best_fields',
                            'operator' => 'and',
                            'analyzer' => (string) BlogService::getConfig('es.analyzer', 'standard'),
                            'fuzziness' => 'AUTO',
                        ],
                    ],
                ],
                'should' => [
                    // 标题短语匹配，较高权重（保留），去除对不存在的 title.keyword 精确匹配以避免400
                    // 标题短语匹配，较高权重
                    [
                        'match_phrase' => [
                            'title' => [
                                'query' => $keyword,
                                'boost' => 12,
                            ],
                        ],
                    ],
                    // 标题前缀短语匹配，辅助提升
                    [
                        'match_phrase_prefix' => [
                            'title' => [
                                'query' => $keyword,
                                'boost' => 8,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        // 添加时间范围过滤器
        if (!empty($date)) {
            $dateFilter = self::buildDateFilter($date);
            if ($dateFilter) {
                $queryPayload['bool']['filter'] = $dateFilter;
            }
        }

        $payload = [
            'from' => $from,
            'size' => $perPage,
            'track_total_hits' => true,
            'timeout' => '2s',
            'terminate_after' => 10000,
            // 为高亮提供必要的源字段（保持轻量）
            '_source' => ['id', 'title', 'content'],
            // 可选：不返回 stored_fields
            'stored_fields' => [],
            'query' => $queryPayload,
            'highlight' => [
                'pre_tags' => ['<em class="hl">'],
                'post_tags' => ['</em>'],
                'fields' => [
                    'title' => new stdClass(),
                    'content' => new stdClass(),
                    'categories_names' => new stdClass(),
                    'tags_names' => new stdClass(),
                ],
            ],
        ];

        $url = sprintf('%s/%s/_search', $cfg['host'], $cfg['index']);
        $resp = self::curlRequest('POST', $url, $payload, $cfg['timeout']);
        if (!$resp['ok'] || !is_array($resp['body'])) {
            $bodyStr = isset($resp['body']) ? json_encode($resp['body'], JSON_UNESCAPED_UNICODE) : '';
            Log::warning('[ElasticService] search fallback to DB, status=' . $resp['status'] . ' body=' . $bodyStr);

            return ['ids' => [], 'total' => 0, 'used' => false];
        }

        $body = $resp['body'];
        $hits = $body['hits']['hits'] ?? [];
        $total = isset($body['hits']['total']['value']) ? (int) $body['hits']['total']['value'] : count($hits);
        $ids = [];
        $highlights = [];
        foreach ($hits as $h) {
            $id = null;
            if (isset($h['_id'])) {
                $id = (int) $h['_id'];
            } elseif (isset($h['_source']['id'])) {
                $id = (int) $h['_source']['id'];
            }
            if ($id !== null) {
                $ids[] = $id;
                if (!empty($h['highlight']) && is_array($h['highlight'])) {
                    $hl = $h['highlight'];
                    $highlights[$id] = [
                        'title' => isset($hl['title']) && is_array($hl['title']) ? $hl['title'] : [],
                        'content' => isset($hl['content']) && is_array($hl['content']) ? $hl['content'] : [],
                    ];
                }
            }
        }
        $analyzerUsed = (string) BlogService::getConfig('es.analyzer', 'standard');
        $signals = [
            'highlighted' => !empty($highlights),
            'synonym' => str_contains(mb_strtolower($analyzerUsed), 'synonym'),
            'analyzer' => $analyzerUsed,
        ];
        $result = ['ids' => $ids, 'total' => $total, 'used' => true, 'highlights' => $highlights, 'signals' => $signals];
        // 写入短TTL缓存（45秒）
        CacheService::cache($ckey, $result, true, 45);

        return $result;
    }

    /**
     * 构建时间范围过滤器
     *
     * @param string $date 时间范围参数 (如 '7d', '30d', '365d')
     *
     * @return array|null 时间范围过滤器数组
     */
    protected static function buildDateFilter(string $date): ?array
    {
        // 根据日期参数构建时间范围过滤器
        $dateRanges = [
            '7d' => 'now-7d/d',
            '30d' => 'now-30d/d',
            '365d' => 'now-365d/d',
        ];

        if (isset($dateRanges[$date])) {
            return [
                [
                    'range' => [
                        'created_at' => [
                            'gte' => $dateRanges[$date],
                            'lt' => 'now/d',
                        ],
                    ],
                ],
            ];
        }

        return null;
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
        // 超短TTL缓存（联想）
        if (mb_strlen($prefix) < 1) {
            return [];
        }
        $skey = 'es_suggest:' . md5($prefix) . ':' . (int) $limit;
        $scached = CacheService::cache($skey);
        if ($scached !== false && is_array($scached)) {
            return $scached;
        }
        $usePinyin = (bool) BlogService::getConfig('es.suggest.pinyin', false);
        // 优先使用 title.pinyin（若映射存在且开启），否则使用 title 前缀匹配
        $field = $usePinyin ? 'title.pinyin' : 'title';
        $payload = [
            'size' => $limit,
            'query' => [
                'match_phrase_prefix' => [
                    $field => [
                        'query' => $prefix,
                        'analyzer' => (string) BlogService::getConfig('es.analyzer', 'standard'),
                    ],
                ],
            ],
            '_source' => ['id', 'title'],
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
        // 写入超短TTL缓存（30秒）
        CacheService::cache($skey, $titles, true, 30);

        return $titles;
    }
}
