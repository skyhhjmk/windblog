<?php

namespace app\service\updater;

/**
 * 容器更新接口
 */
interface ContainerUpdateInterface
{
    /**
     * 处理容器更新
     *
     * @param array $versionInfo 版本信息
     *
     * @return array {message: string, action: string}
     */
    public function handleUpdate(array $versionInfo): array;
}
