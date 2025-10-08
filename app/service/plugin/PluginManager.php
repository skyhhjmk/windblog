<?php

namespace app\service\plugin;

use app\service\CacheService;

/**
 * 插件管理器：扫描、加载、启用/停用/卸载，记录状态到 blog_config
 * 约定：
 * - 插件目录：app/wind_plugins/{slug}/
 * - 主文件：优先匹配 plugin.php，其次匹配首个包含 WP 风格头注释的 .php 文件
 * - 主文件包含后应返回一个实现 PluginInterface 的实例，或在文件中定义一个类并返回该实例
 */
class PluginManager
{
    private string $pluginRoot;
    private HookManager $hooks;

    /** @var array<string, array{meta: PluginMetadata, file: string, instance: ?PluginInterface}> */
    private array $plugins = [];

    public function __construct(string $pluginRoot, HookManager $hooks)
    {
        $this->pluginRoot = rtrim($pluginRoot, DIRECTORY_SEPARATOR);
        $this->hooks = $hooks;
    }

    /**
     * 扫描插件目录，解析元数据并建立索引
     */
    public function scan(): void
    {
        $root = $this->pluginRoot;
        if (!is_dir($root)) {
            return;
        }
        $dirs = scandir($root);
        if (!is_array($dirs)) {
            return;
        }

        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..') continue;
            $full = $root . DIRECTORY_SEPARATOR . $dir;
            if (!is_dir($full)) continue;

            $mainFile = $this->locateMainFile($full);
            if (!$mainFile) continue;

            $meta = PluginMetadata::parseFromFile($mainFile);
            if (!$meta) continue;

            // 使用目录名作为默认 slug，若解析到 slug 则以解析值为准
            if ($meta->slug === '') {
                $meta->slug = strtolower(preg_replace('/[^a-z0-9\-]+/i', '-', $dir));
            }
            $this->plugins[$meta->slug] = [
                'meta' => $meta,
                'file' => $mainFile,
                'instance' => null,
            ];
        }
    }

    /**
     * 加载当前已启用插件
     */
    public function loadEnabled(): void
    {
        $enabled = (array)(blog_config('plugins.enabled', [], true) ?: []);
        foreach ($enabled as $slug) {
            $this->enable($slug);
        }
    }

    /**
     * 启用插件（激活并持久化状态）
     */
    public function enable(string $slug): bool
    {
        if (!isset($this->plugins[$slug])) {
            return false;
        }
        $entry = &$this->plugins[$slug];
        if (!$entry['instance']) {
            $instance = $this->instantiate($entry['file']);
            if (!$instance) {
                return false;
            }
            $entry['instance'] = $instance;
        }
        try {
            $entry['instance']->activate($this->hooks);
        } catch (\Throwable $e) {
            return false;
        }

        $enabled = (array)(blog_config('plugins.enabled', [], true) ?: []);
        if (!in_array($slug, $enabled, true)) {
            $enabled[] = $slug;
            blog_config('plugins.enabled', $enabled, true, true, true);
            // 清理缓存键，避免旧状态影响
            CacheService::clearCache('blog_config_plugins.enabled*');
        }
        return true;
    }

    /**
     * 停用插件（调用 deactivate 并更新状态）
     */
    public function disable(string $slug): bool
    {
        if (!isset($this->plugins[$slug])) {
            return false;
        }
        $entry = &$this->plugins[$slug];
        if ($entry['instance']) {
            try {
                $entry['instance']->deactivate($this->hooks);
            } catch (\Throwable $e) {
                // 忽略异常以保证流程继续
            }
        }

        $enabled = (array)(blog_config('plugins.enabled', [], true) ?: []);
        $enabled = array_values(array_filter($enabled, fn($s) => $s !== $slug));
        blog_config('plugins.enabled', $enabled, true, true, true);
        CacheService::clearCache('blog_config_plugins.enabled*');
        return true;
    }

    /**
     * 卸载插件（调用 uninstall 并从启用列表移除）
     */
    public function uninstall(string $slug): bool
    {
        if (!isset($this->plugins[$slug])) {
            return false;
        }
        $entry = &$this->plugins[$slug];
        if ($entry['instance']) {
            try {
                $entry['instance']->uninstall($this->hooks);
            } catch (\Throwable $e) {
                // 忽略异常
            }
        }
        // 停用并持久化
        $this->disable($slug);
        return true;
    }

    /**
     * 获取所有扫描到的插件元信息
     * @return array<string, PluginMetadata>
     */
    public function allMetadata(): array
    {
        $out = [];
        foreach ($this->plugins as $slug => $entry) {
            $out[$slug] = $entry['meta'];
        }
        return $out;
    }

    private function locateMainFile(string $pluginDir): ?string
    {
        $prefer = $pluginDir . DIRECTORY_SEPARATOR . 'plugin.php';
        if (is_file($prefer)) {
            return $prefer;
        }
        // 回退：选择第一个包含 WP 风格头注释的 php 文件
        $files = scandir($pluginDir);
        if (!is_array($files)) {
            return null;
        }
        foreach ($files as $f) {
            if (str_ends_with($f, '.php')) {
                $full = $pluginDir . DIRECTORY_SEPARATOR . $f;
                $meta = PluginMetadata::parseFromFile($full);
                if ($meta) {
                    return $full;
                }
            }
        }
        return null;
    }

    private function instantiate(string $file): ?PluginInterface
    {
        // 约定：主文件 return 一个实现 PluginInterface 的实例
        $ret = (function ($__file__) {
            return include $__file__;
        })($file);

        if ($ret instanceof PluginInterface) {
            return $ret;
        }

        // 若未返回实例，尝试在已声明的类中查找实现 PluginInterface 的类并实例化（仅限无参构造）
        $declared = get_declared_classes();
        foreach (array_reverse($declared) as $cls) {
            if (is_subclass_of($cls, PluginInterface::class)) {
                try {
                    $ref = new \ReflectionClass($cls);
                    if ($ref->isInstantiable() && $ref->getConstructor()?->getNumberOfRequiredParameters() === 0) {
                        return $ref->newInstance();
                    }
                } catch (\Throwable $e) {
                    // 忽略并继续
                }
            }
        }

        return null;
    }
}