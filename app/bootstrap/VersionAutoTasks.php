<?php

declare(strict_types=1);

namespace app\bootstrap;

use app\service\EnhancedCacheService;
use app\service\MenuImportService;
use app\service\TwigCacheService;

use function base_path;
use function env;
use function file_get_contents;
use function fopen;
use function is_file;
use function json_decode;
use function runtime_path;

use Throwable;
use Webman\Bootstrap;

/**
 * 开机自检版本变化并执行一次性维护任务
 */
class VersionAutoTasks implements Bootstrap
{
    public static function start($worker): void
    {
        // 可通过环境变量关闭
        $enabled = env('AUTO_VERSION_TASKS', 'true');
        if (is_string($enabled) && strtolower($enabled) === 'false') {
            return;
        }

        // 仅在 workerId==0 尝试触发
        if (!$worker || $worker->id !== 0) {
            return;
        }

        $current = self::readCurrentVersion();
        if ($current === null || $current === '') {
            return;
        }

        $last = self::readLastVersion();
        if ($last === $current) {
            return; // 未变化
        }

        // 粗粒度文件锁避免并发
        $lockFile = runtime_path('version_tasks.lock');
        $fp = @fopen($lockFile, 'c+');
        $locked = false;
        if (is_resource($fp)) {
            $locked = @flock($fp, LOCK_EX | LOCK_NB);
        }
        if (!$locked) {
            return;
        }

        try {
            // 任务：菜单导入、Twig 缓存清理、应用缓存清理
            MenuImportService::reinitialize();
            TwigCacheService::clearAll();
            (new EnhancedCacheService())->clearAll();
            self::writeLastVersion($current);
        } catch (Throwable $_) {
            // ignore
        } finally {
            if (is_resource($fp)) {
                @flock($fp, LOCK_UN);
                @fclose($fp);
            }
        }
    }

    private static function readCurrentVersion(): ?string
    {
        $composerFile = base_path('composer.json');
        if (!is_file($composerFile)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($composerFile), true);
        $v = is_array($data) ? ($data['version'] ?? null) : null;

        return is_string($v) ? $v : null;
    }

    private static function readLastVersion(): ?string
    {
        // 优先通过统一的 blog_config 获取
        try {
            $v = blog_config('system_app_version', '', false, false);
            if (is_string($v) && $v !== '') {
                return $v;
            }
            // 兼容旧键名（早期实现使用 'system.app_version'）
            $vOld = blog_config('system.app_version', '', false, false);
            if (is_string($vOld) && $vOld !== '') {
                return $vOld;
            }
        } catch (Throwable $_) {
            // ignore
        }
        // 回退到本地文件
        $path = runtime_path('last_version.txt');
        if (is_file($path)) {
            $v = trim((string) file_get_contents($path));

            return $v !== '' ? $v : null;
        }

        return null;
    }

    private static function writeLastVersion(string $version): void
    {
        // 统一通过 blog_config 写入（JSON 存储 + 缓存刷新）
        try {
            blog_config('system_app_version', $version, false, false, true);
        } catch (Throwable $_) {
            // ignore
        }
        // 文件
        $path = runtime_path('last_version.txt');
        @file_put_contents($path, $version);
    }
}
