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
        $buckets = $this->actions[$hook] ?? [];
        if (empty($buckets)) {
            return;
        }
        ksort($buckets); // 按优先级升序
        foreach ($buckets as $priority => $list) {
            foreach ($list as $item) {
                $cb = $item['cb'];
                $n  = $item['args'];
                $pass = $n > 0 ? array_slice($args, 0, $n) : [];
                try {
                    $cb(...$pass);
                } catch (\Throwable $e) {
                    // 吞异常以避免中断动作链（符合 WP 行为）
                }
            }
        }
    }

    public function addFilter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void
    {
        $priority = max(PHP_INT_MIN, min(PHP_INT_MAX, $priority));
        $this->filters[$hook][$priority][] = ['cb' => $callback, 'args' => max(0, $accepted_args)];
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
        $buckets = $this->filters[$hook] ?? [];
        if (empty($buckets)) {
            return $value;
        }
        ksort($buckets);
        $current = $value;
        foreach ($buckets as $priority => $list) {
            foreach ($list as $item) {
                $cb = $item['cb'];
                $n  = $item['args'];
                $pass = $n > 0 ? array_slice([$current, ...$args], 0, $n) : [];
                try {
                    $current = $cb(...$pass);
                } catch (\Throwable $e) {
                    // 过滤器异常时保持当前值
                }
            }
        }
        return $current;
    }
}