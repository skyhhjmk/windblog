<?php

namespace app\command;

use support\Db;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 清除表数据命令
 */
class ClearTableCommand extends Command
{
    protected static $defaultName = 'dev:clear-table';

    protected static $defaultDescription = '清除指定表的所有数据，支持同时传入多个表名';

    /**
     * 配置命令
     */
    protected function configure()
    {
        $this->addArgument('name', InputArgument::IS_ARRAY | InputArgument::REQUIRED, '表名，支持多个表名，用空格分隔');
    }

    /**
     * 执行命令
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tables = $input->getArgument('name');

        if (empty($tables)) {
            $output->writeln('<error>请指定要清除数据的表名</error>');

            return 1;
        }

        // 确认操作
        $output->writeln('<warning>警告：此操作将清除以下表的所有数据，且无法恢复！</warning>');
        foreach ($tables as $table) {
            $output->writeln('<info>- ' . $table . '</info>');
        }

        $output->writeln('<question>是否继续？(y/n)</question>');
        $handle = fopen('php://stdin', 'r');
        $line = fgets($handle);
        fclose($handle);

        if (strtolower(trim($line)) !== 'y') {
            $output->writeln('<info>操作已取消</info>');

            return 0;
        }

        // 执行清除操作
        foreach ($tables as $table) {
            try {
                Db::table($table)->truncate();
                $output->writeln('<info>成功清除表 ' . $table . ' 的所有数据</info>');
            } catch (\Exception $e) {
                $output->writeln('<error>清除表 ' . $table . ' 数据失败：' . $e->getMessage() . '</error>');
            }
        }

        return 0;
    }
}
