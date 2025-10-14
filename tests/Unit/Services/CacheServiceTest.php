<?php

namespace Tests\Unit\Services;

use app\service\CacheService;
use PHPUnit\Framework\TestCase;

/**
 * CacheService 单元测试
 *
 * 注意：这些测试使用内存缓存驱动，不需要外部依赖
 */
class CacheServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // 重置缓存服务状态
        CacheService::reset();
        // 使用内存缓存驱动进行测试
        putenv('CACHE_DRIVER=memory');
        putenv('CACHE_PREFIX=test_');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // 清理环境变量
        putenv('CACHE_DRIVER');
        putenv('CACHE_PREFIX');
        // 重置缓存服务状态
        CacheService::reset();
    }

    /**
     * 测试缓存处理器获取
     */
    public function testGetHandler(): void
    {
        $handler = CacheService::getHandler();

        $this->assertNotNull($handler);
    }

    /**
     * 测试基本的设置和获取
     */
    public function testSetAndGet(): void
    {
        $handler = CacheService::getHandler();
        $key = 'test_key';
        $value = 'test_value';

        $handler->set($key, $value, 60);
        $result = $handler->get($key);

        $this->assertEquals($value, $result);
    }

    /**
     * 测试获取不存在的键返回 false
     */
    public function testGetNonExistentKeyReturnsFalse(): void
    {
        $handler = CacheService::getHandler();
        $key = 'non_existent_key_' . time();

        $result = $handler->get($key);

        $this->assertFalse($result);
    }

    /**
     * 测试删除缓存
     */
    public function testDelete(): void
    {
        $handler = CacheService::getHandler();
        $key = 'test_delete_key';
        $value = 'test_value';

        $handler->set($key, $value, 60);
        $this->assertEquals($value, $handler->get($key));

        $handler->del($key);
        $this->assertFalse($handler->get($key));
    }

    /**
     * 测试缓存是否存在
     */
    public function testHas(): void
    {
        $handler = CacheService::getHandler();
        $key = 'test_has_key';
        $value = 'test_value';

        $this->assertFalse($handler->has($key));

        $handler->set($key, $value, 60);
        $this->assertTrue($handler->has($key));
    }

    /**
     * 测试存储复杂数据类型
     */
    public function testComplexDataTypes(): void
    {
        $handler = CacheService::getHandler();

        $testCases = [
            'array' => ['a' => 1, 'b' => 2, 'c' => [3, 4, 5]],
            'integer' => 12345,
            'float' => 123.45,
            'boolean_true' => true,
            'boolean_false' => false,
            'string' => 'test string',
        ];

        foreach ($testCases as $key => $value) {
            $handler->set($key, $value, 60);
            $result = $handler->get($key);
            $this->assertEquals($value, $result, "Failed for type: $key");
        }
    }

    /**
     * 测试缓存前缀功能
     */
    public function testCachePrefix(): void
    {
        putenv('CACHE_PREFIX=test_prefix_');

        $handler = CacheService::getHandler();
        $key = 'mykey';
        $value = 'myvalue';

        $handler->set($key, $value, 60);
        $result = $handler->get($key);

        $this->assertEquals($value, $result);
    }

    /**
     * 测试 TTL 设置
     */
    public function testTtlSetting(): void
    {
        $handler = CacheService::getHandler();
        $key = 'test_ttl_key';
        $value = 'test_value';
        $ttl = 60;

        // 设置缓存
        $handler->set($key, $value, $ttl);

        // 验证可以读取
        $this->assertEquals($value, $handler->get($key));

        // 注意：实际的 TTL 过期测试需要 sleep，这里只验证设置不会出错
    }

    /**
     * 测试多次写入同一个键
     */
    public function testOverwrite(): void
    {
        $handler = CacheService::getHandler();
        $key = 'test_overwrite_key';

        $handler->set($key, 'value1', 60);
        $this->assertEquals('value1', $handler->get($key));

        $handler->set($key, 'value2', 60);
        $this->assertEquals('value2', $handler->get($key));
    }

    /**
     * 测试缓存键命名
     */
    public function testKeyNaming(): void
    {
        $handler = CacheService::getHandler();

        $keys = [
            'simple_key',
            'key:with:colons',
            'key.with.dots',
            'key-with-dashes',
            'key_with_underscores',
        ];

        foreach ($keys as $key) {
            $handler->set($key, 'value', 60);
            $result = $handler->get($key);
            $this->assertEquals('value', $result, "Failed for key: $key");
        }
    }

    /**
     * 测试空字符串值
     */
    public function testEmptyStringValue(): void
    {
        $handler = CacheService::getHandler();
        $key = 'test_empty_string';
        $value = '';

        $handler->set($key, $value, 60);
        $result = $handler->get($key);

        // 注意：空字符串应该被正确存储和读取
        $this->assertSame($value, $result);
    }

    /**
     * 测试数字键
     */
    public function testNumericKeys(): void
    {
        $handler = CacheService::getHandler();

        $handler->set('123', 'value123', 60);
        $handler->set('456', 'value456', 60);

        $this->assertEquals('value123', $handler->get('123'));
        $this->assertEquals('value456', $handler->get('456'));
    }

    /**
     * 测试 None 驱动（禁用缓存）
     */
    public function testNoneDriver(): void
    {
        putenv('CACHE_DRIVER=none');

        $handler = CacheService::getHandler();

        // None 驱动应该总是返回 false
        $handler->set('test_key', 'test_value', 60);
        $result = $handler->get('test_key');

        $this->assertFalse($result);
    }

    /**
     * 测试并发写入相同键
     */
    public function testConcurrentWrites(): void
    {
        $handler = CacheService::getHandler();
        $key = 'concurrent_key';

        // 模拟多次快速写入
        for ($i = 0; $i < 10; $i++) {
            $handler->set($key, "value_$i", 60);
        }

        // 最后一次写入应该生效
        $result = $handler->get($key);
        $this->assertEquals('value_9', $result);
    }

    /**
     * 测试 prefixKey 静态方法
     */
    public function testPrefixKey(): void
    {
        // 重置并设置前缀
        CacheService::reset();
        putenv('CACHE_PREFIX=prefix_');

        $key = 'mykey';
        $prefixedKey = CacheService::prefixKey($key);

        $this->assertStringStartsWith('prefix_', $prefixedKey);
        $this->assertStringContainsString('mykey', $prefixedKey);
    }
}
