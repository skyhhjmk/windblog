<?php

namespace app\service\plugin;

/**
 * WordPress 风格钩子系统（动作/过滤器）
 * - add_action($hook, $callback, $priority = 10, $accepted_args = 1)
 * - do_action($hook, ...$args)
 * - add_filter($hook, $callback, $priority = 10, $accepted_args = 1)
 * - apply_filters($hook, $value, ...$args)
 */
class HookManager
{
    /** @var array<string, array<int, array<int, array{cb: callable, args: int}>>> */
    private array $actions = [];

    /** @var array<string, array<int, array<int, array{cb: callable, args: int}>>> */
    private array $filters = [];

    /** 当前执行的钩子名称（动作或过滤器） */
    private ?string $currentHook = null;

    /** 简单统计：执行次数与错误计数 */
    private array $stats = ['actions' => [], 'filters' => [], 'errors' => 0];

    /** 优化：通配符钩子前缀缓存（如 'post_*' 的前缀 'post_'） */
    private array $wildcardPrefixes = [];

    /** 插件注册上下文：当前正在注册钩子的插件 slug 与管理器引用 */
    private ?string $registeringSlug = null;

    private ?PluginManager $managerRef = null;

    /** 优化：每个钩子的优先级排序缓存（减少触发时排序开销） */
    private array $actionsSorted = [];

    private array $filtersSorted = [];

    public function addAction(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void
    {
        $priority = max(PHP_INT_MIN, min(PHP_INT_MAX, $priority));
        // 系统权限校验：在插件激活阶段注册动作钩子时，先检查管理员授权
        if ($this->registeringSlug !== null && $this->managerRef) {
            $perm = $this->resolveActionPermission($hook);
            if (!$this->managerRef->hasPermission($this->registeringSlug, $perm)) {
                try {
                    \support\Log::warning("[plugin-hook-registration-denied] slug={$this->registeringSlug} hook={$hook} perm={$perm} type=action");
                } catch (\Throwable $e) {
                }

                return;
            }
        }
        $this->actions[$hook][$priority][] = ['cb' => $callback, 'args' => max(0, $accepted_args)];
        // 缓存：维护通配符前缀
        if (is_string($hook) && str_ends_with($hook, '*')) {
            $this->wildcardPrefixes[$hook] = substr($hook, 0, -1);
        }
    }

    public function removeAction(string $hook, callable $callback, ?int $priority = null): void
    {
        // 系统侧权限校验：仅在插件注册上下文中允许移除已授权钩子
        if ($this->registeringSlug !== null && $this->managerRef) {
            $perm = $this->resolveActionPermission($hook);
            if (!$this->managerRef->hasPermission($this->registeringSlug, $perm)) {
                try {
                    \support\Log::warning("[plugin-hook-remove-denied] slug={$this->registeringSlug} hook={$hook} perm={$perm} type=action");
                } catch (\Throwable $e) {
                }

                return;
            }
        }
        if (!isset($this->actions[$hook])) {
            return;
        }
        if ($priority === null) {
            foreach ($this->actions[$hook] as $p => $list) {
                $this->actions[$hook][$p] = array_values(array_filter($list, fn ($item) => $item['cb'] !== $callback));
            }
        } else {
            $list = $this->actions[$hook][$priority] ?? [];
            $this->actions[$hook][$priority] = array_values(array_filter($list, fn ($item) => $item['cb'] !== $callback));
        }
        // 清理通配符前缀缓存（当该钩子已无监听时）
        if (is_string($hook) && str_ends_with($hook, '*')) {
            if (empty($this->actions[$hook])) {
                unset($this->wildcardPrefixes[$hook]);
                unset($this->actions[$hook]);
            }
        }
    }

    public function doAction(string $hook, mixed ...$args): void
    {
        $buckets = $this->collectBuckets($this->actions, $hook);
        if (empty($buckets)) {
            return;
        }
        $this->currentHook = $hook;
        $this->stats['actions'][$hook] = ($this->stats['actions'][$hook] ?? 0) + 1;
        $this->sortBuckets($buckets, $hook, false);
        foreach ($buckets as $priority => $list) {
            $toRemove = [];
            foreach ($list as $idx => $item) {
                $cb = $item['cb'];
                $n = $item['args'];
                $pass = $n > 0 ? array_slice($args, 0, $n) : [];
                try {
                    $cb(...$pass);
                } catch (\Throwable $e) {
                    $this->stats['errors']++;
                    // 吞异常以避免中断动作链（符合 WP 行为）
                }
                // once 支持：约定传入的回调若为一次性，执行后移除
                if (is_array($item) && ($item['_once'] ?? false) === true) {
                    $toRemove[] = $idx;
                }
            }
            // 批量移除一次性钩子
            if (!empty($toRemove)) {
                foreach ($toRemove as $idx) {
                    unset($this->actions[$hook][$priority][$idx]);
                }
                $this->actions[$hook][$priority] = array_values($this->actions[$hook][$priority]);
                // 清理缓存
                unset($this->actionsSorted[$hook]);
            }
        }
        $this->currentHook = null;
    }

    public function addFilter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void
    {
        $priority = max(PHP_INT_MIN, min(PHP_INT_MAX, $priority));
        // 系统权限校验：在插件激活阶段注册过滤器钩子时，先检查管理员授权
        if ($this->registeringSlug !== null && $this->managerRef) {
            $perm = $this->resolveFilterPermission($hook);
            if (!$this->managerRef->hasPermission($this->registeringSlug, $perm)) {
                try {
                    \support\Log::warning("[plugin-hook-registration-denied] slug={$this->registeringSlug} hook={$hook} perm={$perm} type=filter");
                } catch (\Throwable $e) {
                }

                return;
            }
        }
        $this->filters[$hook][$priority][] = ['cb' => $callback, 'args' => max(0, $accepted_args)];
        // 缓存：维护通配符前缀
        if (is_string($hook) && str_ends_with($hook, '*')) {
            $this->wildcardPrefixes[$hook] = substr($hook, 0, -1);
        }
    }

    public function addOnceAction(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void
    {
        $priority = max(PHP_INT_MIN, min(PHP_INT_MAX, $priority));
        // 系统权限校验：一次性动作钩子
        if ($this->registeringSlug !== null && $this->managerRef) {
            $perm = $this->resolveActionPermission($hook);
            if (!$this->managerRef->hasPermission($this->registeringSlug, $perm)) {
                try {
                    \support\Log::warning("[plugin-hook-registration-denied] slug={$this->registeringSlug} hook={$hook} perm={$perm} type=action_once");
                } catch (\Throwable $e) {
                }

                return;
            }
        }
        $this->actions[$hook][$priority][] = ['cb' => $callback, 'args' => max(0, $accepted_args), '_once' => true];
    }

    public function addOnceFilter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void
    {
        $priority = max(PHP_INT_MIN, min(PHP_INT_MAX, $priority));
        // 系统权限校验：一次性过滤器钩子
        if ($this->registeringSlug !== null && $this->managerRef) {
            $perm = $this->resolveFilterPermission($hook);
            if (!$this->managerRef->hasPermission($this->registeringSlug, $perm)) {
                try {
                    \support\Log::warning("[plugin-hook-registration-denied] slug={$this->registeringSlug} hook={$hook} perm={$perm} type=filter_once");
                } catch (\Throwable $e) {
                }

                return;
            }
        }
        $this->filters[$hook][$priority][] = ['cb' => $callback, 'args' => max(0, $accepted_args), '_once' => true];
    }

    public function removeAllAction(string $hook): void
    {
        unset($this->actions[$hook]);
    }

    public function removeAllFilter(string $hook): void
    {
        unset($this->filters[$hook]);
    }

    public function currentHook(): ?string
    {
        return $this->currentHook;
    }

    public function getStats(): array
    {
        return $this->stats;
    }

    /** 插件激活阶段：开始/结束注册上下文（由 PluginManager 调用） */
    public function beginRegistering(string $slug, PluginManager $manager): void
    {
        $this->registeringSlug = $slug;
        $this->managerRef = $manager;
    }

    public function endRegistering(): void
    {
        $this->registeringSlug = null;
        $this->managerRef = null;
    }

    /** 将钩子名解析为权限字符串（动作） */
    private function resolveActionPermission(string $hook): string
    {
        // 通配符：如 "post_*" -> "post:action:*"
        if (str_ends_with($hook, '*')) {
            $prefix = rtrim($hook, '*');
            $domain = $prefix;
            if (str_contains($prefix, ':')) {
                $domain = explode(':', $prefix, 2)[0];
            } elseif (str_contains($prefix, '_')) {
                $domain = explode('_', $prefix, 2)[0];
            }
            $domain = $domain !== '' ? $domain : 'system';

            return "{$domain}:action:*";
        }
        // 规范：domain:name -> domain:action:name
        if (str_contains($hook, ':')) {
            [$domain, $name] = explode(':', $hook, 2);

            return "{$domain}:action:{$name}";
        }

        // 特例映射
        return match ($hook) {
            'request_enter' => 'request:action:enter',
            'response_exit' => 'response:action:exit',
            default => "system:action:{$hook}",
        };
    }

    /** 将钩子名解析为权限字符串（过滤器） */
    private function resolveFilterPermission(string $hook): string
    {
        // 通配符：如 "post_*" -> "post:filter:*"
        if (str_ends_with($hook, '*')) {
            $prefix = rtrim($hook, '*');
            $domain = $prefix;
            if (str_contains($prefix, ':')) {
                $domain = explode(':', $prefix, 2)[0];
            } elseif (str_contains($prefix, '_')) {
                $domain = explode('_', $prefix, 2)[0];
            }
            $domain = $domain !== '' ? $domain : 'system';

            return "{$domain}:filter:*";
        }
        if (str_contains($hook, ':')) {
            [$domain, $name] = explode(':', $hook, 2);

            return "{$domain}:filter:{$name}";
        }

        // 特例：response_filter 属于请求过滤权限
        return match ($hook) {
            'response_filter' => 'request:filter',
            default => "system:filter:{$hook}",
        };
    }

    private function collectBuckets(array $store, string $hook): array
    {
        // 基础：直接取该钩子的映射
        $buckets = $store[$hook] ?? [];
        // 通配符：仅遍历已缓存的前缀集合，避免全表扫描
        foreach ($this->wildcardPrefixes as $name => $prefix) {
            if ($prefix !== '' && str_starts_with($hook, $prefix)) {
                $map = $store[$name] ?? [];
                foreach ($map as $prio => $list) {
                    $buckets[$prio] = array_merge($buckets[$prio] ?? [], $list);
                }
            }
        }

        return $buckets;
    }

    private function hasWildcardHit(string $hook): bool
    {
        foreach ($this->wildcardPrefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($hook, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function sortBuckets(array &$buckets, string $hook, bool $isFilter): void
    {
        // 若未命中通配符前缀，可用缓存顺序；否则回退标准排序
        $useCache = !$this->hasWildcardHit($hook);
        if ($useCache) {
            // 选择对应的缓存存储
            if ($isFilter) {
                $sorted = $this->filtersSorted[$hook] ?? null;
                if (!is_array($sorted) || empty($sorted)) {
                    // 首次触发：根据现有优先级生成并缓存顺序
                    $keys = array_keys($buckets);
                    sort($keys);
                    $this->filtersSorted[$hook] = $keys;
                    $sorted = $keys;
                }
            } else {
                $sorted = $this->actionsSorted[$hook] ?? null;
                if (!is_array($sorted) || empty($sorted)) {
                    $keys = array_keys($buckets);
                    sort($keys);
                    $this->actionsSorted[$hook] = $keys;
                    $sorted = $keys;
                }
            }
            if (is_array($sorted) && !empty($sorted)) {
                $rebuilt = [];
                foreach ($sorted as $prio) {
                    if (isset($buckets[$prio])) {
                        $rebuilt[$prio] = $buckets[$prio];
                    }
                }
                $buckets = $rebuilt;

                return;
            }
        }
        // 回退：标准排序
        ksort($buckets);
    }

    public function removeFilter(string $hook, callable $callback, ?int $priority = null): void
    {
        // 系统侧权限校验：仅在插件注册上下文中允许移除已授权钩子
        if ($this->registeringSlug !== null && $this->managerRef) {
            $perm = $this->resolveFilterPermission($hook);
            if (!$this->managerRef->hasPermission($this->registeringSlug, $perm)) {
                try {
                    \support\Log::warning("[plugin-hook-remove-denied] slug={$this->registeringSlug} hook={$hook} perm={$perm} type=filter");
                } catch (\Throwable $e) {
                }

                return;
            }
        }
        if (!isset($this->filters[$hook])) {
            return;
        }
        if ($priority === null) {
            foreach ($this->filters[$hook] as $p => $list) {
                $this->filters[$hook][$p] = array_values(array_filter($list, fn ($item) => $item['cb'] !== $callback));
            }
        } else {
            $list = $this->filters[$hook][$priority] ?? [];
            $this->filters[$hook][$priority] = array_values(array_filter($list, fn ($item) => $item['cb'] !== $callback));
        }
        // 清理通配符前缀缓存（当该钩子已无监听时）
        if (is_string($hook) && str_ends_with($hook, '*')) {
            if (empty($this->filters[$hook])) {
                unset($this->wildcardPrefixes[$hook]);
                unset($this->filters[$hook]);
            }
        }
    }

    public function applyFilters(string $hook, mixed $value, mixed ...$args): mixed
    {
        $buckets = $this->collectBuckets($this->filters, $hook);
        if (empty($buckets)) {
            return $value;
        }
        $this->currentHook = $hook;
        $this->stats['filters'][$hook] = ($this->stats['filters'][$hook] ?? 0) + 1;
        $this->sortBuckets($buckets, $hook, true);
        $current = $value;
        foreach ($buckets as $priority => $list) {
            $toRemove = [];
            foreach ($list as $idx => $item) {
                $cb = $item['cb'];
                $n = $item['args'];
                $pass = $n > 0 ? array_slice([$current, ...$args], 0, $n) : [];
                try {
                    $current = $cb(...$pass);
                } catch (\Throwable $e) {
                    $this->stats['errors']++;
                    // 过滤器异常时保持当前值
                }
                if (is_array($item) && ($item['_once'] ?? false) === true) {
                    $toRemove[] = $idx;
                }
            }
            // 批量移除一次性过滤器
            if (!empty($toRemove)) {
                foreach ($toRemove as $idx) {
                    unset($this->filters[$hook][$priority][$idx]);
                }
                $this->filters[$hook][$priority] = array_values($this->filters[$hook][$priority]);
                // 清理缓存
                unset($this->filtersSorted[$hook]);
            }
        }
        $this->currentHook = null;

        return $current;
    }
}
