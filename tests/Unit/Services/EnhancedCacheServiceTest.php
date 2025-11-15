<?php

namespace Tests\Unit\Services;

use app\service\CacheService;
use app\service\EnhancedCacheService;
use PHPUnit\Framework\TestCase;

class EnhancedCacheServiceTest extends TestCase
{
    public function testSetAndGetWithPrimaryAndSecondaryCache(): void
    {
        $service = new EnhancedCacheService();

        // 写入缓存（主缓存 + 二级缓存）
        $this->assertTrue($service->set('item_1', 'value_1', 3600, 'post'));

        // 第一次 get 走主缓存，并将值写入二级缓存
        $val1 = $service->get('item_1', 'post');
        $this->assertSame('value_1', $val1);

        // 第二次 get 应直接命中二级缓存
        $val2 = $service->get('item_1', 'post');
        $this->assertSame('value_1', $val2);

        $stats = $service->getStats();
        $this->assertSame(2, $stats['hits']);
        $this->assertSame(0, $stats['misses']);
        $this->assertSame(2, $stats['requests']);
        $this->assertSame(1, $stats['secondary_cache_size']);
    }

    public function testDeleteRemovesFromPrimaryAndSecondary(): void
    {
        $service = new EnhancedCacheService();

        $service->set('item_del', 'to_delete', 3600, 'user');
        $this->assertSame('to_delete', $service->get('item_del', 'user'));

        // 再次获取以确保写入二级缓存
        $this->assertSame('to_delete', $service->get('item_del', 'user'));

        $this->assertTrue($service->delete('item_del', 'user'));
        $this->assertFalse($service->get('item_del', 'user'));
    }

    public function testClearGroupClearsSecondaryCacheForGroup(): void
    {
        $service = new EnhancedCacheService();

        $service->set('p1', 'v1', 3600, 'post');
        $service->set('c1', 'v2', 3600, 'category');

        // 读取一次，使其进入二级缓存
        $this->assertSame('v1', $service->get('p1', 'post'));
        $this->assertSame('v2', $service->get('c1', 'category'));

        // 此时二级缓存中应包含两个键
        $statsBefore = $service->getStats();
        $this->assertSame(2, $statsBefore['secondary_cache_size']);

        // 仅清除 post 分组
        $this->assertTrue($service->clearGroup('post'));

        // 清理后，二级缓存只剩下 category 分组的键
        $statsAfter = $service->getStats();
        $this->assertSame(1, $statsAfter['secondary_cache_size']);

        // category 分组的键仍然可以读取
        $this->assertSame('v2', $service->get('c1', 'category'));
    }

    public function testMultiSetAndMultiGet(): void
    {
        $service = new EnhancedCacheService();

        $items = [
            'k1' => ['v1', 3600],
            'k2' => ['v2', 3600],
        ];

        $successKeys = $service->multiSet($items, 'page');
        sort($successKeys);
        $this->assertSame(['k1', 'k2'], $successKeys);

        $results = $service->multiGet(['k1', 'k2', 'k3'], 'page');
        $this->assertSame('v1', $results['k1']);
        $this->assertSame('v2', $results['k2']);
        $this->assertFalse($results['k3']);
    }

    public function testSetGroupConfigOverridesDefaults(): void
    {
        $service = new EnhancedCacheService();

        $service->setGroupConfig('analytics', [
            'prefix' => 'ana_',
            'ttl' => 100,
            'secondary_ttl' => 5,
        ]);

        $this->assertTrue($service->set('foo', 'bar', 100, 'analytics'));
        $this->assertSame('bar', $service->get('foo', 'analytics'));
    }

    protected function setUp(): void
    {
        parent::setUp();
        // 使用内存驱动，避免外部依赖
        CacheService::reset();
        putenv('CACHE_DRIVER=memory');
        putenv('CACHE_PREFIX=ecs_test_');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        CacheService::reset();
        putenv('CACHE_DRIVER');
        putenv('CACHE_PREFIX');
    }
}
