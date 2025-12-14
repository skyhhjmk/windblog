<?php

namespace app\command;

use app\service\CacheService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 缓存清理命令
 * 提供命令行方式清理缓存功能
 */
class CacheClearCommand extends Command
{
    protected static $defaultName = 'cache:clear';

    protected static $defaultDescription = '清理缓存';

    /**
     * @return void
     */
    protected function configure()
    {
        $this->addArgument('group', InputArgument::OPTIONAL, '缓存分组名称')
            ->addOption('pattern', 'p', InputOption::VALUE_REQUIRED, '按模式清除缓存')
            ->addOption('all', 'a', InputOption::VALUE_NONE, '清除所有缓存');
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $group = $input->getArgument('group');
        $pattern = $input->getOption('pattern');
        $all = $input->getOption('all');

        if ($all) {
            // 清除所有缓存
            $result = CacheService::clearCacheAllDrivers();
            if ($result) {
                $output->writeln('<info>所有缓存已清除</info>');
            } else {
                $output->writeln('<error>清除所有缓存失败</error>');

                return Command::FAILURE;
            }
        } elseif ($pattern) {
            // 按模式清除缓存
            $result = CacheService::clearCache($pattern);
            if ($result) {
                $output->writeln("<info>匹配模式 {$pattern} 的缓存已清除</info>");
            } else {
                $output->writeln("<error>清除匹配模式 {$pattern} 的缓存失败</error>");

                return Command::FAILURE;
            }
        } elseif ($group) {
            // 清除指定分组缓存
            $pattern = "{$group}_*";
            $result = CacheService::clearCache($pattern);
            if ($result) {
                $output->writeln("<info>缓存分组 {$group} 已清除</info>");
            } else {
                $output->writeln("<error>清除缓存分组 {$group} 失败</error>");

                return Command::FAILURE;
            }
        } else {
            $output->writeln('<error>请指定要清除的缓存分组、模式或使用 --all 参数清除所有缓存</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
