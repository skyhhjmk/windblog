<?php

namespace app\service\updater;

/**
 * 预留的一键升级实现
 */
class ContainerAutoUpdateImpl implements ContainerUpdateInterface
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
        // TODO: 实现一键升级逻辑
        return [
            'message' => '一键升级功能暂未实现',
            'action' => 'auto_update_reserved',
        ];
    }
}
