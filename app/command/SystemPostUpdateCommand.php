<?php

declare(strict_types=1);

namespace app\command;

use app\service\EnhancedCacheService;
use app\service\MenuImportService;
use app\service\TwigCacheService;

use function base_path;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SystemPostUpdateCommand extends Command
{
    protected static $defaultName = 'system:post-update';

    protected static $defaultDescription = '系统更新后的一次性维护任务（菜单导入、Twig 缓存清理、应用缓存清理）';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>执行系统更新后的维护任务</info>');

        // 读取当前版本
        $version = null;
        $composerFile = base_path('composer.json');
        if (is_file($composerFile)) {
            $data = json_decode((string) file_get_contents($composerFile), true);
            $version = is_array($data) ? ($data['version'] ?? null) : null;
        }

        // 1) 菜单重新导入
        $output->writeln('<comment>→ 重新导入管理菜单</comment>');
        if (!MenuImportService::reinitialize()) {
            $output->writeln('<error>× 菜单导入失败</error>');
        } else {
            $output->writeln('<info>✓ 菜单导入完成</info>');
        }

        // 2) Twig 缓存清理
        $output->writeln('<comment>→ 清理 Twig 缓存</comment>');
        if (!TwigCacheService::clearAll()) {
            $output->writeln('<error>× Twig 缓存清理部分失败</error>');
        } else {
            $output->writeln('<info>✓ Twig 缓存已清理</info>');
        }

        // 3) 应用缓存清理
        $output->writeln('<comment>→ 清理应用缓存</comment>');
        $cache = new EnhancedCacheService();
        if (!$cache->clearAll()) {
            $output->writeln('<error>× 应用缓存清理失败</error>');
        } else {
            $output->writeln('<info>✓ 应用缓存已清理</info>');
        }

        // 4) 记录版本
        try {
            if (is_string($version) && $version !== '') {
                blog_config('system_app_version', $version, false, false, true);
            }
        } catch (\Throwable $e) {
            // 忽略 DB 错误
        }

        $output->writeln('<info>所有任务执行完毕</info>');

        return Command::SUCCESS;
    }
}
