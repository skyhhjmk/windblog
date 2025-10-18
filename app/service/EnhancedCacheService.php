<?php

namespace app\service;

/**
 * 增强型缓存服务类
 * 提供更高级的缓存功能，包括缓存命中率监控、二级缓存支持、缓存预热和分组管理
 */
class EnhancedCacheService
{
    /**
     * 二级缓存驱动
     * @var array
     */
    protected $secondaryCache = [];

    /**
     * 缓存统计数据
     * @var array
     */
    protected static $stats = [
        'hits' => 0,
        'misses' => 0,
        'requests' => 0,
    ];

    /**
     * 缓存分组配置
     * @var array
     */
    protected $groups = [];

    /**
     * 构造函数
     */
    public function __construct()
    {
        // 初始化二级缓存（内存缓存）
        $this->secondaryCache = [];
        // 初始化缓存分组
        $this->initGroups();
    }

    /**
     * 初始化缓存分组
     */
    protected function initGroups()
    {
        $this->groups = [
            'post' => [
                'prefix' => 'post_',
                'ttl' => 3600, // 默认1小时
                'secondary_ttl' => 60, // 二级缓存1分钟
            ],
            'category' => [
                'prefix' => 'category_',
                'ttl' => 7200, // 默认2小时
                'secondary_ttl' => 120, // 二级缓存2分钟
            ],
            'user' => [
                'prefix' => 'user_',
                'ttl' => 1800, // 默认30分钟
                'secondary_ttl' => 30, // 二级缓存30秒
            ],
            'config' => [
                'prefix' => 'config_',
                'ttl' => 86400, // 默认1天
                'secondary_ttl' => 300, // 二级缓存5分钟
            ],
        ];
    }

    /**
     * 获取缓存
     * @param string $key 缓存键
     * @param string $group 缓存分组
     * @param callable|null $callback 缓存不存在时的回调函数
     * @param int|null $ttl 缓存过期时间（秒）
     * @return mixed
     */
    public function get($key, $group = 'default', $callback = null, $ttl = null)
    {
        self::$stats['requests']++;

        // 获取分组配置
        $groupConfig = $this->getGroupConfig($group);
        $fullKey = $groupConfig['prefix'] . $key;

        // 1. 先从二级缓存获取
        $now = time();
        if (isset($this->secondaryCache[$fullKey]) &&
            $this->secondaryCache[$fullKey]['expire'] > $now) {
            self::$stats['hits']++;

            return $this->secondaryCache[$fullKey]['value'];
        }

        // 2. 从主缓存获取（兼容缓存驱动为null的情况）
        $value = false;
        try {
            $handler = CacheService::getCacheHandler();
            if ($handler) {
                $value = CacheService::cache($fullKey);
            }
        } catch (\Exception $e) {
            // 忽略任何缓存操作异常
        }

        if ($value !== false) {
            self::$stats['hits']++;
            // 更新二级缓存
            $this->updateSecondaryCache($fullKey, $value, $groupConfig['secondary_ttl']);

            return $value;
        }

        // 3. 缓存未命中，执行回调
        self::$stats['misses']++;
        if (is_callable($callback)) {
            $value = $callback();
            if ($value !== null) {
                $this->set($fullKey, $value, $ttl ?? $groupConfig['ttl']);
            }

            return $value;
        }

        return false;
    }

    /**
     * 设置缓存
     * @param string $key 缓存键
     * @param mixed $value 缓存值
     * @param int $ttl 缓存过期时间（秒）
     * @param string $group 缓存分组
     * @return bool
     */
    public function set($key, $value, $ttl = 3600, $group = 'default')
    {
        // 获取分组配置
        $groupConfig = $this->getGroupConfig($group);
        $fullKey = $groupConfig['prefix'] . $key;

        // 设置主缓存（兼容缓存驱动为null的情况）
        $result = false;
        try {
            $handler = CacheService::getCacheHandler();
            if ($handler) {
                $result = CacheService::cache($fullKey, $value, true, $ttl);
            }
        } catch (\Exception $e) {
            // 忽略任何缓存操作异常
        }

        // 设置二级缓存
        if ($result !== false) {
            $this->updateSecondaryCache($fullKey, $value, $groupConfig['secondary_ttl']);
        }

        return $result !== false;
    }

    /**
     * 删除缓存
     * @param string $key 缓存键
     * @param string $group 缓存分组
     * @return bool
     */
    public function delete($key, $group = 'default')
    {
        // 获取分组配置
        $groupConfig = $this->getGroupConfig($group);
        $fullKey = $groupConfig['prefix'] . $key;

        // 删除主缓存
        $handler = CacheService::getCacheHandler();
        $result = $handler && $handler->del(CacheService::prefixKey($fullKey));

        // 删除二级缓存
        if (isset($this->secondaryCache[$fullKey])) {
            unset($this->secondaryCache[$fullKey]);
        }

        return $result;
    }

    /**
     * 清空指定分组的缓存
     * @param string $group 缓存分组
     * @return bool
     */
    public function clearGroup($group)
    {
        if (!isset($this->groups[$group])) {
            return false;
        }

        $prefix = $this->groups[$group]['prefix'];

        // 清空主缓存中该分组的所有键
        // 注意：这里需要根据实际的缓存驱动实现键的批量删除
        // 由于原CacheService没有提供批量删除方法，这里需要扩展

        // 清空二级缓存中该分组的所有键
        foreach (array_keys($this->secondaryCache) as $key) {
            if (strpos($key, $prefix) === 0) {
                unset($this->secondaryCache[$key]);
            }
        }

        return true;
    }

    /**
     * 更新二级缓存
     * @param string $key 缓存键
     * @param mixed $value 缓存值
     * @param int $ttl 缓存过期时间（秒）
     */
    protected function updateSecondaryCache($key, $value, $ttl)
    {
        // 避免二级缓存过大
        $this->pruneSecondaryCache();

        $this->secondaryCache[$key] = [
            'value' => $value,
            'expire' => time() + $ttl,
            'created' => time(),
        ];
    }

    /**
     * 清理过期的二级缓存
     */
    protected function pruneSecondaryCache()
    {
        $now = time();
        $maxSize = 1000; // 二级缓存最大条目数

        // 清理过期项
        foreach ($this->secondaryCache as $key => $item) {
            if ($item['expire'] < $now) {
                unset($this->secondaryCache[$key]);
            }
        }

        // 如果缓存大小超过限制，按创建时间排序并删除旧项
        if (count($this->secondaryCache) > $maxSize) {
            usort($this->secondaryCache, function ($a, $b) {
                return $a['created'] - $b['created'];
            });

            $this->secondaryCache = array_slice($this->secondaryCache, -$maxSize);
        }
    }

    /**
     * 获取分组配置
     * @param string $group 分组名称
     * @return array
     */
    protected function getGroupConfig($group)
    {
        if (isset($this->groups[$group])) {
            return $this->groups[$group];
        }

        // 默认分组配置
        return [
            'prefix' => '',
            'ttl' => 3600,
            'secondary_ttl' => 60,
        ];
    }

    /**
     * 获取缓存统计信息
     * @return array
     */
    public function getStats()
    {
        $hits = self::$stats['hits'];
        $requests = self::$stats['requests'];
        $hitRate = $requests > 0 ? ($hits / $requests) * 100 : 0;

        return [
            'hits' => $hits,
            'misses' => self::$stats['misses'],
            'requests' => $requests,
            'hit_rate' => round($hitRate, 2) . '%',
            'secondary_cache_size' => count($this->secondaryCache),
        ];
    }

    /**
     * 缓存预热
     * @param array $items 要预热的缓存项数组
     */
    public function warmup(array $items)
    {
        foreach ($items as $item) {
            $key = $item['key'] ?? '';
            $group = $item['group'] ?? 'default';
            $callback = $item['callback'] ?? null;
            $ttl = $item['ttl'] ?? null;

            if ($key && is_callable($callback)) {
                // 异步预热缓存（如果系统支持）
                // 这里简化处理，直接同步执行
                $this->get($key, $group, $callback, $ttl);
            }
        }
    }

    /**
     * 批量获取缓存
     * @param array $keys 缓存键数组
     * @param string $group 缓存分组
     * @return array
     */
    public function multiGet(array $keys, $group = 'default')
    {
        $results = [];

        foreach ($keys as $key) {
            $results[$key] = $this->get($key, $group);
        }

        return $results;
    }

    /**
     * 批量设置缓存
     * @param array $items 缓存项数组，格式：[key => [value, ttl], ...]
     * @param string $group 缓存分组
     * @return array 成功设置的键数组
     */
    public function multiSet(array $items, $group = 'default')
    {
        $successKeys = [];

        foreach ($items as $key => $item) {
            $value = $item[0];
            $ttl = $item[1] ?? 3600;

            if ($this->set($key, $value, $ttl, $group)) {
                $successKeys[] = $key;
            }
        }

        return $successKeys;
    }

    /**
     * 设置缓存分组配置
     * @param string $group 分组名称
     * @param array $config 分组配置
     */
    public function setGroupConfig($group, array $config)
    {
        $defaultConfig = [
            'prefix' => $group . '_',
            'ttl' => 3600,
            'secondary_ttl' => 60,
        ];

        $this->groups[$group] = array_merge($defaultConfig, $config);
    }

    /**
     * 增加计数器缓存值
     * @param string $key 缓存键
     * @param int $step 步长
     * @param string $group 缓存分组
     * @return bool 成功返回true，失败返回false
     */
    public function increment($key, $step = 1, $group = 'default'): bool
    {
        $groupConfig = $this->getGroupConfig($group);
        $fullKey = $groupConfig['prefix'] . $key;

        // 尝试在主缓存中增加（兼容缓存驱动为null的情况）
        $result = false;
        try {
            $handler = CacheService::getCacheHandler();
            $prefixedKey = CacheService::prefixKey($fullKey);
            $result = $handler && $handler->incr($prefixedKey, $step);
        } catch (\Exception $e) {
            // 忽略任何缓存操作异常
        }

        // 如果成功，更新二级缓存
        if ($result !== false) {
            $this->updateSecondaryCache($fullKey, $result, $groupConfig['secondary_ttl']);
        }

        return $result !== false;
    }

    /**
     * 减少计数器缓存值
     * @param string $key 缓存键
     * @param int $step 步长
     * @param string $group 缓存分组
     * @return bool 成功返回true，失败返回false
     */
    public function decrement($key, $step = 1, $group = 'default'): bool
    {
        $groupConfig = $this->getGroupConfig($group);
        $fullKey = $groupConfig['prefix'] . $key;

        // 尝试在主缓存中减少（兼容缓存驱动为null的情况）
        $result = false;
        try {
            $handler = CacheService::getCacheHandler();
            $prefixedKey = CacheService::prefixKey($fullKey);
            $result = $handler && $handler->decr($prefixedKey, $step);
        } catch (\Exception $e) {
            // 忽略任何缓存操作异常
        }

        // 如果成功，更新二级缓存
        if ($result !== false) {
            $this->updateSecondaryCache($fullKey, $result, $groupConfig['secondary_ttl']);
        }

        return $result !== false;
    }
}
