<?php

declare(strict_types=1);

namespace app\service;

use app\model\Link;
use support\Log;
use Throwable;

/**
 * 友链优先级计算服务 (CAT4功能)
 * 根据反链位置、监控数据等自动计算友链优先级并排序
 */
class LinkPriorityService
{
    /**
     * 批量更新友链的sort_order
     * 根据优先级自动设置sort_order (优先级越高，sort_order越小)
     *
     * @param array|null $linkIds 指定友链ID数组，null表示全部友链
     *
     * @return int 更新的友链数量
     */
    public static function updateSortOrder(?array $linkIds = null): int
    {
        try {
            $query = Link::query();

            if ($linkIds !== null) {
                $query->whereIn('id', $linkIds);
            }

            $links = $query->get();
            $updated = 0;

            // 计算每个友链的优先级并存储
            $linksWithPriority = [];
            foreach ($links as $link) {
                $priority = self::calculatePriority($link);
                $linksWithPriority[] = [
                    'link' => $link,
                    'priority' => $priority,
                ];
            }

            // 按优先级降序排序
            usort($linksWithPriority, function ($a, $b) {
                return $b['priority'] <=> $a['priority'];
            });

            // 更新sort_order (从10开始，每次+10)
            $sortOrder = 10;
            foreach ($linksWithPriority as $item) {
                /** @var Link $link */
                $link = $item['link'];
                $oldSortOrder = $link->sort_order;

                // 只有当sort_order变化时才更新
                if ($oldSortOrder !== $sortOrder) {
                    $link->sort_order = $sortOrder;

                    // 将优先级也存到custom_fields供调试
                    $link->setCustomField('calculated_priority', $item['priority']);
                    $link->setCustomField('auto_sorted_at', utc_now_string('Y-m-d H:i:s'));

                    $link->save();
                    $updated++;

                    Log::debug('友链排序更新', [
                        'link_id' => $link->id,
                        'name' => $link->name,
                        'priority' => $item['priority'],
                        'old_sort' => $oldSortOrder,
                        'new_sort' => $sortOrder,
                    ]);
                }

                $sortOrder += 10;
            }

            Log::info('友链自动排序完成', [
                'total_links' => count($links),
                'updated' => $updated,
            ]);

            return $updated;
        } catch (Throwable $e) {
            Log::error('友链批量排序失败: ' . $e->getMessage());

            return 0;
        }
    }

    /**
     * 计算单个友链的优先级得分
     *
     * @param Link $link 友链对象
     *
     * @return int 优先级得分 (0-100，越高越优先)
     */
    public static function calculatePriority(Link $link): int
    {
        $score = 50; // 基础得分

        try {
            $customFields = $link->custom_fields ?: [];

            // 1. 反链位置权重 (30分)
            $linkPosition = $customFields['link_position'] ?? '';
            $score += match ($linkPosition) {
                'homepage' => 30,      // 首页反链最高权重
                'link_page' => 20,     // 友链页次之
                'other_page' => 10,    // 其他页面较低
                default => 0,
            };

            // 2. 监控数据加分 (20分)
            $monitor = $customFields['monitor'] ?? [];
            if (!empty($monitor)) {
                // 网站可访问性
                if (($monitor['ok'] ?? false) === true) {
                    $score += 5;
                }

                // 反链存在性
                $backlink = $monitor['backlink'] ?? [];
                if (($backlink['found'] ?? false) === true) {
                    $score += 10;
                    // 反链数量额外加分
                    $linkCount = (int) ($backlink['count'] ?? 0);
                    $score += min(5, $linkCount); // 最多加5分
                }
            }

            // 3. AI审核加分 (10分)
            $aiAuditScore = (float) ($customFields['ai_audit_score'] ?? 0);
            if ($aiAuditScore > 0) {
                $score += (int) ($aiAuditScore / 10); // AI分数0-100映射到0-10
            }

            // 4. 协议支持加分 (10分)
            $peerProtocol = $customFields['peer_protocol'] ?? '';
            if ($peerProtocol === 'wind_connect') {
                $score += 10;
            }

            // 5. 扣分项
            // 长时间未监控扣分
            if (isset($monitor['time'])) {
                $lastMonitorTime = strtotime($monitor['time']);
                $daysSinceMonitor = (time() - $lastMonitorTime) / 86400;
                if ($daysSinceMonitor > 30) {
                    $score -= 10;
                } elseif ($daysSinceMonitor > 14) {
                    $score -= 5;
                }
            }

            // AI审核未通过扣分
            $aiAuditStatus = $customFields['ai_audit_status'] ?? '';
            if ($aiAuditStatus === 'rejected' || $aiAuditStatus === 'spam') {
                $score -= 20;
            }

        } catch (Throwable $e) {
            Log::warning('计算友链优先级失败: ' . $e->getMessage(), [
                'link_id' => $link->id,
                'link_url' => $link->url,
            ]);
        }

        // 限制得分范围
        return max(0, min(100, $score));
    }

    /**
     * 获取排序规则说明
     *
     * @return array 排序规则
     */
    public static function getSortingRules(): array
    {
        return [
            'base_score' => 50,
            'rules' => [
                [
                    'name' => '反链位置',
                    'weight' => 30,
                    'details' => [
                        'homepage' => '+30分',
                        'link_page' => '+20分',
                        'other_page' => '+10分',
                    ],
                ],
                [
                    'name' => '监控数据',
                    'weight' => 20,
                    'details' => [
                        'site_accessible' => '+5分',
                        'backlink_found' => '+10分',
                        'backlink_count' => '每个反链+1分(最多5分)',
                    ],
                ],
                [
                    'name' => 'AI审核评分',
                    'weight' => 10,
                    'details' => [
                        'ai_score' => 'AI分数(0-100)映射到0-10分',
                    ],
                ],
                [
                    'name' => '协议支持',
                    'weight' => 10,
                    'details' => [
                        'wind_connect' => '+10分',
                    ],
                ],
                [
                    'name' => '扣分项',
                    'weight' => -20,
                    'details' => [
                        'no_monitor_30days' => '-10分',
                        'no_monitor_14days' => '-5分',
                        'ai_rejected' => '-20分',
                    ],
                ],
            ],
        ];
    }
}
