<?php

namespace Tests\Unit\Services;

use app\service\PluginService;
use PHPUnit\Framework\TestCase;

/**
 * PluginService 单元测试
 */
class PluginServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * 测试插件服务初始化
     */
    public function testInitialization(): void
    {
        // 确保插件服务可以初始化
        $this->expectNotToPerformAssertions();
        PluginService::init();
    }

    /**
     * 测试添加和执行动作钩子
     */
    public function testAddAndDoAction(): void
    {
        PluginService::init();

        $executed = false;
        $callback = function() use (&$executed) {
            $executed = true;
        };

        PluginService::add_action('test_action', $callback);
        PluginService::do_action('test_action');

        $this->assertTrue($executed, 'Action callback should have been executed');
    }

    /**
     * 测试动作钩子的参数传递
     */
    public function testActionWithArguments(): void
    {
        PluginService::init();

        $receivedArg = null;
        $callback = function($arg) use (&$receivedArg) {
            $receivedArg = $arg;
        };

        PluginService::add_action('test_action_args', $callback, 10, 1);
        PluginService::do_action('test_action_args', 'test_value');

        $this->assertEquals('test_value', $receivedArg);
    }

    /**
     * 测试动作钩子的优先级
     */
    public function testActionPriority(): void
    {
        PluginService::init();

        $order = [];

        $callback1 = function() use (&$order) {
            $order[] = 'first';
        };

        $callback2 = function() use (&$order) {
            $order[] = 'second';
        };

        // 优先级低的先执行
        PluginService::add_action('test_priority', $callback2, 20);
        PluginService::add_action('test_priority', $callback1, 10);
        PluginService::do_action('test_priority');

        $this->assertEquals(['first', 'second'], $order);
    }

    /**
     * 测试添加和应用过滤器钩子
     */
    public function testAddAndApplyFilter(): void
    {
        PluginService::init();

        $callback = function($value) {
            return $value . '_filtered';
        };

        PluginService::add_filter('test_filter', $callback);
        $result = PluginService::apply_filters('test_filter', 'original');

        $this->assertEquals('original_filtered', $result);
    }

    /**
     * 测试过滤器钩子的链式调用
     */
    public function testFilterChaining(): void
    {
        PluginService::init();

        $callback1 = function($value) {
            return $value . '_first';
        };

        $callback2 = function($value) {
            return $value . '_second';
        };

        PluginService::add_filter('test_chain', $callback1, 10);
        PluginService::add_filter('test_chain', $callback2, 20);
        $result = PluginService::apply_filters('test_chain', 'start');

        $this->assertEquals('start_first_second', $result);
    }

    /**
     * 测试过滤器带额外参数
     */
    public function testFilterWithExtraArguments(): void
    {
        PluginService::init();

        $callback = function($value, $arg1, $arg2) {
            return $value . $arg1 . $arg2;
        };

        PluginService::add_filter('test_filter_args', $callback, 10, 3);
        $result = PluginService::apply_filters('test_filter_args', 'base_', 'arg1_', 'arg2');

        $this->assertEquals('base_arg1_arg2', $result);
    }

    /**
     * 测试移除动作钩子
     */
    public function testRemoveAction(): void
    {
        PluginService::init();

        $executed = false;
        $callback = function() use (&$executed) {
            $executed = true;
        };

        PluginService::add_action('test_remove_action', $callback);
        PluginService::remove_action('test_remove_action', $callback);
        PluginService::do_action('test_remove_action');

        $this->assertFalse($executed, 'Action callback should not have been executed after removal');
    }

    /**
     * 测试移除过滤器钩子
     */
    public function testRemoveFilter(): void
    {
        PluginService::init();

        $callback = function($value) {
            return $value . '_filtered';
        };

        PluginService::add_filter('test_remove_filter', $callback);
        PluginService::remove_filter('test_remove_filter', $callback);
        $result = PluginService::apply_filters('test_remove_filter', 'original');

        $this->assertEquals('original', $result);
    }

    /**
     * 测试不存在的动作钩子不会报错
     */
    public function testNonExistentAction(): void
    {
        PluginService::init();

        $this->expectNotToPerformAssertions();
        PluginService::do_action('non_existent_action');
    }

    /**
     * 测试不存在的过滤器钩子返回原值
     */
    public function testNonExistentFilter(): void
    {
        PluginService::init();

        $result = PluginService::apply_filters('non_existent_filter', 'original_value');

        $this->assertEquals('original_value', $result);
    }

    /**
     * 测试获取所有插件列表
     */
    public function testAllPlugins(): void
    {
        PluginService::init();

        $plugins = PluginService::all_plugins();

        $this->assertIsArray($plugins);
    }

    /**
     * 测试多个回调按优先级执行
     */
    public function testMultipleCallbacksWithPriority(): void
    {
        PluginService::init();

        $value = 0;

        $add10 = function() use (&$value) { $value += 10; };
        $multiply2 = function() use (&$value) { $value *= 2; };
        $add5 = function() use (&$value) { $value += 5; };

        // 执行顺序应该是：add10 (priority 10), multiply2 (priority 20), add5 (priority 30)
        PluginService::add_action('test_multi', $multiply2, 20);
        PluginService::add_action('test_multi', $add5, 30);
        PluginService::add_action('test_multi', $add10, 10);

        PluginService::do_action('test_multi');

        // (0 + 10) * 2 + 5 = 25
        $this->assertEquals(25, $value);
    }

    /**
     * 测试同一优先级的回调按添加顺序执行
     */
    public function testSamePriorityCallbacksInOrder(): void
    {
        PluginService::init();

        $order = [];

        $callback1 = function() use (&$order) { $order[] = 1; };
        $callback2 = function() use (&$order) { $order[] = 2; };
        $callback3 = function() use (&$order) { $order[] = 3; };

        PluginService::add_action('test_order', $callback1, 10);
        PluginService::add_action('test_order', $callback2, 10);
        PluginService::add_action('test_order', $callback3, 10);

        PluginService::do_action('test_order');

        $this->assertEquals([1, 2, 3], $order);
    }
}
