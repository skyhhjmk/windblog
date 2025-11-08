<?php

declare(strict_types=1);

namespace app\command;

use app\service\TwigCacheService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TwigCacheClearCommand extends Command
{
    protected static $defaultName = 'twig:cache:clear';

    protected static $defaultDescription = '清除 Twig 缓存（文件与片段缓存）';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<comment>正在清理 Twig 缓存...</comment>');
        $ok = TwigCacheService::clearAll();
        if ($ok) {
            $output->writeln('<info>✓ Twig 缓存已清理</info>');

            return Command::SUCCESS;
        }
        $output->writeln('<error>清理 Twig 缓存部分失败（请查看日志）</error>');

        return Command::FAILURE;
    }
}
