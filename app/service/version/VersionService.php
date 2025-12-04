<?php

namespace app\service\version;

use RuntimeException;
use Throwable;

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
     *
     * @return array {has_new_version: bool, current_version: string, latest_version: string, release_url: string,
     *               channel: string, available_versions: array}
     * @throws Throwable
     */
    public function checkVersion(string $channel = 'release', ?string $mirror = null): array
    {
        // 验证通道有效性
        if (!ChannelEnum::isValidChannel($channel)) {
            $channel = ChannelEnum::RELEASE->value;
        }

        $mirror ??= $this->getDefaultMirror();
        $latestVersion = $this->getLatestVersionFromMirror($channel, $mirror);
        $availableVersions = $this->getAvailableVersions($channel, $mirror);

        $currentVersion = $this->currentVersion['version'];
        $hasNewVersion = VersionComparator::isLessThan($currentVersion, $latestVersion['version']);

        return [
            'has_new_version' => $hasNewVersion,
            'current_version' => $currentVersion,
            'latest_version' => $latestVersion['version'],
            'release_url' => $latestVersion['release_url'] ?? '',
            'channel' => $channel,
            'available_versions' => $availableVersions,
        ];
    }

    /**
     * 获取当前配置的默认镜像源
     *
     * @return string
     * @throws Throwable
     */
    public function getDefaultMirror(): string
    {
        return blog_config('update_mirror', 'https://github.com/skyhhjmk/windblog', true);
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
        // TODO: 实现从GitHub API或自定义镜像源获取最新版本的逻辑
        // 这里先返回当前版本作为示例
        return [
            'version' => $this->currentVersion['version'],
            'release_url' => $mirror,
        ];
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
        // 模拟从镜像源获取发布版本列表
        // TODO: 实现从GitHub API或自定义镜像源获取发布版本列表的逻辑
        return [
            '1.0.1',
            '1.0.2',
            '1.0.3',
            '1.1.0',
            '1.1.1',
        ];
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
        // 模拟从镜像源获取开发版本列表
        // TODO: 实现从GitHub API或自定义镜像源获取开发版本列表的逻辑
        return [
            '1.2.0-dev.1',
            '1.2.0-dev.2',
            '1.2.0-dev.3',
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
