<?php

use PHPUnit\Framework\TestCase;
use app\model\Settings;

/**
 * 函数测试类
 * 用于测试博客系统中的各种辅助函数
 */
class FunctionsTest extends TestCase
{
    /**
     * 测试前的准备工作
     * 创建settings表并清空测试数据
     */
    protected function setUp(): void
    {
        // 创建测试用的表
        if (!\support\Db::getSchemaBuilder()->hasTable('settings')) {
            \support\Db::getSchemaBuilder()->create('settings', function ($table) {
                $table->id();
                $table->string('key')->unique();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }
        
        // 清空测试数据
        Settings::truncate();
    }

    /**
     * 测试从数据库获取博客配置值
     * 验证当配置项存在时，能正确返回数据库中的值
     */
    public function testGetBlogConfigReturnsValueFromDatabase()
    {
        // 插入测试数据
        Settings::create(['key' => 'test_key', 'value' => 'test_value']);
        
        // 测试获取已存在的配置
        $result = get_blog_config('test_key', 'default_value');
        $this->assertEquals('test_value', $result);
    }

    /**
     * 测试获取不存在的博客配置值
     * 验证当配置项不存在时，返回默认值
     */
    public function testGetBlogConfigReturnsDefaultWhenKeyNotFound()
    {
        // 测试获取不存在的配置，应该返回默认值
        $result = get_blog_config('nonexistent_key', 'default_value');
        $this->assertEquals('default_value', $result);
    }

    /**
     * 测试当set参数为true时创建记录
     * 验证配置项不存在且set为true时会自动创建记录
     */
    public function testGetBlogConfigCreatesRecordWhenSetIsTrue()
    {
        // 测试当set为true时，会创建记录
        $result = get_blog_config('new_key', 'default_value', true);
        $this->assertEquals('default_value', $result);
        
        // 验证记录已创建
        $this->assertEquals(1, Settings::where('key', 'new_key')->count());
    }

    /**
     * 测试设置新的博客配置
     * 验证能正确创建新的配置项
     */
    public function testSetBlogConfigCreatesNewRecord()
    {
        // 测试设置新配置
        $result = set_blog_config('new_setting', 'new_value');
        $this->assertTrue($result);
        
        // 验证记录已创建
        $setting = Settings::where('key', 'new_setting')->first();
        $this->assertNotNull($setting);
        $this->assertEquals('new_value', $setting->value);
    }

    /**
     * 测试更新已存在的博客配置
     * 验证能正确更新已存在的配置项
     */
    public function testSetBlogConfigUpdatesExistingRecord()
    {
        // 插入初始数据
        Settings::create(['key' => 'existing_key', 'value' => 'old_value']);
        
        // 更新配置
        $result = set_blog_config('existing_key', 'new_value');
        $this->assertTrue($result);
        
        // 验证记录已更新
        $setting = Settings::where('key', 'existing_key')->first();
        $this->assertEquals('new_value', $setting->value);
    }

    /**
     * 测试解码数据函数返回完整数组
     * 验证序列化的数组能被正确反序列化为完整的数组
     */
    public function testDecodeDataReturnsArray()
    {
        $testArray = ['key1' => 'value1', 'key2' => 'value2'];
        $serialized = serialize($testArray);
        
        $result = decodeData($serialized);
        $this->assertEquals($testArray, $result);
    }

    /**
     * 测试解码数据函数返回指定键的值
     * 验证能从序列化的数组中正确提取指定键的值
     */
    public function testDecodeDataReturnsSpecificValue()
    {
        $testArray = ['key1' => 'value1', 'key2' => 'value2'];
        $serialized = serialize($testArray);
        
        $result = decodeData($serialized, 'key1');
        $this->assertEquals('value1', $result);
    }

    /**
     * 测试解码数据函数处理不存在的键
     * 验证当请求的键不存在时返回null
     */
    public function testDecodeDataReturnsNullForNonexistentKey()
    {
        $testArray = ['key1' => 'value1', 'key2' => 'value2'];
        $serialized = serialize($testArray);
        
        $result = decodeData($serialized, 'nonexistent_key');
        $this->assertNull($result);
    }

    /**
     * 测试编码数据函数返回序列化字符串
     * 验证数组能被正确序列化为字符串
     */
    public function testEncodeDataReturnsSerializedString()
    {
        $testArray = ['key1' => 'value1', 'key2' => 'value2'];
        $expected = serialize($testArray);
        
        $result = encodeData($testArray);
        $this->assertEquals($expected, $result);
    }
}