<?php

declare(strict_types=1);

namespace app\service;

/**
 * 风屿互联协议版本管理
 */
class WindConnectVersion
{
    /**
     * 协议版本号
     */
    public const VERSION = '1.0.0';

    /**
     * 协议名称
     */
    public const PROTOCOL_NAME = 'Wind Connect';

    /**
     * 获取协议版本
     */
    public static function getVersion(): string
    {
        return self::VERSION;
    }

    /**
     * 获取协议名称
     */
    public static function getProtocolName(): string
    {
        return self::PROTOCOL_NAME;
    }

    /**
     * 获取完整的协议标识（包含级别）
     *
     * @param string|null $level CAT 级别，如果为 null 则自动检测
     *
     * @return string 例如: "CAT5E/1.0.0"
     */
    public static function getProtocolIdentifier(?string $level = null): string
    {
        if ($level === null) {
            $levelInfo = CatLevelService::getCurrentLevel();
            $level = $levelInfo['level'];
        }

        return $level . '/' . self::VERSION;
    }
}
