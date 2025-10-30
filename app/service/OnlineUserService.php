<?php

namespace app\service;

use support\Redis;
use Webman\Push\Api;

/**
 * 在线用户统计服务
 */
class OnlineUserService
{
    /**
     * Redis键前缀
     */
    private const ONLINE_USERS_KEY = 'online_users';
    private const ONLINE_USER_INFO_PREFIX = 'online_user_info:';
    private const ONLINE_COUNT_KEY = 'online_count';

    /**
     * 用户在线超时时间（秒）
     */
    private const USER_ONLINE_TIMEOUT = 300; // 5分钟

    /**
     * 用户上线
     *
     * @param int   $userId   用户ID
     * @param array $userInfo 用户信息（可选）
     *
     * @return bool
     */
    public function userOnline(int $userId, array $userInfo = []): bool
    {
        try {
            $redis = Redis::connection();

            // 添加用户到在线用户集合
            $redis->zAdd(self::ONLINE_USERS_KEY, time(), $userId);

            // 存储用户信息
            if (!empty($userInfo)) {
                $userInfo['online_at'] = time();
                $redis->setex(
                    self::ONLINE_USER_INFO_PREFIX . $userId,
                    self::USER_ONLINE_TIMEOUT,
                    json_encode($userInfo)
                );
            }

            // 更新在线人数统计
            $this->updateOnlineCount();

            return true;
        } catch (\Throwable $e) {
            \support\Log::error('User online failed: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * 更新在线人数统计
     *
     * @return void
     */
    private function updateOnlineCount(): void
    {
        try {
            $redis = Redis::connection();
            $count = $redis->zCard(self::ONLINE_USERS_KEY);
            $redis->setex(self::ONLINE_COUNT_KEY, 60, $count);
        } catch (\Throwable $e) {
            \support\Log::error('Update online count failed: ' . $e->getMessage());
        }
    }

    /**
     * 用户心跳（延长在线状态）
     *
     * @param int $userId 用户ID
     *
     * @return bool
     */
    public function userHeartbeat(int $userId): bool
    {
        try {
            $redis = Redis::connection();

            // 更新用户在线时间戳
            $redis->zAdd(self::ONLINE_USERS_KEY, time(), $userId);

            // 延长用户信息过期时间
            $userInfoKey = self::ONLINE_USER_INFO_PREFIX . $userId;
            if ($redis->exists($userInfoKey)) {
                $redis->expire($userInfoKey, self::USER_ONLINE_TIMEOUT);
            }

            return true;
        } catch (\Throwable $e) {
            \support\Log::error('User heartbeat failed: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * 获取在线用户列表
     *
     * @param int $page     页码
     * @param int $pageSize 每页数量
     *
     * @return array
     */
    public function getOnlineUsers(int $page = 1, int $pageSize = 20): array
    {
        try {
            $redis = Redis::connection();

            // 清理过期用户
            $this->cleanExpiredUsers();

            $offset = ($page - 1) * $pageSize;

            // 获取在线用户ID列表（按上线时间倒序）
            $userIds = $redis->zRevRange(self::ONLINE_USERS_KEY, $offset, $offset + $pageSize - 1);

            if (empty($userIds)) {
                return [
                    'total' => 0,
                    'page' => $page,
                    'page_size' => $pageSize,
                    'users' => [],
                ];
            }

            // 获取用户信息
            $users = [];
            foreach ($userIds as $userId) {
                $userInfo = $redis->get(self::ONLINE_USER_INFO_PREFIX . $userId);
                if ($userInfo) {
                    $users[] = json_decode($userInfo, true);
                } else {
                    // 如果没有详细信息，只返回用户ID
                    $users[] = ['user_id' => $userId];
                }
            }

            $total = $redis->zCard(self::ONLINE_USERS_KEY);

            return [
                'total' => $total,
                'page' => $page,
                'page_size' => $pageSize,
                'users' => $users,
            ];
        } catch (\Throwable $e) {
            \support\Log::error('Get online users failed: ' . $e->getMessage());

            return [
                'total' => 0,
                'page' => $page,
                'page_size' => $pageSize,
                'users' => [],
            ];
        }
    }

    /**
     * 清理过期用户
     *
     * @return int 清理的用户数量
     */
    public function cleanExpiredUsers(): int
    {
        try {
            $redis = Redis::connection();

            $timeout = time() - self::USER_ONLINE_TIMEOUT;

            // 移除超时的用户
            $count = $redis->zRemRangeByScore(self::ONLINE_USERS_KEY, 0, $timeout);

            if ($count > 0) {
                // 更新在线人数统计
                $this->updateOnlineCount();
            }

            return $count;
        } catch (\Throwable $e) {
            \support\Log::error('Clean expired users failed: ' . $e->getMessage());

            return 0;
        }
    }

    /**
     * 检查用户是否在线
     *
     * @param int $userId 用户ID
     *
     * @return bool
     */
    public function isUserOnline(int $userId): bool
    {
        try {
            $redis = Redis::connection();
            $score = $redis->zScore(self::ONLINE_USERS_KEY, $userId);

            if ($score === false) {
                return false;
            }

            // 检查是否超时
            $timeout = time() - self::USER_ONLINE_TIMEOUT;
            if ($score < $timeout) {
                // 已超时，移除用户
                $this->userOffline($userId);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            \support\Log::error('Check user online failed: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * 用户下线
     *
     * @param int $userId 用户ID
     *
     * @return bool
     */
    public function userOffline(int $userId): bool
    {
        try {
            $redis = Redis::connection();

            // 从在线用户集合中移除
            $redis->zRem(self::ONLINE_USERS_KEY, $userId);

            // 删除用户信息
            $redis->del(self::ONLINE_USER_INFO_PREFIX . $userId);

            // 更新在线人数统计
            $this->updateOnlineCount();

            return true;
        } catch (\Throwable $e) {
            \support\Log::error('User offline failed: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * 广播在线人数更新
     *
     * @return bool
     */
    public function broadcastOnlineCount(): bool
    {
        try {
            $count = $this->getOnlineCount();

            $pusher = new Api(
                str_replace('0.0.0.0', '127.0.0.1', config('plugin.webman.push.app.api')),
                config('plugin.webman.push.app.app_key'),
                config('plugin.webman.push.app.app_secret')
            );

            // 向presence-online频道发送在线人数更新事件
            $pusher->trigger('presence-online', 'online-count-updated', [
                'count' => $count,
                'timestamp' => time(),
            ]);

            return true;
        } catch (\Throwable $e) {
            \support\Log::error('Broadcast online count failed: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * 获取在线用户数量
     *
     * @return int
     */
    public function getOnlineCount(): int
    {
        try {
            $redis = Redis::connection();

            // 清理过期用户
            $this->cleanExpiredUsers();

            // 从缓存读取
            $count = $redis->get(self::ONLINE_COUNT_KEY);
            if ($count !== false) {
                return (int) $count;
            }

            // 重新计算
            $count = $redis->zCard(self::ONLINE_USERS_KEY);
            $redis->setex(self::ONLINE_COUNT_KEY, 60, $count);

            return $count;
        } catch (\Throwable $e) {
            \support\Log::error('Get online count failed: ' . $e->getMessage());

            return 0;
        }
    }

    /**
     * 获取在线统计信息
     *
     * @return array
     */
    public function getOnlineStats(): array
    {
        try {
            $redis = Redis::connection();

            // 清理过期用户
            $this->cleanExpiredUsers();

            $totalOnline = $redis->zCard(self::ONLINE_USERS_KEY);

            // 统计最近1分钟、5分钟、15分钟活跃用户
            $now = time();
            $activeIn1Min = $redis->zCount(self::ONLINE_USERS_KEY, $now - 60, $now);
            $activeIn5Min = $redis->zCount(self::ONLINE_USERS_KEY, $now - 300, $now);
            $activeIn15Min = $redis->zCount(self::ONLINE_USERS_KEY, $now - 900, $now);

            return [
                'total_online' => $totalOnline,
                'active_1min' => $activeIn1Min,
                'active_5min' => $activeIn5Min,
                'active_15min' => $activeIn15Min,
                'timestamp' => $now,
            ];
        } catch (\Throwable $e) {
            \support\Log::error('Get online stats failed: ' . $e->getMessage());

            return [
                'total_online' => 0,
                'active_1min' => 0,
                'active_5min' => 0,
                'active_15min' => 0,
                'timestamp' => time(),
            ];
        }
    }
}
