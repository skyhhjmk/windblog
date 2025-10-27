<?php

namespace app\command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * GitLab OAuth 配置初始化命令（示例）
 *
 * 使用方式:
 * php webman oauth:init-gitlab
 */
class OAuthGitLabInit extends Command
{
    protected static $defaultName = 'oauth:init-gitlab';

    protected static $defaultDescription = '初始化 GitLab OAuth 配置到数据库';

    /**
     * 配置命令
     */
    protected function configure()
    {
        $this->setName('oauth:init-gitlab')
            ->setDescription('初始化 GitLab OAuth 配置到数据库（示例命令）');
    }

    /**
     * 执行命令
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>开始初始化 GitLab OAuth 配置...</info>');

        // 定义 GitLab OAuth 配置
        $config = [
            'enabled' => false,
            'name' => 'GitLab',
            'icon' => 'fab fa-gitlab',
            'color' => '#fc6d26',
            'base_url' => 'https://gitlab.com',
            'authorize_path' => '/oauth/authorize',
            'token_path' => '/oauth/token',
            'userinfo_path' => '/api/v4/user',
            'revoke_path' => '/oauth/revoke',
            'client_id' => '',
            'client_secret' => '',
            'scopes' => ['read_user'],

            // 字段映射
            'user_id_field' => 'id',
            'username_field' => 'username',
            'email_field' => 'email',
            'nickname_field' => 'name',
            'avatar_field' => 'avatar_url',
        ];

        try {
            // 使用 blog_config 的 init 参数初始化配置
            $result = blog_config('oauth_gitlab', $config, true, true);

            if ($result) {
                $output->writeln('<info>✓ 已初始化配置: oauth_gitlab</info>');
            } else {
                $output->writeln('<comment>○ 配置已存在，跳过: oauth_gitlab</comment>');
            }

            $output->writeln('');
            $output->writeln('<info>GitLab OAuth 配置初始化完成！</info>');
            $output->writeln('');
            $output->writeln('<comment>下一步：</comment>');
            $output->writeln('1. 在 GitLab 创建 OAuth 应用');
            $output->writeln('   - 访问: https://gitlab.com/-/profile/applications');
            $output->writeln('   - Redirect URI: http://yourdomain.com/oauth/gitlab/callback');
            $output->writeln('   - Scopes: read_user');
            $output->writeln('2. 更新配置中的 client_id 和 client_secret');
            $output->writeln('3. 将 enabled 设置为 true');
            $output->writeln('');
            $output->writeln('<comment>更新配置示例：</comment>');
            $output->writeln('UPDATE settings SET value = JSON_SET(value, \'$.enabled\', true, \'$.client_id\', \'你的ID\', \'$.client_secret\', \'你的密钥\') WHERE `key` = \'oauth_gitlab\';');

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $output->writeln('<error>✗ 初始化失败: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
    }
}
