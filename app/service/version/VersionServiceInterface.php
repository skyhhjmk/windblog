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
     *
     * @return array {has_new_version: bool, current_version: string, latest_version: string, release_url: string,
     *               channel: string}
     */
    public function checkVersion(string $channel = 'release', ?string $mirror = null): array;

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
}
