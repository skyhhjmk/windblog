<?php

namespace app\command;

use app\service\CatLevelService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CAT 运行级别管理命令
 *
 * 使用方法：
 * - 查看当前级别：php webman cat-level:show
 * - 刷新缓存：php webman cat-level:refresh
 * - 清除缓存：php webman cat-level:clear
 */
class CatLevelCommand extends Command
{
    protected static $defaultName = 'cat-level';

    protected static $defaultDescription = 'CAT 运行级别管理';

    protected function configure()
    {
        $this->addOption('show', 's', InputOption::VALUE_NONE, '显示当前 CAT 级别')
            ->addOption('refresh', 'r', InputOption::VALUE_NONE, '刷新级别检测缓存')
            ->addOption('clear', 'c', InputOption::VALUE_NONE, '清除级别检测缓存');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $show = $input->getOption('show');
        $refresh = $input->getOption('refresh');
        $clear = $input->getOption('clear');

        // 清除缓存
        if ($clear) {
            if (CatLevelService::clearCache()) {
                $output->writeln('<info>✓ CAT 级别缓存已清除</info>');
            } else {
                $output->writeln('<error>✗ 清除缓存失败</error>');

                return Command::FAILURE;
            }
        }

        // 刷新缓存
        if ($refresh) {
            $output->writeln('<comment>正在重新检测 CAT 级别...</comment>');
            $info = CatLevelService::getLevelInfo(true);
            $output->writeln('<info>✓ CAT 级别缓存已刷新</info>');
            $this->displayLevelInfo($output, $info);

            return Command::SUCCESS;
        }

        // 显示当前级别
        if ($show || (!$clear && !$refresh)) {
            $info = CatLevelService::getLevelInfo();
            $this->displayLevelInfo($output, $info);

            return Command::SUCCESS;
        }

        return Command::SUCCESS;
    }

    /**
     * 显示级别信息
     */
    private function displayLevelInfo(OutputInterface $output, array $info): void
    {
        $output->writeln('');
        $output->writeln('<fg=cyan>========================================</>');
        $output->writeln('<fg=cyan>       CAT 运行级别检测结果</>');
        $output->writeln('<fg=cyan>========================================</>');
        $output->writeln('');

        // 级别
        $levelColor = match (substr($info['level'], 0, 4)) {
            'CAT5' => 'red',
            'CAT4' => 'cyan',
            'CAT3' => 'green',
            'CAT2' => 'yellow',
            default => 'white',
        };
        $output->writeln("  <fg={$levelColor};options=bold>级别：{$info['level']}</>");
        $output->writeln('');

        // 描述
        $output->writeln("  描述：{$info['description']}");
        $output->writeln('');

        // 能力列表
        $output->writeln('  <fg=yellow>支持的功能：</>');
        foreach ($info['capabilities'] as $capability) {
            $output->writeln("    {$capability}");
        }
        $output->writeln('');

        // 检测时间
        $output->writeln("  <fg=gray>检测时间：{$info['detected_at']} UTC</>");
        $output->writeln('');

        // 功能详情
        if (!empty($info['features'])) {
            $output->writeln('  <fg=yellow>功能检测详情：</>');
            foreach ($info['features'] as $key => $value) {
                $status = $value ? '<fg=green>✓</>' : '<fg=red>✗</>';
                $output->writeln("    {$status} {$key}");
            }
            $output->writeln('');
        }

        $output->writeln('<fg=cyan>========================================</>');
        $output->writeln('');
    }
}
