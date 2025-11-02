<?php

namespace app\service;

use GuzzleHttp\Client;
use support\Log;
use support\Redis;
use Throwable;

/**
 * 地理位置服务
 * 通过 IP 获取地理位置信息
 *
 * @deprecated 该服务已弃用，不应在业务逻辑中直接调用会导致阻塞的外部 API
 * @deprecated 应由前端获取用户地理位置信息并通过 POST 参数传递给服务器
 * @deprecated 请参考 docs/frontend-location-guide.md 了解如何在前端实现位置获取
 */
class LocationService
{
    /**
     * IP 地理位置 API
     */
    private const IP_API_URL = 'https://ipip.rehi.org/json';

    /**
     * Redis 缓存键前缀
     */
    private const CACHE_PREFIX = 'location:ip:';

    /**
     * 缓存过期时间（秒）- 24小时
     */
    private const CACHE_TTL = 86400;

    /**
     * HTTP 客户端
     */
    private Client $httpClient;

    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->httpClient = new Client([
            'timeout' => 5,
            'verify' => false,
        ]);
    }

    /**
     * 批量获取地理位置信息
     *
     * @param array $ips IP 地址数组
     *
     * @return array IP => 地理位置信息的映射
     *@deprecated 该方法会导致线程阻塞，请使用前端位置获取方案
     */
    public function batchGetLocation(array $ips): array
    {
        $result = [];

        foreach ($ips as $ip) {
            $location = $this->getLocationByIp($ip);
            if ($location !== null) {
                $result[$ip] = $location;
            }
        }

        return $result;
    }

    /**
     * 通过 IP 获取地理位置信息
     *
     * @param string $ip IP 地址（可以为空，则自动获取外部IP）
     *
     * @return array|null 地理位置信息，失败返回 null
     * @deprecated 然后通过 POST 参数 'location' 传递给服务器
     * @deprecated 该方法会导致线程阻塞，请使用前端位置获取方案
     * @deprecated 前端应使用浏览器 Geolocation API 或第三方 IP 定位服务获取位置
     */
    public function getLocationByIp(string $ip = ''): ?array
    {
        // 如果 IP 为空或为内网 IP，则直接调用 API 获取真实外部 IP
        if (empty($ip) || $this->isPrivateIp($ip)) {
            return $this->getLocationFromApi();
        }

        // 尝试从缓存读取
        try {
            $cached = $this->getFromCache($ip);
            if ($cached !== null) {
                return $cached;
            }
        } catch (Throwable $e) {
            Log::warning('Failed to get location from cache: ' . $e->getMessage());
        }

        // 调用 API 获取（使用 X-Forwarded-For 传递指定 IP）
        return $this->getLocationFromApi($ip);
    }

    /**
     * 检查是否为内网 IP
     *
     * @param string $ip IP 地址
     *
     * @return bool
     */
    private function isPrivateIp(string $ip): bool
    {
        // 检查是否为 IPv4 内网地址
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $longIp = ip2long($ip);
            if ($longIp === false) {
                return false;
            }

            // 10.0.0.0 - 10.255.255.255
            if ($longIp >= ip2long('10.0.0.0') && $longIp <= ip2long('10.255.255.255')) {
                return true;
            }

            // 172.16.0.0 - 172.31.255.255
            if ($longIp >= ip2long('172.16.0.0') && $longIp <= ip2long('172.31.255.255')) {
                return true;
            }

            // 192.168.0.0 - 192.168.255.255
            if ($longIp >= ip2long('192.168.0.0') && $longIp <= ip2long('192.168.255.255')) {
                return true;
            }

            // 127.0.0.0 - 127.255.255.255
            if ($longIp >= ip2long('127.0.0.0') && $longIp <= ip2long('127.255.255.255')) {
                return true;
            }

            return false;
        }

        // 检查是否为 IPv6 内网地址
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // ::1 (localhost)
            if ($ip === '::1') {
                return true;
            }

            // fe80::/10 (link-local)
            if (str_starts_with(strtolower($ip), 'fe80:')) {
                return true;
            }

            // fc00::/7 (unique local)
            if (str_starts_with(strtolower($ip), 'fc') || str_starts_with(strtolower($ip), 'fd')) {
                return true;
            }

            return false;
        }

        return false;
    }

    /**
     * 直接调用 API 获取地理位置（不传 IP 则自动使用请求来源 IP）
     *
     * @param string $ip IP 地址（可选）
     *
     * @return array|null
     */
    private function getLocationFromApi(string $ip = ''): ?array
    {
        try {
            $headers = [
                'User-Agent' => 'Mozilla/5.0 (compatible; WindBlog/1.0)',
            ];

            // 如果指定了 IP，通过 X-Forwarded-For 传递
            if (!empty($ip)) {
                $headers['X-Forwarded-For'] = $ip;
            }

            $response = $this->httpClient->get(self::IP_API_URL, [
                'headers' => $headers,
            ]);

            $body = $response->getBody()->getContents();
            $data = json_decode($body, true);

            if (!$data || !isset($data['country'])) {
                Log::error('Invalid location API response', ['ip' => $ip, 'response' => $body]);

                return null;
            }

            // 格式化地理位置信息
            $location = $this->formatLocation($data);

            // 存入缓存（使用返回的真实 IP 作为键）
            $realIp = $location['ip'] ?? $ip;
            if ($realIp) {
                try {
                    $this->saveToCache($realIp, $location);
                } catch (Throwable $e) {
                    Log::warning('Failed to save location to cache: ' . $e->getMessage());
                }
            }

            return $location;
        } catch (Throwable $e) {
            Log::error('Failed to get location from API: ' . $e->getMessage(), ['ip' => $ip]);

            return null;
        }
    }

    /**
     * 格式化地理位置信息
     *
     * @param array $data API 返回的原始数据
     *
     * @return array 格式化后的地理位置信息
     */
    private function formatLocation(array $data): array
    {
        $country = $data['country'] ?? '';
        $province = $data['province'] ?? '';
        $city = $data['city'] ?? '';
        $isp = $data['isp'] ?? '';

        // 构建位置字符串
        $locationParts = array_filter([$country, $province, $city]);
        $location = implode(' ', $locationParts);

        // 构建显示字符串
        $display = $this->buildDisplayString($country, $province, $city, $isp);

        return [
            'ip' => $data['ip'] ?? '',
            'country' => $country,
            'country_code' => $data['country_code'] ?? '',
            'province' => $province,
            'city' => $city,
            'district' => $data['district'] ?? '',
            'isp' => $isp,
            'asn' => $data['asn'] ?? '',
            'usage_type' => $data['usage_type'] ?? '',
            'latitude' => $data['latitude'] ?? '',
            'longitude' => $data['longitude'] ?? '',
            'timezone' => $data['timezone'] ?? '',
            'location' => $location,
            'display' => $display,
        ];
    }

    /**
     * 构建显示字符串
     *
     * @param string $country  国家
     * @param string $province 省份
     * @param string $city     城市
     * @param string $isp      运营商
     *
     * @return string 显示字符串
     */
    private function buildDisplayString(string $country, string $province, string $city, string $isp): string
    {
        // 中国大陆特殊处理
        if ($country === '中国' || $country === 'China') {
            // 优先显示城市
            if ($city && $city !== $province) {
                return $city;
            }
            // 如果没有城市或城市与省份相同，显示省份
            if ($province) {
                // 如果省份是英文，直接使用
                return $province;
            }

            return '中国';
        }

        // 其他国家
        if ($city) {
            return $city . ', ' . $country;
        }
        if ($province) {
            return $province . ', ' . $country;
        }

        return $country ?: '未知地区';
    }

    /**
     * 保存地理位置信息到缓存
     *
     * @param string $ip       IP 地址
     * @param array  $location 地理位置信息
     *
     * @return void
     */
    private function saveToCache(string $ip, array $location): void
    {
        try {
            $redis = Redis::connection();
            $redis->setex(
                self::CACHE_PREFIX . $ip,
                self::CACHE_TTL,
                json_encode($location)
            );
        } catch (Throwable $e) {
            Log::warning('Redis cache write failed: ' . $e->getMessage());
        }
    }

    /**
     * 从缓存获取地理位置信息
     *
     * @param string $ip IP 地址
     *
     * @return array|null
     */
    private function getFromCache(string $ip): ?array
    {
        try {
            $redis = Redis::connection();
            $cached = $redis->get(self::CACHE_PREFIX . $ip);

            if ($cached === false || $cached === null) {
                return null;
            }

            $data = json_decode($cached, true);

            return is_array($data) ? $data : null;
        } catch (Throwable $e) {
            Log::warning('Redis cache read failed: ' . $e->getMessage());

            return null;
        }
    }
}
