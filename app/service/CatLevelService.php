<?php

declare(strict_types=1);

namespace app\service;

use support\Log;
use Throwable;

/**
 * CAT 运行级别检测服务
 * 根据系统功能自动判定当前支持的 CAT 级别
 */
class CatLevelService
{
    /**
     * 缓存键名
     */
    private const CACHE_KEY = 'cat_level_info';

    /**
     * 缓存时间（秒）- 1小时
     */
    private const CACHE_TTL = 3600;

    /**
     * 获取完整的级别信息（用于 API 返回）
     *
     * @param bool $forceRefresh 强制刷新缓存
     *
     * @return array
     */
    public static function getLevelInfo(bool $forceRefresh = false): array
    {
        $levelData = self::getCurrentLevel($forceRefresh);

        return [
            'level' => $levelData['level'],
            'description' => $levelData['description'],
            'capabilities' => $levelData['capabilities'],
            'features' => $levelData['features'],
            'badge_html' => self::getLevelBadge($levelData['level']),
            'detected_at' => gmdate('Y-m-d H:i:s'), // 使用 UTC 时间
        ];
    }

    /**
     * 获取当前系统的 CAT 运行级别（带缓存）
     *
     * @param bool $forceRefresh 强制刷新缓存
     *
     * @return array ['level' => 'CAT3E', 'features' => [...], 'description' => '...']
     */
    public static function getCurrentLevel(bool $forceRefresh = false): array
    {
        // 尝试从缓存读取
        if (!$forceRefresh) {
            try {
                $cached = cache(self::CACHE_KEY);
                if ($cached !== null && is_array($cached)) {
                    Log::debug('CAT 级别从缓存读取');

                    return $cached;
                }
            } catch (Throwable $e) {
                Log::warning('读取 CAT 级别缓存失败: ' . $e->getMessage());
            }
        }

        // 缓存不存在或强制刷新，重新检测
        try {
            $features = self::detectFeatures();
            $level = self::calculateLevel($features);

            $result = [
                'level' => $level,
                'features' => $features,
                'description' => self::getLevelDescription($level),
                'capabilities' => self::getLevelCapabilities($level),
            ];

            // 存入缓存
            try {
                cache(self::CACHE_KEY, $result, true, self::CACHE_TTL);
                Log::info('CAT 级别检测完成并缓存: ' . $level);
            } catch (Throwable $e) {
                Log::warning('缓存 CAT 级别失败: ' . $e->getMessage());
            }

            return $result;
        } catch (Throwable $e) {
            Log::error('检测 CAT 级别失败: ' . $e->getMessage());

            return [
                'level' => 'CAT1',
                'features' => [],
                'description' => '基础级别',
                'capabilities' => ['基础信息获取'],
            ];
        }
    }

    /**
     * 检测系统支持的功能
     *
     * @return array
     */
    protected static function detectFeatures(): array
    {
        $features = [];

        // CAT1: 基础功能（默认支持）
        $features['basic_info'] = true;

        // CAT2: 友链监控
        // 检查是否存在 LinkMonitor 进程
        $features['link_monitor'] = class_exists('app\\process\\LinkMonitor');

        // CAT3: 快速互联
        // 检查是否支持 quick-connect 接口
        $features['quick_connect'] = method_exists('app\\controller\\LinkController', 'quickConnect');
        $features['auto_backlink'] = $features['quick_connect']; // 自动回链功能

        // CAT4: 自动审核和排序
        // 检查是否存在 LinkAuditWorker 和 LinkPriorityService
        $features['auto_audit'] = class_exists('app\\process\\LinkAuditWorker');
        $features['auto_priority'] = class_exists('app\\service\\LinkPriorityService');

        // CAT5: 高级推送
        // 检查是否存在 LinkPushWorker
        $features['advanced_push'] = class_exists('app\\process\\LinkPushWorker');

        // 检查是否启用了风屿互联协议
        try {
            $windConnectEnabled = (bool) blog_config('wind_connect_enabled', false, true);
            $features['wind_connect_enabled'] = $windConnectEnabled;
        } catch (Throwable $e) {
            $features['wind_connect_enabled'] = false;
        }

        return $features;
    }

    /**
     * 根据功能计算运行级别
     *
     * @param array $features
     *
     * @return string
     */
    protected static function calculateLevel(array $features): string
    {
        // CAT5: 支持高级推送
        if ($features['advanced_push'] ?? false) {
            return 'CAT5E'; // E 代表基于 Webman 框架的实现
        }

        // CAT4: 支持自动审核和排序
        if (($features['auto_audit'] ?? false) && ($features['auto_priority'] ?? false)) {
            return 'CAT4E';
        }

        // CAT3: 支持快速互联和自动回链
        if (($features['quick_connect'] ?? false) && ($features['auto_backlink'] ?? false)) {
            return 'CAT3E';
        }

        // CAT2: 支持友链监控
        if ($features['link_monitor'] ?? false) {
            return 'CAT2';
        }

        // CAT1: 基础级别
        return 'CAT1';
    }

    /**
     * 获取级别描述
     *
     * @param string $level
     *
     * @return string
     */
    protected static function getLevelDescription(string $level): string
    {
        return match ($level) {
            'CAT5E' => '高级互联级别 - 支持实时推送、自动审核、智能排序',
            'CAT4E' => '自动化级别 - 支持 AI 自动审核和优先级排序',
            'CAT3E' => '快速互联级别 - 支持友链快速申请和自动回链',
            'CAT2' => '监控级别 - 支持友链状态监控',
            'CAT1' => '基础级别 - 支持基础信息获取',
            default => '未知级别',
        };
    }

    /**
     * 获取级别支持的能力列表
     *
     * @param string $level
     *
     * @return array
     */
    protected static function getLevelCapabilities(string $level): array
    {
        $capabilities = [
            'CAT1' => [
                '✅ 基础信息获取',
                '✅ 手动友链管理',
            ],
            'CAT2' => [
                '✅ 基础信息获取',
                '✅ 手动友链管理',
                '✅ 友链状态监控',
                '✅ 反链检测',
            ],
            'CAT3E' => [
                '✅ 基础信息获取',
                '✅ 手动友链管理',
                '✅ 友链状态监控',
                '✅ 反链检测',
                '✅ 快速互联申请',
                '✅ 自动创建回链',
                '✅ Token 验证机制',
            ],
            'CAT4E' => [
                '✅ 基础信息获取',
                '✅ 手动友链管理',
                '✅ 友链状态监控',
                '✅ 反链检测',
                '✅ 快速互联申请',
                '✅ 自动创建回链',
                '✅ Token 验证机制',
                '✅ AI 自动审核',
                '✅ 智能优先级排序',
            ],
            'CAT5E' => [
                '✅ 基础信息获取',
                '✅ 手动友链管理',
                '✅ 友链状态监控',
                '✅ 反链检测',
                '✅ 快速互联申请',
                '✅ 自动创建回链',
                '✅ Token 验证机制',
                '✅ AI 自动审核',
                '✅ 智能优先级排序',
                '✅ 实时信息推送',
                '✅ 友链推荐系统',
            ],
        ];

        return $capabilities[$level] ?? $capabilities['CAT1'];
    }

    /**
     * 获取级别徽章 HTML
     *
     * @param string $level
     *
     * @return string
     */
    public static function getLevelBadge(string $level): string
    {
        $colors = [
            'CAT5E' => '#ff6b6b',
            'CAT4E' => '#4ecdc4',
            'CAT3E' => '#95e1d3',
            'CAT2' => '#feca57',
            'CAT1' => '#dfe6e9',
        ];

        $color = $colors[$level] ?? '#dfe6e9';

        return sprintf(
            '<span class="cat-level-badge" style="background: %s; color: white; padding: 4px 12px; border-radius: 4px; font-weight: bold; font-size: 14px;">%s</span>',
            $color,
            $level
        );
    }

    /**
     * 清除缓存
     *
     * @return bool
     */
    public static function clearCache(): bool
    {
        try {
            cache(self::CACHE_KEY, null);
            Log::info('CAT 级别缓存已清除');

            return true;
        } catch (Throwable $e) {
            Log::error('清除 CAT 级别缓存失败: ' . $e->getMessage());

            return false;
        }
    }
}
