<?php

declare(strict_types=1);

namespace app\service\version;

use app\service\CacheService;
use support\Log;
use Throwable;

/**
 * HTTP客户端工具类
 */
class HttpClient
{
    /**
     * 发送GET请求
     *
     * @param string $url     请求URL
     * @param array  $params  请求参数
     * @param array  $headers 请求头
     *
     * @return object|false 响应对象或false
     */
    public static function get(string $url, array $params = [], array $headers = [])
    {
        $queryString = empty($params) ? '' : '?' . http_build_query($params);
        $fullUrl = $url . $queryString;

        Log::debug("[HttpClient] 请求URL: {$fullUrl}", ['headers' => $headers]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $fullUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'WindBlog/1.0',
        ]);

        if (!empty($headers)) {
            $headerArray = [];
            foreach ($headers as $key => $value) {
                $headerArray[] = $key . ': ' . $value;
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headerArray);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        Log::debug("[HttpClient] 响应状态: {$httpCode}, 错误: {$error}", ['response_length' => $response ? strlen($response) : 0]);

        if ($error || $httpCode !== 200) {
            Log::warning("[HttpClient] 请求失败: {$fullUrl}, 状态: {$httpCode}, 错误: {$error}");

            return false;
        }

        return new class ($response, $httpCode) {
            private $body;

            private $statusCode;

            public function __construct($body, $statusCode)
            {
                $this->body = $body;
                $this->statusCode = $statusCode;
            }

            public function getBody()
            {
                return $this->body;
            }

            public function getStatusCode()
            {
                return $this->statusCode;
            }
        };
    }
}

/**
 * 版本服务实现
 */
class VersionService implements VersionServiceInterface
{
    /**
     * @var array 当前版本信息
     */
    private array $currentVersion;

    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->currentVersion = $this->getCurrentVersionInfo();
        // 使用CacheService的静态方法，不需要实例化
    }

    /**
     * 获取当前版本信息
     *
     * @return array
     */
    private function getCurrentVersionInfo(): array
    {
        $versionFile = base_path() . '/version.json';

        if (!file_exists($versionFile)) {
            throw new RuntimeException('Version file not found');
        }

        $content = file_get_contents($versionFile);
        if ($content === false) {
            throw new RuntimeException('Failed to read version file');
        }

        $versionInfo = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Failed to parse version file: ' . json_last_error_msg());
        }

        return $versionInfo;
    }

    /**
     * 检查是否有新版本
     *
     * @param string      $channel 更新通道（release/pre-release/dev）
     * @param string|null $mirror  自定义镜像源（null表示使用配置中的默认值）
     * @param bool        $force   是否强制刷新缓存
     *
     * @return array {has_new_version: bool, current_version: string, latest_version: string, release_url: string,
     *               channel: string, available_versions: array, cached: bool, default_mirror: string, update_available: bool}
     * @throws Throwable
     */
    public function checkVersion(string $channel = 'release', ?string $mirror = null, bool $force = false): array
    {
        // 验证通道有效性
        if (!ChannelEnum::isValidChannel($channel)) {
            $channel = ChannelEnum::RELEASE->value;
        }

        $mirror ??= $this->getDefaultMirror();
        $defaultMirror = $this->getDefaultMirror();

        // 生成缓存键
        $cacheKey = $this->generateCacheKey('check_version', $channel, $mirror);

        // 尝试从缓存获取结果
        if (!$force) {
            $cachedResult = CacheService::cache($cacheKey);
            if ($cachedResult !== false) {
                $cachedResult['cached'] = true;
                $cachedResult['default_mirror'] = $defaultMirror;

                return $cachedResult;
            }
        }

        try {
            // 从远程获取最新版本信息
            $latestVersion = $this->getLatestVersionFromMirror($channel, $mirror);
            $availableVersions = $this->getAvailableVersions($channel, $mirror);

            $currentVersion = $this->currentVersion['version'];
            $currentCommit = $this->currentVersion['commit'] ?? '';

            // DEV通道：只要commit号不同就认为有新版本
            if ($channel === ChannelEnum::DEV->value) {
                $latestCommit = $latestVersion['commit'] ?? '';
                $hasNewVersion = $currentCommit !== $latestCommit;
            } else {
                // 其他通道使用版本号比较
                $hasNewVersion = VersionComparator::isLessThan($currentVersion, $latestVersion['version']);
            }

            $result = [
                'has_new_version' => $hasNewVersion,
                'current_version' => $currentVersion,
                'latest_version' => $latestVersion['version'],
                'release_url' => $latestVersion['release_url'] ?? '',
                'published_at' => $latestVersion['published_at'] ?? null,
                'channel' => $channel,
                'available_versions' => $availableVersions,
                'cached' => false,
                'default_mirror' => $defaultMirror,
                'update_available' => $hasNewVersion,
            ];

            // 根据通道设置不同的缓存时间
            $ttl = $this->getCacheTtl($channel);
            CacheService::cache($cacheKey, $result, true, $ttl);

            return $result;
        } catch (\Throwable $e) {
            // 如果获取失败，返回当前版本信息
            $result = [
                'has_new_version' => false,
                'current_version' => $this->currentVersion['version'] ?? 'unknown',
                'latest_version' => $this->currentVersion['version'] ?? 'unknown',
                'release_url' => '',
                'published_at' => null,
                'channel' => $channel,
                'available_versions' => [],
                'cached' => false,
                'default_mirror' => $defaultMirror,
                'update_available' => false,
                'error' => '版本检查失败: ' . $e->getMessage(),
            ];

            // 缓存失败结果较短时间
            CacheService::cache($cacheKey, $result, true, 300);

            return $result;
        }
    }

    /**
     * 获取缓存TTL（秒）
     *
     * @param string $channel
     *
     * @return int
     */
    private function getCacheTtl(string $channel): int
    {
        switch ($channel) {
            case ChannelEnum::RELEASE->value:
                // 正式版本缓存时间较长（6小时）
                return 6 * 60 * 60;
            case ChannelEnum::PRE_RELEASE->value:
                // 预发布版本缓存时间较短（2小时）
                return 2 * 60 * 60;
            case ChannelEnum::DEV->value:
                // 开发版本缓存时间很短（15分钟）
                return 15 * 60;
            default:
                return 60 * 60; // 默认1小时
        }
    }

    /**
     * 生成缓存键
     *
     * @param string $operation
     * @param string $channel
     * @param string $mirror
     *
     * @return string
     */
    private function generateCacheKey(string $operation, string $channel, string $mirror): string
    {
        // 镜像源URL可能很长
        $mirrorHash = md5($mirror);

        return "version_{$operation}_{$channel}_{$mirrorHash}";
    }

    /**
     * 清除版本检查缓存
     *
     * @param string|null $channel
     * @param string|null $mirror
     *
     * @return bool
     */
    public function clearVersionCache(?string $channel = null, ?string $mirror = null): bool
    {
        try {
            if ($channel && $mirror) {
                // 清除指定channel和mirror的缓存
                $cacheKey = $this->generateCacheKey('check_version', $channel, $mirror);

                return CacheService::clearCache($cacheKey);
            } elseif ($channel) {
                // 清除指定channel的所有缓存
                $pattern = "version_check_version_{$channel}_*";

                return CacheService::clearCache($pattern);
            } else {
                // 清除所有版本检查缓存
                $pattern = 'version_check_version_*';

                return CacheService::clearCache($pattern);
            }
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 获取版本检查统计信息
     *
     * @return array
     */
    public function getVersionStats(): array
    {
        try {
            $stats = [
                'total_cache_hits' => 0,
                'total_cache_misses' => 0,
                'channels_tested' => [],
                'last_update_time' => null,
                'mirror_status' => [],
                'cache_size' => 0,
            ];

            // 检查各通道的缓存状态
            foreach (ChannelEnum::cases() as $channel) {
                $channelValue = $channel->value;
                $defaultMirror = $this->getDefaultMirror();

                try {
                    $cacheKey = $this->generateCacheKey('check_version', $channelValue, $defaultMirror);
                    $cached = CacheService::cache($cacheKey);

                    if ($cached !== false) {
                        $stats['total_cache_hits']++;
                        $stats['channels_tested'][$channelValue] = [
                            'status' => 'cached',
                            'latest_version' => $cached['latest_version'] ?? 'unknown',
                            'cached_at' => time(),
                        ];
                    } else {
                        $stats['total_cache_misses']++;
                        $stats['channels_tested'][$channelValue] = [
                            'status' => 'not_cached',
                            'latest_version' => 'unknown',
                        ];
                    }
                } catch (\Throwable $e) {
                    $stats['channels_tested'][$channelValue] = [
                        'status' => 'error',
                        'error' => $e->getMessage(),
                    ];
                }
            }

            // 检查镜像源状态
            $defaultMirror = $this->getDefaultMirror();
            try {
                $validationResult = $this->validateMirror($defaultMirror);
                $stats['mirror_status'][$defaultMirror] = $validationResult;
            } catch (\Throwable $e) {
                $stats['mirror_status'][$defaultMirror] = [
                    'valid' => false,
                    'message' => '验证失败: ' . $e->getMessage(),
                    'response_time' => 0,
                ];
            }

            // 估算缓存大小
            try {
                $stats['cache_size'] = $this->estimateCacheSize();
            } catch (\Throwable $e) {
                $stats['cache_size'] = 0;
            }

            return $stats;
        } catch (\Throwable $e) {
            return [
                'error' => '获取统计信息失败: ' . $e->getMessage(),
                'total_cache_hits' => 0,
                'total_cache_misses' => 0,
                'channels_tested' => [],
                'mirror_status' => [],
                'cache_size' => 0,
            ];
        }
    }

    /**
     * 预热版本检查缓存
     *
     * @param array $channels
     *
     * @return bool
     */
    public function warmupVersionCache(array $channels = ['release', 'pre-release', 'dev']): bool
    {
        try {
            $successCount = 0;
            $totalCount = count($channels);
            $defaultMirror = $this->getDefaultMirror();

            foreach ($channels as $channel) {
                if (!ChannelEnum::isValidChannel($channel)) {
                    continue;
                }

                try {
                    // 强制刷新缓存
                    $this->checkVersion($channel, $defaultMirror, true);
                    $successCount++;
                } catch (\Throwable $e) {
                    Log::warning("[VersionService] 预热缓存失败 - 通道: {$channel}, 错误: {$e->getMessage()}");
                }
            }

            $success = $successCount > 0;
            if ($success) {
                Log::info("[VersionService] 版本检查缓存预热完成: {$successCount}/{$totalCount} 通道成功");
            } else {
                Log::warning("[VersionService] 版本检查缓存预热失败: 0/{$totalCount} 通道成功");
            }

            return $success;
        } catch (\Throwable $e) {
            Log::error("[VersionService] 预热缓存过程中发生错误: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * 估算缓存大小
     *
     * @return int 缓存条目数量
     */
    private function estimateCacheSize(): int
    {
        try {
            // 简单的缓存大小估算，通过统计相关键的数量
            $pattern = 'version_check_version_*';

            return $this->countCacheEntries($pattern);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 统计缓存条目数量
     *
     * @param string $pattern
     *
     * @return int
     */
    private function countCacheEntries(string $pattern): int
    {
        try {
            // 这里我们假设使用文件缓存或简单的键值缓存
            // 实际实现可能需要根据使用的缓存驱动进行调整
            $defaultMirror = $this->getDefaultMirror();
            $count = 0;

            foreach (ChannelEnum::cases() as $channel) {
                $channelValue = $channel->value;
                $cacheKey = $this->generateCacheKey('check_version', $channelValue, $defaultMirror);

                if (CacheService::cache($cacheKey) !== false) {
                    $count++;
                }
            }

            return $count;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 获取当前配置的默认镜像源
     *
     * @return string
     * @throws Throwable
     */
    public function getDefaultMirror(): string
    {
        // 强制清除blog_config缓存，确保使用正确的默认值
        cache('blog_config_update_mirror', null, false);

        return blog_config('update_mirror', 'https://github.com/windblog/windblog', true);
    }

    /**
     * 从镜像源获取最新版本
     *
     * @param string $channel
     * @param string $mirror
     *
     * @return array
     */
    private function getLatestVersionFromMirror(string $channel, string $mirror): array
    {
        try {
            // 提取仓库信息
            $repoInfo = $this->parseMirror($mirror);

            switch ($repoInfo['type']) {
                case 'github':
                    return $this->getLatestGithubVersion($repoInfo, $channel);
                default:
                    // 自定义镜像源，暂时返回当前版本
                    return [
                        'version' => $this->currentVersion['version'],
                        'release_url' => $mirror,
                    ];
            }
        } catch (\Throwable $e) {
            // 如果获取失败，返回当前版本
            return [
                'version' => $this->currentVersion['version'],
                'release_url' => $mirror,
            ];
        }
    }

    /**
     * 从GitHub获取最新版本
     *
     * @param array  $repoInfo
     * @param string $channel
     *
     * @return array
     */
    private function getLatestGithubVersion(array $repoInfo, string $channel): array
    {
        Log::debug('[VersionService] getLatestGithubVersion 接收到的仓库信息', $repoInfo);
        Log::debug('[VersionService] 仓库名长度: ' . strlen($repoInfo['repo']));
        Log::debug('[VersionService] 仓库名十六进制: ' . bin2hex($repoInfo['repo']));
        Log::debug('[VersionService] 通道: ' . $channel);

        // DEV通道获取主分支的最新提交信息
        if ($channel === ChannelEnum::DEV->value) {
            try {
                // 首先获取仓库信息，以确定默认分支
                $repoUrl = "https://api.github.com/repos/{$repoInfo['owner']}/{$repoInfo['repo']}";
                Log::debug('[VersionService] DEV通道获取仓库信息 URL: ' . $repoUrl);

                $repoResponse = HttpClient::get($repoUrl, [], [
                    'Accept' => 'application/vnd.github.v3+json',
                    'User-Agent' => 'WindBlog/1.0',
                ]);

                if (!$repoResponse || $repoResponse->getStatusCode() !== 200) {
                    Log::warning('[VersionService] DEV通道获取仓库信息失败，状态码: ' . ($repoResponse ? $repoResponse->getStatusCode() : '无响应'));
                    // 如果获取仓库信息失败，尝试使用常见的分支名
                    $defaultBranch = 'main';
                } else {
                    $repoData = json_decode((string) $repoResponse->getBody(), true);
                    $defaultBranch = $repoData['default_branch'] ?? 'main';
                    Log::debug('[VersionService] 仓库默认分支: ' . $defaultBranch);
                }

                // 使用默认分支获取最新提交
                $commitUrl = "https://api.github.com/repos/{$repoInfo['owner']}/{$repoInfo['repo']}/commits/{$defaultBranch}";
                Log::debug('[VersionService] DEV通道构建的API URL: ' . $commitUrl);

                $response = HttpClient::get($commitUrl, [], [
                    'Accept' => 'application/vnd.github.v3+json',
                    'User-Agent' => 'WindBlog/1.0',
                ]);

                Log::debug('[VersionService] DEV通道GitHub API 响应状态: ' . ($response ? $response->getStatusCode() : 'false'));

                if (!$response || $response->getStatusCode() !== 200) {
                    Log::warning('[VersionService] DEV通道GitHub API 请求失败，状态码: ' . ($response ? $response->getStatusCode() : '无响应'));
                    throw new RuntimeException('GitHub API 请求失败');
                }

                $responseBody = (string) $response->getBody();
                Log::debug('[VersionService] DEV通道GitHub API 响应内容长度: ' . strlen($responseBody));

                $commitInfo = json_decode($responseBody, true);
                if (!$commitInfo) {
                    Log::warning('[VersionService] DEV通道GitHub API 响应解析失败，响应内容: ' . substr($responseBody, 0, 200) . '...');
                    throw new RuntimeException('GitHub API 响应解析失败');
                }

                $commitSha = substr($commitInfo['sha'], 0, 7); // 使用短提交号作为版本号

                return [
                    'version' => $commitSha,
                    'release_url' => $commitInfo['html_url'],
                    'published_at' => $commitInfo['commit']['committer']['date'],
                    'commit' => $commitInfo['sha'],
                    'message' => $commitInfo['commit']['message'],
                ];
            } catch (Throwable $e) {
                Log::error('[VersionService] DEV通道获取最新提交失败: ' . $e->getMessage());
                throw $e;
            }
        }

        // 非DEV通道获取最新的release
        $url = "https://api.github.com/repos/{$repoInfo['owner']}/{$repoInfo['repo']}/releases";
        Log::debug('[VersionService] 构建的API URL: ' . $url);

        // 根据通道设置不同的参数
        $params = [];
        switch ($channel) {
            case ChannelEnum::RELEASE->value:
                $params['filter'] = 'latest';
                break;
            case ChannelEnum::PRE_RELEASE->value:
                $params['per_page'] = 10;
                break;
        }

        $response = HttpClient::get($url, $params, [
            'Accept' => 'application/vnd.github.v3+json',
            'User-Agent' => 'WindBlog/1.0',
        ]);

        Log::debug('[VersionService] GitHub API 响应状态: ' . ($response ? $response->getStatusCode() : 'false'));

        if (!$response || $response->getStatusCode() !== 200) {
            Log::warning('[VersionService] GitHub API 请求失败，状态码: ' . ($response ? $response->getStatusCode() : '无响应'));
            throw new RuntimeException('GitHub API 请求失败');
        }

        $responseBody = (string) $response->getBody();
        Log::debug('[VersionService] GitHub API 响应内容长度: ' . strlen($responseBody));

        $releases = json_decode($responseBody, true);
        if (!$releases) {
            Log::warning('[VersionService] GitHub API 响应解析失败，响应内容: ' . substr($responseBody, 0, 200) . '...');
            throw new RuntimeException('GitHub API 响应解析失败');
        }

        Log::debug('[VersionService] GitHub API 返回版本数量: ' . count($releases));

        // 根据通道筛选版本
        $filteredReleases = $this->filterReleasesByChannel($releases, $channel);

        if (empty($filteredReleases)) {
            // 如果没有找到匹配的版本，返回当前版本
            return [
                'version' => $this->currentVersion['version'],
                'release_url' => "https://github.com/{$repoInfo['owner']}/{$repoInfo['repo']}/releases",
            ];
        }

        $latest = $filteredReleases[0];

        return [
            'version' => ltrim($latest['tag_name'], 'v'),
            'release_url' => $latest['html_url'],
            'published_at' => $latest['published_at'],
            'body' => $latest['body'],
        ];
    }

    /**
     * 根据通道筛选发布版本
     *
     * @param array  $releases
     * @param string $channel
     *
     * @return array
     */
    private function filterReleasesByChannel(array $releases, string $channel): array
    {
        switch ($channel) {
            case ChannelEnum::RELEASE->value:
                // 只返回正式发布版本
                return array_values(array_filter($releases, function ($release) {
                    return !$release['prerelease'];
                }));

            case ChannelEnum::PRE_RELEASE->value:
                // 返回预发布版本和正式版本
                return $releases;

            case ChannelEnum::DEV->value:
                // dev通道返回所有版本（包括draft）
                return array_values(array_filter($releases, function ($release) {
                    return $release['prerelease'] || $release['draft'];
                }));

            default:
                return [];
        }
    }

    /**
     * 解析镜像源信息
     *
     * @param string $mirror
     *
     * @return array
     */
    private function parseMirror(string $mirror): array
    {
        Log::debug("[VersionService] 开始解析镜像源: {$mirror}");

        // GitHub URL 格式解析
        if (preg_match('/github\.com\/([^\/]+)\/([^\/]+)/', $mirror, $matches)) {
            Log::debug('[VersionService] 正则表达式匹配结果: ' . json_encode($matches));
            $result = [
                'type' => 'github',
                'owner' => $matches[1],
                'repo' => str_ends_with($matches[2], '.git') ? substr($matches[2], 0, -4) : $matches[2],
            ];

            Log::debug('[VersionService] 原始匹配数组: ' . json_encode($matches));
            Log::debug('[VersionService] 处理后的owner: ' . $result['owner']);
            Log::debug('[VersionService] 处理后的repo: ' . $result['repo']);
            Log::debug('[VersionService] 处理后的repo长度: ' . strlen($result['repo']));
            Log::debug('[VersionService] 镜像源解析成功: ' . json_encode($result));

            return $result;
        }

        // 尝试解析其他格式的镜像源
        // TODO: 可以扩展支持其他Git托管平台

        Log::warning("[VersionService] 不支持的镜像源格式: {$mirror}");
        throw new RuntimeException('不支持的镜像源格式: ' . $mirror);
    }

    /**
     * 获取指定通道的所有可用版本
     *
     * @param string      $channel 更新通道
     * @param string|null $mirror  自定义镜像源
     *
     * @return array 版本列表
     * @throws Throwable
     */
    public function getAvailableVersions(string $channel = 'release', ?string $mirror = null): array
    {
        // 验证通道有效性
        if (!ChannelEnum::isValidChannel($channel)) {
            $channel = ChannelEnum::RELEASE->value;
        }

        $mirror ??= $this->getDefaultMirror();

        // 根据通道获取不同的版本列表
        switch ($channel) {
            case ChannelEnum::RELEASE->value:
            case ChannelEnum::PRE_RELEASE->value:
                return $this->getReleasesFromMirror($channel, $mirror);
            case ChannelEnum::DEV->value:
                return $this->getDevVersionsFromMirror($mirror);
            default:
                return [];
        }
    }

    /**
     * 从镜像源获取发布版本列表
     *
     * @param string $channel
     * @param string $mirror
     *
     * @return array
     */
    private function getReleasesFromMirror(string $channel, string $mirror): array
    {
        try {
            $repoInfo = $this->parseMirror($mirror);

            if ($repoInfo['type'] === 'github') {
                return $this->getGithubReleases($repoInfo, $channel);
            }

            // 其他镜像源暂时返回空数组
            return [];
        } catch (\Throwable $e) {
            // 如果获取失败，返回空数组
            return [];
        }
    }

    /**
     * 从GitHub获取发布版本列表
     *
     * @param array  $repoInfo
     * @param string $channel
     *
     * @return array
     */
    private function getGithubReleases(array $repoInfo, string $channel): array
    {
        $url = "https://api.github.com/repos/{$repoInfo['owner']}/{$repoInfo['repo']}/releases";

        $response = HttpClient::get($url, ['per_page' => 50], [
            'Accept: application/vnd.github.v3+json',
            'User-Agent: WindBlog/1.0',
            'timeout: 10',
        ]);

        if (!$response || $response->getStatusCode() !== 200) {
            return [];
        }

        $releases = json_decode((string) $response->getBody(), true);
        if (!$releases) {
            return [];
        }

        // 根据通道筛选版本
        $filteredReleases = $this->filterReleasesByChannel($releases, $channel);

        // 提取版本号列表
        $versions = [];
        foreach ($filteredReleases as $release) {
            $version = ltrim($release['tag_name'], 'v');
            if ($version) {
                $versions[] = $version;
            }
        }

        return $versions;
    }

    /**
     * 从镜像源获取开发版本列表
     *
     * @param string $mirror
     *
     * @return array
     */
    private function getDevVersionsFromMirror(string $mirror): array
    {
        try {
            $repoInfo = $this->parseMirror($mirror);

            if ($repoInfo['type'] === 'github') {
                return $this->getGithubDevVersions($repoInfo);
            }

            // 其他镜像源暂时返回空数组
            return [];
        } catch (\Throwable $e) {
            // 如果获取失败，返回空数组
            return [];
        }
    }

    /**
     * 从GitHub获取开发版本列表
     *
     * @param array $repoInfo
     *
     * @return array
     */
    private function getGithubDevVersions(array $repoInfo): array
    {
        // dev通道通常使用最新的commit信息
        $url = "https://api.github.com/repos/{$repoInfo['owner']}/{$repoInfo['repo']}/commits";

        $response = HttpClient::get($url, ['per_page' => 10], [
            'Accept: application/vnd.github.v3+json',
            'User-Agent: WindBlog/1.0',
            'timeout: 10',
        ]);

        if (!$response || $response->getStatusCode() !== 200) {
            return [];
        }

        $commits = json_decode((string) $response->getBody(), true);
        if (!$commits) {
            return [];
        }

        // 从提交信息中提取版本信息
        $versions = [];
        $baseVersion = $this->currentVersion['version'] ?? '1.0.0';

        foreach ($commits as $index => $commit) {
            $commitSha = substr($commit['sha'], 0, 7);
            $devVersion = "{$baseVersion}-dev.{$commitSha}";
            $versions[] = $devVersion;
        }

        return $versions;
    }

    /**
     * 验证镜像源是否可访问
     *
     * @param string $mirror
     *
     * @return array {valid: bool, message: string, response_time: float}
     */
    public function validateMirror(string $mirror): array
    {
        $startTime = microtime(true);

        try {
            // 解析镜像源
            $repoInfo = $this->parseMirror($mirror);

            $responseTime = (microtime(true) - $startTime) * 1000;

            switch ($repoInfo['type']) {
                case 'github':
                    return $this->validateGithubMirror($repoInfo, $responseTime);
                default:
                    return [
                        'valid' => false,
                        'message' => '不支持的镜像源类型',
                        'response_time' => $responseTime,
                    ];
            }
        } catch (\Throwable $e) {
            $responseTime = (microtime(true) - $startTime) * 1000;

            return [
                'valid' => false,
                'message' => '镜像源验证失败: ' . $e->getMessage(),
                'response_time' => $responseTime,
            ];
        }
    }

    /**
     * 验证GitHub镜像源
     *
     * @param array $repoInfo
     * @param float $responseTime
     *
     * @return array
     */
    private function validateGithubMirror(array $repoInfo, float $responseTime): array
    {
        // 测试API访问
        $url = "https://api.github.com/repos/{$repoInfo['owner']}/{$repoInfo['repo']}";

        $response = HttpClient::get($url, [], [
            'Accept: application/vnd.github.v3+json',
            'User-Agent: WindBlog/1.0',
            'timeout: 10',
        ]);

        if (!$response || $response->getStatusCode() !== 200) {
            return [
                'valid' => false,
                'message' => 'GitHub API访问失败',
                'response_time' => $responseTime,
            ];
        }

        $repo = json_decode((string) $response->getBody(), true);
        if (!$repo) {
            return [
                'valid' => false,
                'message' => 'GitHub API响应解析失败',
                'response_time' => $responseTime,
            ];
        }

        return [
            'valid' => true,
            'message' => '镜像源验证成功',
            'response_time' => $responseTime,
            'repo_info' => [
                'name' => $repo['name'],
                'full_name' => $repo['full_name'],
                'description' => $repo['description'],
                'stars' => $repo['stargazers_count'],
                'forks' => $repo['forks_count'],
                'open_issues' => $repo['open_issues_count'],
                'last_updated' => $repo['updated_at'],
            ],
        ];
    }

    /**
     * 检查是否刚刚完成更新
     *
     * @return bool
     * @throws Throwable
     */
    public function isJustUpdated(): bool
    {
        // 从配置中获取上次版本，对比当前版本
        $lastVersion = blog_config('system_app_version', '', true);
        $currentVersion = $this->currentVersion['version'];

        return $lastVersion !== $currentVersion;
    }
}
