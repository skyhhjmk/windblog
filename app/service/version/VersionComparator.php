<?php

namespace app\service\version;

/**
 * 版本比较工具类
 */
class VersionComparator
{
    /**
     * 检查版本1是否大于版本2
     *
     * @param string $version1
     * @param string $version2
     *
     * @return bool
     */
    public static function isGreaterThan(string $version1, string $version2): bool
    {
        return self::compare($version1, $version2) > 0;
    }

    /**
     * 比较两个版本号
     *
     * @param string $version1 第一个版本号
     * @param string $version2 第二个版本号
     *
     * @return int -1: version1 < version2, 0: version1 == version2, 1: version1 > version2
     */
    public static function compare(string $version1, string $version2): int
    {
        $v1Parts = self::parseVersion($version1);
        $v2Parts = self::parseVersion($version2);

        // 比较主版本、次版本、修订号
        for ($i = 0; $i < 3; $i++) {
            if ($v1Parts[$i] < $v2Parts[$i]) {
                return -1;
            } elseif ($v1Parts[$i] > $v2Parts[$i]) {
                return 1;
            }
        }

        // 比较预发布标签
        if ($v1Parts[3] && !$v2Parts[3]) {
            return -1;
        } elseif (!$v1Parts[3] && $v2Parts[3]) {
            return 1;
        } elseif ($v1Parts[3] && $v2Parts[3]) {
            return strcmp($v1Parts[3], $v2Parts[3]);
        }

        return 0;
    }

    /**
     * 解析版本号为数组
     *
     * @param string $version
     *
     * @return array [major, minor, patch, pre_release]
     */
    private static function parseVersion(string $version): array
    {
        // 移除前缀 'v' 或 'V'
        $version = ltrim($version, 'vV');

        // 分割版本号和预发布标签
        $parts = explode('-', $version, 2);
        $versionCore = $parts[0];
        $preRelease = $parts[1] ?? '';

        // 分割主版本、次版本、修订号
        $versionNumbers = explode('.', $versionCore);

        // 确保数组长度为3
        $versionNumbers = array_pad($versionNumbers, 3, 0);

        // 转换为整数
        $major = (int) $versionNumbers[0];
        $minor = (int) $versionNumbers[1];
        $patch = (int) $versionNumbers[2];

        return [$major, $minor, $patch, $preRelease];
    }

    /**
     * 检查版本1是否小于版本2
     *
     * @param string $version1
     * @param string $version2
     *
     * @return bool
     */
    public static function isLessThan(string $version1, string $version2): bool
    {
        return self::compare($version1, $version2) < 0;
    }

    /**
     * 检查两个版本是否相等
     *
     * @param string $version1
     * @param string $version2
     *
     * @return bool
     */
    public static function isEqual(string $version1, string $version2): bool
    {
        return self::compare($version1, $version2) === 0;
    }
}
