<?php

namespace app\command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * OAuth 配置初始化命令
 *
 * 使用方式:
 * php webman oauth:init
 */
class OAuthConfigInit extends Command
{
    protected static $defaultName = 'oauth:init';

    protected static $defaultDescription = '初始化 OAuth 配置到数据库';

    /**
     * 配置命令
     */
    protected function configure()
    {
        $this->setName('oauth:init')
            ->setDescription('初始化 OAuth 配置到数据库（使用 blog_config）');
    }

    /**
     * 执行命令
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>开始初始化 OAuth 配置...</info>');

        // 定义默认配置
        $configs = [
            'oauth_wind' => [
                'enabled' => false,
                'name' => 'Wind OAuth',
                'icon' => 'fas fa-wind',
                'color' => '#4a90e2',
                'base_url' => 'http://localhost:8787',
                'client_id' => '',
                'client_secret' => '',
                'scopes' => ['basic', 'profile'],
            ],
            'oauth_github' => [
                'enabled' => false,
                'name' => 'GitHub',
                'icon' => 'fab fa-github',
                'color' => '#333',
                'client_id' => '',
                'client_secret' => '',
                'scopes' => ['user:email'],
            ],
            'oauth_google' => [
                'enabled' => false,
                'name' => 'Google',
                'icon' => 'fab fa-google',
                'color' => '#db4437',
                'client_id' => '',
                'client_secret' => '',
                'scopes' => ['openid', 'email', 'profile'],
            ],
        ];

        // 使用 blog_config 的 init 参数初始化配置
        foreach ($configs as $key => $config) {
            try {
                // 使用 init=true 参数，如果配置不存在则创建
                $result = blog_config($key, $config, true, true);

                if ($result) {
                    $output->writeln("<info>✓ 已初始化配置: {$key}</info>");
                } else {
                    $output->writeln("<comment>○ 配置已存在，跳过: {$key}</comment>");
                }
            } catch (\Throwable $e) {
                $output->writeln("<error>✗ 初始化失败 {$key}: {$e->getMessage()}</error>");

                return Command::FAILURE;
            }
        }

        $output->writeln('');
        $output->writeln('<info>OAuth 配置初始化完成！</info>');
        $output->writeln('');
        $output->writeln('<comment>下一步：</comment>');
        $output->writeln('1. 在 wind_oauth 服务器创建客户端');
        $output->writeln('2. 更新配置中的 client_id 和 client_secret');
        $output->writeln('3. 将 enabled 设置为 true');
        $output->writeln('');
        $output->writeln('<comment>更新配置示例：</comment>');
        $output->writeln('通过数据库或后台管理界面修改 settings 表中的配置项');

        return Command::SUCCESS;
    }
}
