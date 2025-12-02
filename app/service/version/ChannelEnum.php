<?php

namespace app\service\version;

/**
 * 更新通道枚举
 */
enum ChannelEnum: string
{
    /**
     * 稳定正式版
     */
    case RELEASE = 'release';

    /**
     * 预发布版
     */
    case PRE_RELEASE = 'pre-release';

    /**
     * 开发版
     */
    case DEV = 'dev';

    /**
     * 检查通道是否有效
     *
     * @param string $channel
     *
     * @return bool
     */
    public static function isValidChannel(string $channel): bool
    {
        return in_array($channel, self::getAllChannels());
    }

    /**
     * 获取所有可用通道
     *
     * @return array
     */
    public static function getAllChannels(): array
    {
        return array_column(self::cases(), 'value');
    }
}
