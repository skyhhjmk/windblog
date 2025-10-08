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

    public function addAction(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void
    {
        $priority = max(PHP_INT_MIN, min(PHP_INT_MAX, $priority));
        $this->actions[$hook][$priority][] = ['cb' => $callback, 'args' => max(0, $accepted_args)];
    }

    public function removeAction(string $hook, callable $callback, ?int $priority = null): void
    {
        if (!isset($this->actions[$hook])) {
            return;
        }
        if ($priority === null) {
            foreach ($this->actions[$hook] as $p => $list) {
                $this->actions[$hook][$p] = array_values(array_filter($list, fn($item) => $item['cb'] !== $callback));
            }
        } else {
            $list = $this->actions[$hook][$priority] ?? [];
            $this->actions[$hook][$priority] = array_values(array_filter($list, fn($item) => $item['cb'] !== $callback));
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
        ksort($buckets); // 按优先级升序
        foreach ($buckets as $priority => $list) {
            foreach ($list as $idx => $item) {
                $cb = $item['cb'];
                $n  = $item['args'];
                $pass = $n > 0 ? array_slice($args, 0, $n) : [];
                try {
                    $cb(...$pass);
                } catch (\Throwable $e) {
                    $this->stats['errors']++;
                    // 吞异常以避免中断动作链（符合 WP 行为）
                }
                // once 支持：约定传入的回调若为一次性，执行后移除
                if (is_array($item) && ($item['_once'] ?? false) === true) {
                    unset($list[$idx]);
                    $this->actions[$hook][$priority] = array_values($list);
                }
            }
        }
        $this->currentHook = null;
    }

    public function addFilter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void
    {
        $priority = max(PHP_INT_MIN, min(PHP_INT_MAX, $priority));
        $this->filters[$hook][$priority][] = ['cb' => $callback, 'args' => max(0, $accepted_args)];
    }

    public function addOnceAction(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void
    {
        $priority = max(PHP_INT_MIN, min(PHP_INT_MAX, $priority));
        $this->actions[$hook][$priority][] = ['cb' => $callback, 'args' => max(0, $accepted_args), '_once' => true];
    }

    public function addOnceFilter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void
    {
        $priority = max(PHP_INT_MIN, min(PHP_INT_MAX, $priority));
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

    private function collectBuckets(array $store, string $hook): array
    {
        $buckets = $store[$hook] ?? [];
        // 通配符：支持以 * 结尾的前缀匹配，如 "post_*"
        foreach ($store as $name => $map) {
            if (is_string($name) && str_ends_with($name, '*')) {
                $prefix = substr($name, 0, -1);
                if ($prefix !== '' && str_starts_with($hook, $prefix)) {
                    foreach ($map as $prio => $list) {
                        $buckets[$prio] = array_merge($buckets[$prio] ?? [], $list);
                    }
                }
            }
        }
        return $buckets;
    }

    public function removeFilter(string $hook, callable $callback, ?int $priority = null): void
    {
        if (!isset($this->filters[$hook])) {
            return;
        }
        if ($priority === null) {
            foreach ($this->filters[$hook] as $p => $list) {
                $this->filters[$hook][$p] = array_values(array_filter($list, fn($item) => $item['cb'] !== $callback));
            }
        } else {
            $list = $this->filters[$hook][$priority] ?? [];
            $this->filters[$hook][$priority] = array_values(array_filter($list, fn($item) => $item['cb'] !== $callback));
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
        ksort($buckets);
        $current = $value;
        foreach ($buckets as $priority => $list) {
            foreach ($list as $idx => $item) {
                $cb = $item['cb'];
                $n  = $item['args'];
                $pass = $n > 0 ? array_slice([$current, ...$args], 0, $n) : [];
                try {
                    $current = $cb(...$pass);
                } catch (\Throwable $e) {
                    $this->stats['errors']++;
                    // 过滤器异常时保持当前值
                }
                if (is_array($item) && ($item['_once'] ?? false) === true) {
                    unset($list[$idx]);
                    $this->filters[$hook][$priority] = array_values($list);
                }
            }
        }
        $this->currentHook = null;
        return $current;
    }
}