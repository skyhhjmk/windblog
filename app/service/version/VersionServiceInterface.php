<?php

namespace app\service\version;

/**
 * 版本服务接口
 */
interface VersionServiceInterface
{
    /**
     * 检查是否有新版本
     *
     * @param string      $channel 更新通道（release/pre-release/dev）
     * @param string|null $mirror  自定义镜像源（null表示使用配置中的默认值）
     * @param bool $force 是否强制刷新缓存
     *
     * @return array {has_new_version: bool, current_version: string, latest_version: string, release_url: string,
     *               channel: string, available_versions: array, cached: bool, default_mirror: string, update_available: bool}
     */
    public function checkVersion(string $channel = 'release', ?string $mirror = null, bool $force = false): array;

    /**
     * 检查是否刚刚完成更新
     *
     * @return bool
     */
    public function isJustUpdated(): bool;

    /**
     * 获取指定通道的所有可用版本
     *
     * @param string      $channel 更新通道
     * @param string|null $mirror  自定义镜像源
     *
     * @return array 版本列表
     */
    public function getAvailableVersions(string $channel = 'release', ?string $mirror = null): array;

    /**
     * 获取当前配置的默认镜像源
     *
     * @return string
     */
    public function getDefaultMirror(): string;

    /**
     * 验证镜像源是否有效
     *
     * @param string $mirror
     *
     * @return array {valid: bool, message: string, response_time: float}
     */
    public function validateMirror(string $mirror): array;

    /**
     * 清除版本检查缓存
     *
     * @param string|null $channel
     * @param string|null $mirror
     *
     * @return bool
     */
    public function clearVersionCache(?string $channel = null, ?string $mirror = null): bool;

    /**
     * 获取版本检查统计信息
     *
     * @return array
     */
    public function getVersionStats(): array;

    /**
     * 预热版本检查缓存
     *
     * @param array $channels
     *
     * @return bool
     */
    public function warmupVersionCache(array $channels = ['release', 'pre-release', 'dev']): bool;
}
