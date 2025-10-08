<?php

namespace app\service;

use app\service\plugin\HookManager;
use app\service\plugin\PluginManager;

/**
 * 插件系统服务入口（独立于 webman），提供 WordPress 风格钩子与插件管理
 *
 * 使用约定：
 * - 插件目录：app/wind_plugins
 * - 状态存储：通过 blog_config('plugins.enabled', [...])
 * - 钩子前缀：空前缀（方法名与 WP 保持一致）
 * - PHP 最低版本：8.2
 */
class PluginService
{
    private static ?HookManager $hooks = null;
    private static ?PluginManager $manager = null;

    public static function init(?string $pluginRoot = null): void
    {
        if (self::$hooks === null) {
            self::$hooks = new HookManager();
        }
        if ($pluginRoot === null) {
            $pluginRoot = base_path() . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'wind_plugins';
        }
        self::$manager = new PluginManager($pluginRoot, self::$hooks);
        self::$manager->scan();
        self::$manager->loadEnabled();
    }

    // 动作钩子
    public static function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void
    {
        self::ensureInit();
        self::$hooks->addAction($hook, $callback, $priority, $accepted_args);
    }

    public static function remove_action(string $hook, callable $callback, ?int $priority = null): void
    {
        self::ensureInit();
        self::$hooks->removeAction($hook, $callback, $priority);
    }

    public static function do_action(string $hook, mixed ...$args): void
    {
        self::ensureInit();
        self::$hooks->doAction($hook, ...$args);
    }

    // 过滤器钩子
    public static function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void
    {
        self::ensureInit();
        self::$hooks->addFilter($hook, $callback, $priority, $accepted_args);
    }

    public static function remove_filter(string $hook, callable $callback, ?int $priority = null): void
    {
        self::ensureInit();
        self::$hooks->removeFilter($hook, $callback, $priority);
    }

    public static function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        self::ensureInit();
        return self::$hooks->applyFilters($hook, $value, ...$args);
    }

    // 插件管理
    public static function enable(string $slug): bool
    {
        self::ensureInit();
        return self::$manager->enable($slug);
    }

    public static function disable(string $slug): bool
    {
        self::ensureInit();
        return self::$manager->disable($slug);
    }

    public static function uninstall(string $slug): bool
    {
        self::ensureInit();
        return self::$manager->uninstall($slug);
    }

    public static function all_plugins(): array
    {
        self::ensureInit();
        return self::$manager->allMetadata();
    }

    private static function ensureInit(): void
    {
        if (self::$hooks === null || self::$manager === null) {
            self::init();
        }
    }
}