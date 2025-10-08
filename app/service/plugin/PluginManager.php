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
        // 生命周期：安装/升级检测（不强制接口，动态调用）
        $prevVersion = (string)(blog_config("plugins.version.$slug", '', true) ?: '');
        $curVersion  = $entry['meta']->version;
        try {
            if ($prevVersion === '' && \method_exists($entry['instance'], 'onInstall')) {
                $entry['instance']->onInstall($this->hooks);
            } elseif ($prevVersion !== '' && $curVersion !== '' && $prevVersion !== $curVersion && \method_exists($entry['instance'], 'onUpgrade')) {
                $entry['instance']->onUpgrade($prevVersion, $curVersion, $this->hooks);
            }
        } catch (\Throwable $e) {
            // 安装/升级异常不影响后续 activate，但可记录日志/统计
        }

        // 权限：检测声明的权限并收集待授权
        $declared = (array)($entry['meta']->permissions ?? []);
        if ($declared) {
            $pending = [];
            foreach ($declared as $perm) {
                if (!$this->hasPermission($slug, (string)$perm)) {
                    $pending[] = (string)$perm;
                }
            }
            if ($pending) {
                blog_config("plugins.permissions.$slug.pending", $pending, true, true, true);
                CacheService::clearCache("blog_config_plugins.permissions.$slug*");
            }
        }

        // 插件注册上下文：在激活阶段统一进行系统权限校验（未授权拒绝注册并告警）
        $this->hooks->beginRegistering($slug, $this);
        try {
            $entry['instance']->activate($this->hooks);
        } catch (\Throwable $e) {
            return false;
        } finally {
            $this->hooks->endRegistering();
        }

        $enabled = (array)(blog_config('plugins.enabled', [], true) ?: []);
        if (!in_array($slug, $enabled, true)) {
            $enabled[] = $slug;
            blog_config('plugins.enabled', $enabled, true, true, true);
            // 清理缓存键，避免旧状态影响
            CacheService::clearCache('blog_config_plugins.enabled*');
        }
        // 更新版本持久化
        if ($curVersion !== '') {
            blog_config("plugins.version.$slug", $curVersion, true, true, true);
            CacheService::clearCache("blog_config_plugins.version.$slug*");
        }
        return true;
    }

    /**
     * 权限白名单：检查/授予/撤销（admin 可对接）
     */
    public function hasPermission(string $slug, string $permission): bool
    {
        $granted = (array)(blog_config("plugins.permissions.$slug.granted", [], true) ?: []);
        return in_array($permission, $granted, true);
    }

    public function grantPermission(string $slug, string $permission): void
    {
        $granted = (array)(blog_config("plugins.permissions.$slug.granted", [], true) ?: []);
        if (!in_array($permission, $granted, true)) {
            $granted[] = $permission;
            blog_config("plugins.permissions.$slug.granted", $granted, true, true, true);
            CacheService::clearCache("blog_config_plugins.permissions.$slug*");
        }
        // 从待授权中移除
        $pending = (array)(blog_config("plugins.permissions.$slug.pending", [], true) ?: []);
        $pending = array_values(array_filter($pending, fn($p) => $p !== $permission));
        blog_config("plugins.permissions.$slug.pending", $pending, true, true, true);

        // 权限变更后重置计数器（calls/denied）
        $this->resetCounts($slug, $permission);
    }

    public function revokePermission(string $slug, string $permission): void
    {
        $granted = (array)(blog_config("plugins.permissions.$slug.granted", [], true) ?: []);
        $granted = array_values(array_filter($granted, fn($p) => $p !== $permission));
        blog_config("plugins.permissions.$slug.granted", $granted, true, true, true);
        CacheService::clearCache("blog_config_plugins.permissions.$slug*");

        // 权限变更后重置计数器（calls/denied）
        $this->resetCounts($slug, $permission);
    }

    /**
     * 权限：查询声明/已授权/待授权，便于 admin 展示
     */
    public function getDeclaredPermissions(string $slug): array
    {
        $entry = $this->plugins[$slug] ?? null;
        return $entry ? (array)($entry['meta']->permissions ?? []) : [];
    }

    public function getGrantedPermissions(string $slug): array
    {
        return (array)(blog_config("plugins.permissions.$slug.granted", [], true) ?: []);
    }

    public function getPendingPermissions(string $slug): array
    {
        return (array)(blog_config("plugins.permissions.$slug.pending", [], true) ?: []);
    }

    /**
     * 获取某权限的调用/拒绝统计（Redis）
     */
    public function getCounts(string $slug, string $permission): array
    {
        $r = $this->redis();
        $keys = [
            $this->keyFor($slug, $permission, 'calls'),
            $this->keyFor($slug, $permission, 'denied'),
        ];
        $vals = $r->mGet($keys);
        return [
            'calls' => (int)($vals[0] ?? 0),
            'denied' => (int)($vals[1] ?? 0),
        ];
    }

    /**
     * 批量授权/撤销（admin 对接）
     */
    public function grantPermissions(string $slug, array $permissions): void
    {
        foreach ($permissions as $perm) {
            $this->grantPermission($slug, (string)$perm);
        }
    }

    public function revokePermissions(string $slug, array $permissions): void
    {
        foreach ($permissions as $perm) {
            $this->revokePermission($slug, (string)$perm);
        }
    }

    /**
     * 计数器实现（Redis）
     */
    private function redis()
    {
        try {
            return \support\Redis::connection('default');
        } catch (\Throwable $e) {
            // 回退：使用内存式简易对象（Redis不可用时，统计不持久）
            static $dummy = null;
            if ($dummy === null) {
                $dummy = new class {
                    private array $store = [];
                    public function incrBy($key, $by) { $this->store[$key] = ($this->store[$key] ?? 0) + $by; return $this->store[$key]; }
                    public function mGet($keys) { return array_map(fn($k) => $this->store[$k] ?? null, $keys); }
                    public function del($keys) { foreach ((array)$keys as $k) { unset($this->store[$k]); } }
                };
            }
            return $dummy;
        }
    }

    private function keyFor(string $slug, string $permission, string $type): string
    {
        return "plugins:perm:{$slug}:{$permission}:{$type}";
    }

    private function incCallWindow(string $slug, string $permission): void
    {
        $r = $this->redis();
        $now = time();
        $hourKey = $this->keyFor($slug, $permission, 'calls') . ':hour:' . date('YmdH', $now);
        $dayKey  = $this->keyFor($slug, $permission, 'calls') . ':day:'  . date('Ymd', $now);
        $r->incrBy($hourKey, 1);
        $r->incrBy($dayKey, 1);
        if (method_exists($r, 'expire')) {
            $r->expire($hourKey, 3600 * 24 * 8);
            $r->expire($dayKey, 86400 * 8);
        }
    }

    private function incDeniedWindow(string $slug, string $permission): void
    {
        $r = $this->redis();
        $now = time();
        $hourKey = $this->keyFor($slug, $permission, 'denied') . ':hour:' . date('YmdH', $now);
        $dayKey  = $this->keyFor($slug, $permission, 'denied') . ':day:'  . date('Ymd', $now);
        $r->incrBy($hourKey, 1);
        $r->incrBy($dayKey, 1);
        if (method_exists($r, 'expire')) {
            $r->expire($hourKey, 3600 * 24 * 8);
            $r->expire($dayKey, 86400 * 8);
        }
    }

    /**
     * 统计窗口汇总：近24小时与近7天
     */
    public function getWindowCounts(string $slug, string $permission): array
    {
        $r = $this->redis();
        $now = time();
        $calls24 = 0; $denied24 = 0;
        for ($h = 0; $h < 24; $h++) {
            $ts = $now - $h * 3600;
            $hk = $this->keyFor($slug, $permission, 'calls') . ':hour:' . date('YmdH', $ts);
            $dk = $this->keyFor($slug, $permission, 'denied') . ':hour:' . date('YmdH', $ts);
            $calls24 += (int)($r->mGet([$hk])[0] ?? 0);
            $denied24 += (int)($r->mGet([$dk])[0] ?? 0);
        }
        $calls7d = 0; $denied7d = 0;
        for ($d = 0; $d < 7; $d++) {
            $ts = $now - $d * 86400;
            $hk = $this->keyFor($slug, $permission, 'calls') . ':day:' . date('Ymd', $ts);
            $dk = $this->keyFor($slug, $permission, 'denied') . ':day:' . date('Ymd', $ts);
            $calls7d += (int)($r->mGet([$hk])[0] ?? 0);
            $denied7d += (int)($r->mGet([$dk])[0] ?? 0);
        }
        return [
            'calls_24h' => $calls24,
            'denied_24h' => $denied24,
            'calls_7d' => $calls7d,
            'denied_7d' => $denied7d,
        ];
    }

    private function incCall(string $slug, string $permission): int
    {
        $r = $this->redis();
        $v = (int)$r->incrBy($this->keyFor($slug, $permission, 'calls'), 1);
        $this->incCallWindow($slug, $permission);
        return $v;
    }

    private function incDenied(string $slug, string $permission): int
    {
        $r = $this->redis();
        $v = (int)$r->incrBy($this->keyFor($slug, $permission, 'denied'), 1);
        $this->incDeniedWindow($slug, $permission);
        return $v;
    }

    private function resetCounts(string $slug, string $permission): void
    {
        $r = $this->redis();
        $r->del([
            $this->keyFor($slug, $permission, 'calls'),
            $this->keyFor($slug, $permission, 'denied'),
        ]);
        // 清理最近8天窗口桶
        $now = time();
        for ($h = 0; $h < 24 * 8; $h++) {
            $ts = $now - $h * 3600;
            $r->del([
                $this->keyFor($slug, $permission, 'calls') . ':hour:' . date('YmdH', $ts),
                $this->keyFor($slug, $permission, 'denied') . ':hour:' . date('YmdH', $ts),
            ]);
        }
        for ($d = 0; $d < 8; $d++) {
            $ts = $now - $d * 86400;
            $r->del([
                $this->keyFor($slug, $permission, 'calls') . ':day:' . date('Ymd', $ts),
                $this->keyFor($slug, $permission, 'denied') . ':day:' . date('Ymd', $ts),
            ]);
        }
    }

    /**
     * 权限强制检查：未授权一律拒绝（默认拒绝），并进行计数+日志
     * 返回 true 表示允许，false 表示拒绝
     */
    public function ensurePermission(string $slug, string $permission): bool
    {
        // 记录调用次数（Redis）
        $this->incCall($slug, $permission);

        if ($this->hasPermission($slug, $permission)) {
            return true;
        }

        // 记录拒绝次数（Redis），并获取最新拒绝计数
        $denied = $this->incDenied($slug, $permission);

        // 日志：记录拒绝事件（包含插件与权限）
        try {
            \support\Log::warning("[plugin-permission-denied] slug={$slug} perm={$permission} denied={$denied}");
        } catch (\Throwable $e) {
            // 忽略日志异常，保持主流程
        }

        return false;
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
                        $inst = $ref->newInstance();
                        if ($inst instanceof PluginInterface) {
                            return $inst;
                        }
                    }
                } catch (\Throwable $e) {
                    // 忽略并继续
                }
            }
        }

        return null;
    }
}