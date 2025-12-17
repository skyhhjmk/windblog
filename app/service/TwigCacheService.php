<?php

declare(strict_types=1);

namespace app\service;

use app\service\cache\RedisCacheAdapter;

use function config;
use function env;
use function is_dir;
use function mkdir;
use function runtime_path;

use Throwable;

/**
 * Twig 缓存服务
 * - 清理文件缓存目录
 * - 清理 Twig Fragment 缓存（Redis 前缀）
 */
class TwigCacheService
{
    /**
     * 清除所有 Twig 缓存（文件 + 片段缓存）
     */
    public static function clearAll(): bool
    {
        $ok = true;
        // 1) 清理 Twig 文件缓存目录
        try {
            $cacheOpt = config('view.options.cache');
            $cacheDir = is_string($cacheOpt) && $cacheOpt !== ''
                ? $cacheOpt
                : (env('TWIG_CACHE_PATH', runtime_path() . DIRECTORY_SEPARATOR . 'twig_cache'));
            self::clearDirectory($cacheDir);
        } catch (Throwable $_) {
            $ok = false;
        }

        // 2) 清理 Twig 片段缓存（Redis 前缀）- 已禁用
        /*
        try {
            $prefix = (string) env('TWIG_CACHE_PREFIX', 'twig:fragment:');
            $adapter = new RedisCacheAdapter([
                'connection' => 'cache',
                'prefix' => $prefix,
                'default_ttl' => 300,
            ]);
            $adapter->clear();
        } catch (Throwable $_) {
            $ok = false;
        }
        */

        return $ok;
    }

    /**
     * 递归清空目录内容（保留目录自身）
     */
    private static function clearDirectory(string $dir): void
    {
        if ($dir === '' || $dir === '/' || $dir === '\\') {
            return;
        }
        if (!is_dir($dir)) {
            // 目录不存在则尝试创建，随即返回
            @mkdir($dir, 0o777, true);

            return;
        }
        $items = @scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                self::deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }
    }

    /**
     * 删除整个目录
     */
    private static function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = @scandir($dir);
        if ($items !== false) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $path = $dir . DIRECTORY_SEPARATOR . $item;
                if (is_dir($path)) {
                    self::deleteDirectory($path);
                } else {
                    @unlink($path);
                }
            }
        }
        @rmdir($dir);
    }

    /**
     * 仅清除 Twig 文件缓存目录
     */
    public static function clearFiles(): bool
    {
        try {
            $cacheOpt = config('view.options.cache');
            $cacheDir = is_string($cacheOpt) && $cacheOpt !== ''
                ? $cacheOpt
                : (env('TWIG_CACHE_PATH', runtime_path() . DIRECTORY_SEPARATOR . 'twig_cache'));
            self::clearDirectory($cacheDir);

            return true;
        } catch (Throwable $_) {
            return false;
        }
    }
}
