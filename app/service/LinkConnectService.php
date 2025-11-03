<?php

namespace app\service;

use app\model\Link;
use app\model\Post;
use app\model\Setting;
use Exception;
use support\Log;
use Throwable;

/**
 * 友链互联服务
 * 负责：申请发起、接收处理、出入站载荷构建、基础校验、HTTP发送、GPG签名验证
 * 负责友链互联协议的实现，包括配置管理、校验和计算等功能
 */
class LinkConnectService
{
    /**
     * 获取互联协议配置
     *
     * @return array 配置信息
     * @throws Throwable
     */
    public static function getConfig(): array
    {
        // 从数据库获取配置
        $config = Setting::where('key', 'link_connect_config')->value('value');

        if ($config) {
            return json_decode($config, true);
        }

        // 返回默认配置
        return self::getDefaultConfig();
    }

    /**
     * 获取默认配置
     *
     * @return array 默认配置信息
     * @throws Throwable
     */
    public static function getDefaultConfig(): array
    {
        // 从系统配置填充默认值，保证页面初次可见合理默认
        $siteUrl = (string) blog_config('site_url', '', true);
        $domain = parse_url($siteUrl, PHP_URL_HOST) ?: '';

        return [
            'enabled' => true,
            'protocol_name' => 'wind_connect',
            'version' => '1.0.0',
            'site_info' => [
                'name' => (string) blog_config('title', 'WindBlog', true),
                'url' => $siteUrl,
                'domain' => $domain,
                'logo' => (string) blog_config('favicon', '', true),
                'banner' => (string) blog_config('banner', '', true),
                'description' => (string) blog_config('description', '', true),
            ],
            'link_exchange' => [
                'enabled' => (bool) blog_config('link_exchange_enabled', true, true),
                'requirements' => (string) blog_config('link_exchange_requirements', '网站内容健康', true),
                'contact_email' => (string) blog_config('admin_email', '', true),
            ],
            'security' => [
                'enable_checksum' => true,
                'checksum_algorithm' => 'sha512',
                'enable_token' => true,  // 默认开启Token验证
                'token' => (string) blog_config('wind_connect_token', '', true),
            ],
        ];
    }

    /**
     * Token 存储：使用 Setting key=link_connect_tokens 保存数组
     * 结构：[
     *   ['token'=>string,'status'=>'unused|used|revoked','used_by'=>'','used_at'=>null,'created_at'=>ISO8601]
     * ]
     */
    public static function listTokens(): array
    {
        $row = Setting::where('key', 'link_connect_tokens')->first();
        if (!$row) {
            return [];
        }
        $val = json_decode((string) $row->value, true);

        return is_array($val) ? $val : [];
    }

    protected static function saveTokens(array $tokens): bool
    {
        $row = Setting::where('key', 'link_connect_tokens')->first();
        if (!$row) {
            $row = new Setting();
            $row->key = 'link_connect_tokens';
        }
        $row->value = json_encode(array_values($tokens), JSON_UNESCAPED_UNICODE);

        return (bool) $row->save();
    }

    public static function generateToken(): array
    {
        $tokens = self::listTokens();
        $token = bin2hex(random_bytes(16));
        $record = [
            'token' => $token,
            'status' => 'unused',
            'used_by' => '',
            'used_at' => null,
            'created_at' => date('c'),
        ];
        array_unshift($tokens, $record); // 最近生成的在最前
        self::saveTokens($tokens);

        return $record;
    }

    public static function invalidateToken(string $token): bool
    {
        $tokens = self::listTokens();
        $changed = false;
        foreach ($tokens as &$t) {
            if (($t['token'] ?? '') === $token && ($t['status'] ?? '') !== 'revoked') {
                $t['status'] = 'revoked';
                $changed = true;
                break;
            }
        }

        return $changed ? self::saveTokens($tokens) : true;
    }

    public static function getLatestUnusedToken(): ?string
    {
        foreach (self::listTokens() as $t) {
            if (($t['status'] ?? '') === 'unused' && !empty($t['token'])) {
                return $t['token'];
            }
        }

        return null;
    }

    public static function markTokenUsed(string $token, string $usedBy = ''): bool
    {
        // 使用Redis锁保证并发安全
        $lockKey = 'link_connect_token_lock:' . md5($token);
        $redis = \support\Redis::connection();

        // 尝试获取锁，最长等待10秒，锁过期时间30秒
        $lockAcquired = false;
        $maxAttempts = 50; // 10秒 / 0.2秒 = 50次

        for ($i = 0; $i < $maxAttempts; $i++) {
            // SET NX EX: 如果不存在则设置并设置过期时间
            $lockAcquired = $redis->set($lockKey, '1', 'EX', 30, 'NX');
            if ($lockAcquired) {
                break;
            }
            usleep(200000); // 等待200ms
        }

        if (!$lockAcquired) {
            Log::warning('Token标记使用失败：无法获取锁', ['token' => substr($token, 0, 8) . '...']);

            return false;
        }

        try {
            $tokens = self::listTokens();
            $changed = false;

            foreach ($tokens as &$t) {
                if (($t['token'] ?? '') === $token) {
                    // 再次检查状态，防止重复标记
                    if (($t['status'] ?? '') === 'used') {
                        Log::warning('Token已被标记为使用，跳过', ['token' => substr($token, 0, 8) . '...']);

                        return false;
                    }

                    $t['status'] = 'used';
                    $t['used_by'] = $usedBy;
                    $t['used_at'] = date('c');
                    $changed = true;
                    break;
                }
            }

            $result = $changed ? self::saveTokens($tokens) : false;

            return $result;
        } finally {
            // 释放锁
            $redis->del($lockKey);
        }
    }

    /**
     * 保存互联协议配置
     *
     * @param array $config 配置信息
     *
     * @return bool 保存结果
     */
    public static function saveConfig(array $config): bool
    {
        try {
            // 获取现有配置
            $existing = Setting::where('key', 'link_connect_config')->first();

            $configJson = json_encode($config, JSON_UNESCAPED_UNICODE);

            if ($existing) {
                $existing->value = $configJson;

                return $existing->save();
            } else {
                $setting = new Setting();
                $setting->key = 'link_connect_config';
                $setting->value = $configJson;

                return $setting->save();
            }
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * 获取互联协议示例
     *
     * @return array 示例配置
     * @throws Throwable
     */
    public static function getExample(): array
    {
        // 获取当前配置
        $config = self::getConfig();

        // 获取站点信息
        $siteInfo = $config['site_info'];

        // 获取链接列表
        $links = Link::where('status', 1)->get()->toArray();
        $formattedLinks = [];

        foreach ($links as $link) {
            $formattedLinks[] = [
                'id' => (string) $link['id'],
                'name' => $link['name'],
                'url' => $link['url'],
                'icon' => $link['icon'],
                'description' => $link['description'],
                'status' => $link['status'],
                'sort_order' => $link['sort_order'],
                'created_at' => $link['created_at'],
            ];
        }

        // 构建示例数据
        $example = [
            'protocol' => $config['protocol_name'],
            'version' => $config['version'],
            'timestamp' => utc_now_string('Y-m-d H:i:s'),
            'site_info' => $siteInfo,
            'statistics' => [
                'article_count' => 0, // 实际应用中可以统计文章数量
                'comment_count' => 0, // 实际应用中可以统计评论数量
                'link_count' => count($formattedLinks),
            ],
            'apis' => [
                'link' => [
                    'url' => $siteInfo['url'] . '/api/link',
                    'methods' => ['GET'],
                ],
            ],
            'link_exchange' => $config['link_exchange'],
            'links' => $formattedLinks,
            'checksum' => '', // 留空，实际使用时会计算
        ];

        // 计算校验和
        $dataToSign = $example;
        unset($dataToSign['checksum']);
        $checksum = self::calculateChecksum($dataToSign);
        $example['checksum'] = $checksum;

        return $example;
    }

    /**
     * 测试连接
     *
     * @param string $url 要测试的URL
     *
     * @return array 测试结果
     */
    public static function testConnection(string $url): array
    {
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            curl_close($ch);

            if ($httpCode != 200) {
                return ['code' => 1, 'msg' => "HTTP错误码：$httpCode"];
            }

            // 尝试解析JSON
            $data = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return ['code' => 1, 'msg' => '无效的JSON响应'];
            }

            // 验证数据结构
            $requiredFields = ['protocol', 'version', 'timestamp', 'links'];
            $missingFields = [];

            foreach ($requiredFields as $field) {
                if (!isset($data[$field])) {
                    $missingFields[] = $field;
                }
            }

            if (!empty($missingFields)) {
                return [
                    'code' => 1,
                    'msg' => '缺少必要字段：' . implode(', ', $missingFields),
                    'data' => $data,
                ];
            }

            // 验证校验和（如果存在）
            if (isset($data['checksum'])) {
                $dataToVerify = $data;
                unset($dataToVerify['checksum']);
                $calculatedChecksum = self::calculateChecksum($dataToVerify);

                if ($calculatedChecksum !== $data['checksum']) {
                    return [
                        'code' => 1,
                        'msg' => '校验和验证失败',
                        'data' => $data,
                        'checksum_valid' => false,
                    ];
                }

                return [
                    'code' => 0,
                    'msg' => '连接测试成功，校验和验证通过',
                    'data' => $data,
                    'checksum_valid' => true,
                ];
            }

            return [
                'code' => 0,
                'msg' => '连接测试成功',
                'data' => $data,
            ];
        } catch (Exception $e) {
            return ['code' => 1, 'msg' => '测试失败：' . $e->getMessage()];
        }
    }

    /**
     * A站向B站发起友链申请：本地建立 pending + 远端自动建 waiting
     * 入参 keys: peer_api, name, url, icon, description, email
     * 返回: ['code'=>0|1, 'msg'=>string]
     *
     * @param array $input
     *
     * @return array
     * @throws Throwable
     */
    public static function applyToPeer(array $input): array
    {
        // 记录接收到的请求参数
        $debugId = uniqid('link_apply_', true);
        Log::debug("友链申请开始 [{$debugId}] - 参数: " . json_encode([
                'peerApi' => substr((string) ($input['peer_api'] ?? ''), 0, 50) . (strlen((string) ($input['peer_api'] ?? '')) > 50 ? '...' : ''),
                'name' => (string) ($input['name'] ?? ''),
                'url' => (string) ($input['url'] ?? ''),
                'icon' => substr((string) ($input['icon'] ?? ''), 0, 50) . (strlen((string) ($input['icon'] ?? '')) > 50 ? '...' : ''),
                'hasEmail' => !empty((string) ($input['email'] ?? '')),
            ]));

        // 获取配置
        $config = self::getConfig();

        // 检查互联协议是否启用
        if (!$config['enabled']) {
            Log::debug("友链申请失败 [{$debugId}] - 原因: 互联协议未启用");

            return ['code' => 1, 'msg' => '互联协议未启用'];
        }

        $peerApi = (string) ($input['peer_api'] ?? '');
        $name = (string) ($input['name'] ?? '');
        $url = (string) ($input['url'] ?? '');
        $icon = (string) ($input['icon'] ?? '');
        $description = (string) ($input['description'] ?? '');
        $email = (string) ($input['email'] ?? '');

        $peerApi = trim($peerApi);
        $name = trim($name);
        $url = trim($url);
        $icon = trim($icon);
        $description = trim($description);
        $email = trim($email);

        // 参数验证
        if (!$peerApi || !$name || !$url) {
            Log::debug("友链申请失败 [{$debugId}] - 原因: 参数不完整");

            return ['code' => 1, 'msg' => '参数不完整'];
        }

        if (!self::validateUrl($url)) {
            Log::debug("友链申请失败 [{$debugId}] - 原因: 无效URL ({$url})");

            return ['code' => 1, 'msg' => '无效URL'];
        }

        Log::debug("友链申请 [{$debugId}] - 参数验证通过");

        // 去重检查
        $existingLink = Link::where('url', $url)->first();
        if ($existingLink) {
            Log::debug("友链申请失败 [{$debugId}] - 原因: 该链接已存在或审核中 (ID: {$existingLink->id})");

            return ['code' => 1, 'msg' => '该链接已存在或审核中'];
        }

        Log::debug("友链申请 [{$debugId}] - 去重检查通过，链接不存在");

        try {
            // 构建并保存本地 pending 记录
            $link = self::buildLocalPendingLink([
                'name' => $name,
                'url' => $url,
                'icon' => $icon,
                'description' => $description,
                'email' => $email,
                'peer_api' => $peerApi,
            ]);

            $link->save();
            Log::debug("友链申请 [{$debugId}] - 本地pending记录创建成功 (ID: {$link->id})");

            // 组织对外载荷
            $payload = self::buildOutboundPayload();
            Log::debug("友链申请 [{$debugId}] - 准备发送对外请求到: " . substr($peerApi, 0, 100) . (strlen($peerApi) > 100 ? '...' : ''));

            // 发送HTTP请求
            $startTime = microtime(true);
            $res = self::httpPostJson($peerApi, $payload);
            $endTime = microtime(true);
            $responseTime = round(($endTime - $startTime) * 1000, 2);

            Log::debug("友链申请 [{$debugId}] - 对外请求完成 (耗时: {$responseTime}ms, 成功: " . ($res['success'] ? '是' : '否') . ', 响应: ' . json_encode([
                    'code' => $res['code'] ?? null,
                    'hasData' => isset($res['data']),
                    'error' => $res['error'] ?? null,
                ]));

            // 根据请求结果返回相应消息
            if (!$res['success']) {
                Log::debug("友链申请 [{$debugId}] - 完成，状态: 本地记录成功，对方站点连接失败");

                return ['code' => 0, 'msg' => '友链申请已提交，请等待对方站点审核'];
            }

            Log::debug("友链申请 [{$debugId}] - 完成，状态: 成功发送到对方站点");

            return ['code' => 0, 'msg' => '申请已发送，对方将自动创建等待记录'];
        } catch (Exception $e) {
            Log::error("友链申请异常 [{$debugId}] - 错误: " . $e->getMessage() . ', 堆栈: ' . $e->getTraceAsString());
            throw $e;
        }
    }

    /**
     * B站接收来自A站的申请并自动创建 waiting 记录
     * headers: 包含 X-WIND-CONNECT-TOKEN
     * payload: 解码后的 JSON
     * 返回: ['code'=>0|1, 'msg'=>string]
     *
     * @param array $headers
     * @param array $payload
     *
     * @return array
     * @throws Throwable
     */
    public static function receiveFromPeer(array $headers, array $payload): array
    {
        // 获取配置
        $config = self::getConfig();

        // 检查互联协议是否启用
        if (!$config['enabled']) {
            return ['code' => 1, 'msg' => '互联协议未启用'];
        }

        // Token验证：优先使用系统Token，如果未配置则跳过验证（开放接收）
        $incoming = (string) ($headers['X-WIND-CONNECT-TOKEN'] ?? ($headers['x-wind-connect-token'] ?? ''));
        $expected = (string) blog_config('wind_connect_token', '', true);

        // 如果配置了系统Token，则必须验证；否则跳过验证（允许任何站点申请）
        if (!empty($expected) && $incoming !== $expected) {
            return ['code' => 1, 'msg' => '鉴权失败'];
        }

        if (($payload['type'] ?? '') !== 'wind_connect_apply') {
            return ['code' => 1, 'msg' => '无效载荷'];
        }

        // 验证SHA512校验和（根据配置）
        if ($config['security']['enable_checksum']) {
            if (isset($payload['checksum'])) {
                if (!self::verifyChecksum($payload)) {
                    return ['code' => 1, 'msg' => '校验和验证失败'];
                }
            } else {
                return ['code' => 1, 'msg' => '缺少校验和'];
            }
        } elseif (isset($payload['checksum'])) {
            // 配置未启用校验和，但收到了校验和，仍然进行验证
            if (!self::verifyChecksum($payload)) {
                return ['code' => 1, 'msg' => '校验和验证失败'];
            }
        }

        $fromSite = $payload['site'] ?? [];
        $fromLink = $payload['link'] ?? [];

        $peerUrl = (string) ($fromLink['url'] ?? '');
        if (!self::validateUrl($peerUrl)) {
            return ['code' => 1, 'msg' => '无效对方站点URL'];
        }

        if (!Link::where('url', $peerUrl)->first()) {
            $link = self::buildLocalWaitingLink($fromSite, $fromLink);
            $link->save();
        }

        return ['code' => 0, 'msg' => '已接收，等待审核'];
    }

    /**
     * 构建对外载荷（本站信息 + 对外link信息 + 统计数据 + 校验和 + GPG签名）
     *
     * @return array
     * @throws Throwable
     */
    public static function buildOutboundPayload(): array
    {
        $siteInfo = [
            'name' => blog_config('title', 'WindBlog', true),
            'url' => blog_config('site_url', '', true),
            'domain' => parse_url(blog_config('site_url', '', true), PHP_URL_HOST) ?: '',
            'logo' => blog_config('favicon', '', true),
            'banner' => blog_config('banner', '', true),
            'description' => blog_config('description', '', true),
        ];

        // 获取统计数据
        $statistics = [
            'article_count' => Post::where('status', 'published')->count(),
            'link_count' => Link::where('status', true)->count(),
            'total_visits' => blog_config('total_visits', 0, true),
            'start_date' => blog_config('start_date', date('Y-m-d'), true),
        ];

        // API地址信息
        $apis = [
            'wind_connect' => blog_config('site_url', '', true) . '/api/wind-connect',
            'latest_articles' => blog_config('site_url', '', true) . '/api/articles/latest',
            'all_links' => blog_config('site_url', '', true) . '/api/links',
            'rss_feed' => blog_config('site_url', '', true) . '/feed.xml',
        ];

        // 友链交换配置
        $linkExchange = [
            'enabled' => (bool) blog_config('link_exchange_enabled', true, true),
            'requirements' => blog_config('link_exchange_requirements', '网站内容健康', true),
            'contact_email' => blog_config('admin_email', '', true),
        ];

        // 构建基本载荷
        $payload = [
            'protocol' => 'wind_connect',
            'version' => '1.0.0',
            'timestamp' => date('c'),
            'type' => 'wind_connect_apply',
            'site' => $siteInfo,
            'statistics' => $statistics,
            'apis' => $apis,
            'link_exchange' => $linkExchange,
            'link' => [
                'name' => blog_config('title', 'WindBlog', true),
                'url' => blog_config('site_url', '', true),
                'icon' => blog_config('favicon', '', true),
                'description' => blog_config('description', '', true),
                'email' => blog_config('admin_email', '', true),
            ],
        ];

        // 计算并添加SHA512校验和用于防伪
        $payload['checksum'] = self::calculateChecksum($payload);

        return $payload;
    }

    /**
     * 构建本地 pending 记录
     *
     * @param array $input
     *
     * @return Link
     */
    public static function buildLocalPendingLink(array $input): Link
    {
        $link = new Link();
        // 移除htmlspecialchars，存储原始数据，在显示时转义
        $link->name = (string) $input['name'];
        $link->url = (string) $input['url'];
        $link->icon = (string) ($input['icon'] ?? '');
        $link->description = (string) ($input['description'] ?? '');
        $link->status = false; // pending
        $link->sort_order = 999;
        $link->target = '_blank';
        $link->redirect_type = 'goto';
        $link->show_url = true;
        $link->email = (string) ($input['email'] ?? '');
        $link->setCustomFields([
            'peer_status' => 'pending',
            'peer_api' => (string) ($input['peer_api'] ?? ''),
            'peer_protocol' => 'wind_connect',
            'source' => 'connect_apply',
        ]);

        return $link;
    }

    /**
     * 构建本地 waiting 记录
     *
     * @param array $site
     * @param array $fromLink
     *
     * @return Link
     */
    public static function buildLocalWaitingLink(array $site, array $fromLink): Link
    {
        $link = new Link();
        // 移除htmlspecialchars，存储原始数据，在显示时转义
        $link->name = (string) ($fromLink['name'] ?? ($site['name'] ?? '友链'));
        $link->url = (string) ($fromLink['url'] ?? '');
        $link->icon = (string) ($fromLink['icon'] ?? ($site['icon'] ?? ''));
        $link->description = (string) ($fromLink['description'] ?? '');
        $link->status = false; // waiting
        $link->sort_order = 999;
        $link->target = '_blank';
        $link->redirect_type = 'goto';
        $link->show_url = true;
        $link->email = (string) ($fromLink['email'] ?? '');
        $link->setCustomFields([
            'peer_status' => 'waiting',
            'peer_protocol' => (string) ($site['protocol'] ?? 'wind_connect'),
            'source' => 'connect_receive',
        ]);

        return $link;
    }

    /**
     * URL 校验
     *
     * @param string $url
     *
     * @return bool
     */
    public static function validateUrl(string $url): bool
    {
        return (bool) filter_var($url, FILTER_VALIDATE_URL);
    }

    /**
     * 简易 JSON POST（带 X-WIND-CONNECT-TOKEN）
     *
     * @param string $url
     * @param array  $payload
     *
     * @return array
     */
    public static function httpPostJson(string $url, array $payload): array
    {
        try {
            $token = (string) blog_config('wind_connect_token', '', true);
            $headers = "Content-Type: application/json\r\n";
            if (!empty($token)) {
                $headers .= "X-WIND-CONNECT-TOKEN: {$token}\r\n";
            }

            // SSL验证配置：从配置读取，默认启用以提高安全性
            $sslVerify = (bool) blog_config('wind_connect_ssl_verify', true, true);

            $opts = [
                'http' => [
                    'method' => 'POST',
                    'timeout' => 30,
                    'header' => $headers,
                    'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                ],
                'ssl' => [
                    'verify_peer' => $sslVerify,
                    'verify_peer_name' => $sslVerify,
                ],
            ];
            $context = stream_context_create($opts);
            $result = @file_get_contents($url, false, $context);
            if ($result === false) {
                return ['success' => false, 'error' => '请求失败'];
            }

            return ['success' => true, 'body' => (string) $result];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 计算SHA512校验和
     *
     * @param array $data 需要计算校验和的数据
     *
     * @return string SHA512校验和
     */
    public static function calculateChecksum(array $data): string
    {
        // 移除可能存在的checksum字段以计算校验和
        $cleanData = $data;
        unset($cleanData['checksum']);

        // 将数据转换为JSON并计算SHA512校验和
        // 注意：移除 JSON_NUMERIC_CHECK 以避免跨环境不一致
        $jsonData = json_encode($cleanData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha512', $jsonData);
    }

    /**
     * 验证SHA512校验和
     *
     * @param array $data 包含checksum字段的数据
     *
     * @return bool 校验结果
     */
    public static function verifyChecksum(array $data): bool
    {
        if (!isset($data['checksum'])) {
            return false;
        }

        $checksum = $data['checksum'];
        $calculatedChecksum = self::calculateChecksum($data);

        return hash_equals($checksum, $calculatedChecksum);
    }
}
