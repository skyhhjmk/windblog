<?php

namespace app\service;

use Exception;

/**
 * URL 安全服务
 * 负责 URL 的安全验证和过滤，防止 SSRF 攻击
 */
class UrlSecurityService
{
    /**
     * 允许的协议列表
     */
    private const ALLOWED_PROTOCOLS = ['http', 'https'];

    /**
     * 获取安全的 URL
     * 如果 URL 不安全，返回 null
     *
     * @param string $url 要验证的 URL
     *
     * @return string|null 安全的 URL 或 null
     */
    public static function getSafeUrl(string $url): ?string
    {
        if (self::isSafeUrl($url)) {
            return $url;
        }

        return null;
    }

    /**
     * 验证 URL 是否安全
     *
     * @param string $url 要验证的 URL
     *
     * @return bool 是否安全
     */
    public static function isSafeUrl(string $url): bool
    {
        try {
            // 解析 URL
            $parsedUrl = parse_url($url);
            if ($parsedUrl === false) {
                return false;
            }

            // 检查协议
            if (!isset($parsedUrl['scheme']) || !in_array($parsedUrl['scheme'], self::ALLOWED_PROTOCOLS)) {
                return false;
            }

            // 检查主机
            if (!isset($parsedUrl['host'])) {
                return false;
            }

            $host = $parsedUrl['host'];

            // 检查是否为 IP 地址
            if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
                // 是 IP 地址，检查是否为私有 IP 或内部 IP
                return !self::isPrivateOrInternalIp($host);
            }

            // 是域名，检查是否为本地域名
            return !self::isLocalDomain($host);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * 检查 IP 地址是否为私有 IP 或内部 IP
     *
     * @param string $ip IP 地址
     *
     * @return bool 是否为私有 IP 或内部 IP
     */
    private static function isPrivateOrInternalIp(string $ip): bool
    {
        // IPv4 私有地址范围
        $privateIpRanges = [
            '10.0.0.0/8',      // A 类私有地址
            '172.16.0.0/12',   // B 类私有地址
            '192.168.0.0/16',  // C 类私有地址
            '127.0.0.0/8',     // 回环地址
            '169.254.0.0/16',  // 链路本地地址
            '0.0.0.0/8',       // 无效地址
            '240.0.0.0/4',     // 保留地址
        ];

        // 检查 IPv4
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            foreach ($privateIpRanges as $range) {
                if (self::ipInRange($ip, $range)) {
                    return true;
                }
            }

            return false;
        }

        // 检查 IPv6
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            // IPv6 本地地址
            return str_starts_with($ip, '::1') || // 回环地址
                str_starts_with($ip, 'fe80:') || // 链路本地地址
                str_starts_with($ip, 'fc00:') || // 唯一本地地址
                str_starts_with($ip, 'fd00:');   // 唯一本地地址
        }

        return false;
    }

    /**
     * 检查 IP 地址是否在指定范围内
     *
     * @param string $ip    IP 地址
     * @param string $range IP 范围（如 192.168.0.0/16）
     *
     * @return bool 是否在范围内
     */
    private static function ipInRange(string $ip, string $range): bool
    {
        [$range, $netmask] = explode('/', $range, 2);
        $rangeDecimal = ip2long($range);
        $ipDecimal = ip2long($ip);
        $wildcardDecimal = pow(2, (32 - $netmask)) - 1;
        $netmaskDecimal = ~$wildcardDecimal;

        return ($ipDecimal & $netmaskDecimal) == ($rangeDecimal & $netmaskDecimal);
    }

    /**
     * 检查域名是否为本地域名
     *
     * @param string $domain 域名
     *
     * @return bool 是否为本地域名
     */
    private static function isLocalDomain(string $domain): bool
    {
        $localDomains = [
            'localhost',
            'localhost.localdomain',
            'local',
            'test',
        ];

        // 转换为小写
        $domain = strtolower($domain);

        // 检查是否为精确匹配
        if (in_array($domain, $localDomains)) {
            return true;
        }

        // 检查是否为本地域名的子域名
        foreach ($localDomains as $localDomain) {
            if (str_ends_with($domain, '.' . $localDomain)) {
                return true;
            }
        }

        return false;
    }
}
