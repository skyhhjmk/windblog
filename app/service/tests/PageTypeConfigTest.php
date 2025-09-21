<?php
/**
 * 页面类型配置测试文件
 * 用于验证页面类型配置功能是否正常工作
 */

namespace app\service\tests;

use app\service\SidebarService;
use app\service\WidgetConfigService;

class PageTypeConfigTest
{
    /**
     * 测试页面类型配置加载
     * @return void
     */
    public static function testPageTypeConfigLoad()
    {
        // 测试配置是否正确从数据库加载
        $pageTypes = blog_config('page_types', []);
        $defaultType = blog_config('default_page_type', 'default');
        
        echo "\n=== 页面类型配置加载测试 ===\n";
        echo "配置是否存在: " . (is_array($pageTypes) ? "是" : "否") . "\n";
        
        if (is_array($pageTypes)) {
            echo "默认页面类型: " . $defaultType . "\n";
            echo "支持的页面类型数量: " . count($pageTypes) . "\n";
            echo "支持的页面类型列表: " . implode(', ', array_keys($pageTypes)) . "\n";
        }
    }
    
    /**
     * 测试SidebarService中的页面类型处理
     * @return void
     */
    public static function testSidebarServicePageType()
    {
        echo "\n=== SidebarService页面类型处理测试 ===\n";
        
        // 测试getPageType方法
        $testTypes = ['home', 'post', 'category', 'tag', 'unknown'];
        
        foreach ($testTypes as $type) {
            $result = SidebarService::getPageType($type);
            echo "页面类型 '$type' 转换结果: '$result'\n";
        }
        
        // 测试getAllPageTypes方法
        $allTypes = SidebarService::getAllPageTypes();
        echo "SidebarService获取的所有页面类型数量: " . count($allTypes) . "\n";
    }
    
    /**
     * 测试WidgetConfigService中的页面类型处理
     * @return void
     */
    public static function testWidgetConfigServicePageType()
    {
        echo "\n=== WidgetConfigService页面类型处理测试 ===\n";
        
        // 测试getAllPageTypes方法
        $allTypes = WidgetConfigService::getAllPageTypes();
        echo "WidgetConfigService获取的所有页面类型数量: " . count($allTypes) . "\n";
        echo "WidgetConfigService获取的所有页面类型列表: " . implode(', ', $allTypes) . "\n";
    }
    
    /**
     * 运行所有测试
     * @return void
     */
    public static function runAllTests()
    {
        echo "\n======== 页面类型配置系统测试 ========\n";
        
        self::testPageTypeConfigLoad();
        self::testSidebarServicePageType();
        self::testWidgetConfigServicePageType();
        
        echo "\n======== 测试完成 ========\n";
    }
}