<?php

namespace app\service;

use app\model\Setting;
use support\Log;

/**
 * 小工具配置服务
 * 负责管理小工具的配置信息，支持按页面设置不同的配置
 */
class WidgetConfigService
{
    /**
     * 获取小工具配置缓存键
     * @param string $widgetType 小工具类型
     * @param string $pageType 页面类型
     * @return string
     */
    private static function getConfigCacheKey(string $widgetType, string $pageType): string
    {
        return "widget_config_{$widgetType}_{$pageType}";
    }
    
    /**
     * 获取小工具在指定页面的配置
     * @param string $widgetType 小工具类型
     * @param string $pageType 页面类型
     * @return array
     */
    public static function getWidgetConfig(string $widgetType, string $pageType): array
    {
        // 先尝试从缓存获取
        $cacheKey = self::getConfigCacheKey($widgetType, $pageType);
        $config = cache($cacheKey);
        
        if (!empty($config)) {
            return $config;
        }
        
        // 缓存不存在则从数据库获取
        $settingKey = "widget_{$widgetType}_{$pageType}";
        $setting = Setting::where('key', $settingKey)->first();
        
        // 如果没有特定页面的配置，则获取通用配置
        if (!$setting && $pageType !== 'default') {
            $defaultSettingKey = "widget_{$widgetType}_default";
            $setting = Setting::where('key', $defaultSettingKey)->first();
        }
        
        // 默认配置
        $defaultConfig = [
            'enabled' => true,
            'title' => '',
            'params' => [],
        ];
        
        // 根据小工具类型设置默认标题
        $widgetTitles = [
            'about' => '关于博主',
            'categories' => '文章分类',
            'tags' => '标签云',
            'archive' => '文章归档',
            'recent_posts' => '最新文章',
            'popular_posts' => '热门文章',
            'random_posts' => '随机文章',
            'html' => '自定义HTML'
        ];
        
        if (isset($widgetTitles[$widgetType])) {
            $defaultConfig['title'] = $widgetTitles[$widgetType];
        }
        
        if ($setting) {
            $config = json_decode($setting->value, true);
            $config = array_merge($defaultConfig, $config);
        } else {
            $config = $defaultConfig;
        }
        
        // 存入缓存
        CacheService::cache($cacheKey, $config, true, 86400); // 缓存一天
        
        return $config;
    }
    
    /**
     * 保存小工具在指定页面的配置
     * @param string $widgetType 小工具类型
     * @param string $pageType 页面类型
     * @param array $config 配置数组
     * @return bool
     */
    public static function saveWidgetConfig(string $widgetType, string $pageType, array $config): bool
    {
        try {
            $settingKey = "widget_{$widgetType}_{$pageType}";
            
            // 确保params数组存在
            if (!isset($config['params']) || !is_array($config['params'])) {
                $config['params'] = [];
            }
            
            // 将配置数组转换为JSON字符串
            $configJson = json_encode($config, JSON_UNESCAPED_UNICODE);
            
            // 更新或创建设置
            Setting::updateOrCreate(
                ['key' => $settingKey],
                ['value' => $configJson]
            );
            
            // 清除缓存
            $cacheKey = self::getConfigCacheKey($widgetType, $pageType);
            cache()->delete($cacheKey);
            
            // 清除相关小工具的HTML缓存
            \app\service\WidgetService::clearWidgetCache(['type' => $widgetType]);
            
            Log::info("[WidgetConfigService] 小工具配置已保存: {$widgetType}_{$pageType}");
            return true;
        } catch (\Exception $e) {
            // 记录错误日志
            Log::error('[WidgetConfigService] 保存小工具配置失败: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取所有已注册的小工具类型
     * @return array
     */
    public static function getAllWidgetTypes(): array
    {
        // 这里返回所有支持的小工具类型
        try {
            return [
                'about', 'categories', 'tags', 'archive', 'recent_posts',
                'popular_posts', 'random_posts', 'html'
            ];
        } catch (\Exception $e) {
            Log::error('[WidgetConfigService] Failed to get widget types: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 获取所有支持的页面类型
     * @return array
     */
    public static function getAllPageTypes(): array
    {
        try {
            // 从数据库获取页面类型
            $pageTypes = blog_config('page_types', []);
            return array_keys($pageTypes);
        } catch (\Exception $e) {
            Log::error('[WidgetConfigService] Failed to get page types: ' . $e->getMessage());
            return [blog_config('default_page_type', 'default')];
        }
    }
    
    /**
     * 获取小工具在所有页面的配置
     * @param string $widgetType 小工具类型
     * @return array
     */
    public static function getWidgetConfigs(string $widgetType): array
    {
        try {
            $configs = [];
            $pageTypes = self::getAllPageTypes();
            
            foreach ($pageTypes as $pageType) {
                $configs[$pageType] = self::getWidgetConfig($widgetType, $pageType);
            }
            
            return $configs;
        } catch (\Exception $e) {
            Log::error('[WidgetConfigService] Failed to get widget configs: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 清除小工具配置缓存
     * 如果指定widgetType，则只清除该小工具的缓存；否则清除所有小工具的缓存
     * @param string|null $widgetType 小工具类型，可选
     * @return void
     */
    public static function clearWidgetCache(?string $widgetType = null): void
    {
        try {
            $widgetTypes = $widgetType ? [$widgetType] : self::getAllWidgetTypes();
            $pageTypes = self::getAllPageTypes();
            
            foreach ($widgetTypes as $type) {
                foreach ($pageTypes as $pageType) {
                    $cacheKey = self::getConfigCacheKey($type, $pageType);
                    cache()->delete($cacheKey);
                }
            }
        } catch (\Exception $e) {
            Log::error('[WidgetConfigService] Failed to clear widget cache: ' . $e->getMessage());
        }
    }
    
    /**
     * 清除所有小工具配置缓存
     * 此方法会清除所有小工具在所有页面类型的配置缓存
     * @return void
     */
    public static function clearAllCache(): void
    {
        self::clearWidgetCache();
    }
}