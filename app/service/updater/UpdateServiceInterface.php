<?php

namespace app\service\updater;

/**
 * 更新服务接口
 */
interface UpdateServiceInterface
{
    /**
     * 执行更新
     *
     * @param string|null $version 目标版本（null表示更新到最新）
     * @param string      $channel 更新通道
     * @param string|null $mirror  自定义镜像源
     *
     * @return array {success: bool, message: string, log: array}
     */
    public function update(?string $version = null, string $channel = 'release', ?string $mirror = null): array;

    /**
     * 执行数据库迁移
     *
     * @return array {success: bool, message: string, migrations: array}
     */
    public function runMigrations(): array;

    /**
     * 同步主分支代码（dev通道）
     *
     * @param string|null $mirror 自定义镜像源
     *
     * @return array {success: bool, message: string, log: array}
     */
    public function syncMainBranch(?string $mirror = null): array;
}
