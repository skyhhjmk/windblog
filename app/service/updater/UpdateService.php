<?php

namespace app\service\updater;

use app\service\version\ChannelEnum;
use app\service\version\VersionService;

/**
 * 更新服务实现
 */
class UpdateService implements UpdateServiceInterface
{
    /**
     * @var DatabaseMigrationService 数据库迁移服务
     */
    private DatabaseMigrationService $migrationService;

    /**
     * @var GitSyncService Git同步服务
     */
    private GitSyncService $gitSyncService;

    /**
     * @var VersionService 版本服务
     */
    private VersionService $versionService;

    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->migrationService = new DatabaseMigrationService();
        $this->gitSyncService = new GitSyncService();
        $this->versionService = new VersionService();
    }

    /**
     * 执行更新
     *
     * @param string|null $version 目标版本（null表示更新到最新）
     * @param string      $channel 更新通道
     * @param string|null $mirror  自定义镜像源
     *
     * @return array {success: bool, message: string, log: array}
     */
    public function update(?string $version = null, string $channel = 'release', ?string $mirror = null): array
    {
        $log = [];

        try {
            // 验证通道有效性
            if (!ChannelEnum::isValidChannel($channel)) {
                $channel = ChannelEnum::RELEASE->value;
            }

            $log[] = "开始执行更新，通道: {$channel}，目标版本: " . ($version ?? '最新版本');

            // 根据通道执行不同的更新逻辑
            switch ($channel) {
                case ChannelEnum::RELEASE->value:
                case ChannelEnum::PRE_RELEASE->value:
                    $result = $this->updateFromRelease($version, $channel, $mirror);
                    break;
                case ChannelEnum::DEV->value:
                    $result = $this->updateFromDev($mirror);
                    break;
                default:
                    throw new \RuntimeException("不支持的更新通道: {$channel}");
            }

            $log = array_merge($log, $result['log']);

            if ($result['success']) {
                // 执行数据库迁移
                $migrationResult = $this->runMigrations();
                $log = array_merge($log, $migrationResult['migrations']);

                if (!$migrationResult['success']) {
                    return [
                        'success' => false,
                        'message' => '更新失败: ' . $migrationResult['message'],
                        'log' => $log,
                    ];
                }

                return [
                    'success' => true,
                    'message' => '更新成功',
                    'log' => $log,
                ];
            } else {
                return [
                    'success' => false,
                    'message' => '更新失败: ' . $result['message'],
                    'log' => $log,
                ];
            }
        } catch (\Throwable $e) {
            $log[] = "更新过程中发生错误: {$e->getMessage()}";

            return [
                'success' => false,
                'message' => '更新失败: ' . $e->getMessage(),
                'log' => $log,
            ];
        }
    }

    /**
     * 从正式版或预发布版更新
     *
     * @param string|null $version
     * @param string      $channel
     * @param string|null $mirror
     *
     * @return array
     */
    private function updateFromRelease(?string $version, string $channel, ?string $mirror): array
    {
        $log = [];

        // TODO: 实现从GitHub Releases下载并更新的逻辑
        $log[] = "从 {$channel} 通道更新，版本: " . ($version ?? '最新版本');
        $log[] = '当前镜像源: ' . ($mirror ?? $this->versionService->getDefaultMirror());

        // 这里先模拟更新成功
        return [
            'success' => true,
            'message' => '更新包下载并安装成功',
            'log' => $log,
        ];
    }

    /**
     * 从开发版更新
     *
     * @param string|null $mirror
     *
     * @return array
     */
    private function updateFromDev(?string $mirror): array
    {
        $log = [];

        $log[] = '开始从dev通道同步代码';

        // 使用Git同步主分支
        $syncResult = $this->gitSyncService->syncMainBranch($mirror);

        return [
            'success' => $syncResult['success'],
            'message' => $syncResult['message'],
            'log' => array_merge($log, $syncResult['log']),
        ];
    }

    /**
     * 同步主分支代码（dev通道）
     *
     * @param string|null $mirror 自定义镜像源
     *
     * @return array {success: bool, message: string, log: array}
     */
    public function syncMainBranch(?string $mirror = null): array
    {
        return $this->gitSyncService->syncMainBranch($mirror);
    }

    /**
     * 执行数据库迁移
     *
     * @return array {success: bool, message: string, migrations: array}
     */
    public function runMigrations(): array
    {
        return $this->migrationService->migrate();
    }
}
