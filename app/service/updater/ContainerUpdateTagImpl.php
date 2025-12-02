<?php

namespace app\service\updater;

/**
 * 提示更新容器tag实现
 */
class ContainerUpdateTagImpl implements ContainerUpdateInterface
{
    /**
     * 处理容器更新
     *
     * @param array $versionInfo 版本信息
     *
     * @return array {message: string, action: string}
     */
    public function handleUpdate(array $versionInfo): array
    {
        $currentVersion = $versionInfo['current_version'];
        $latestVersion = $versionInfo['latest_version'];

        return [
            'message' => "发现新版本 {$latestVersion}，当前版本 {$currentVersion}。请更新容器镜像标签至最新版本。",
            'action' => 'tag_update',
        ];
    }
}
