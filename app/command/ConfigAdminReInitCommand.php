<?php

namespace app\command;

use plugin\admin\app\common\Util;
use plugin\admin\app\model\Admin;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Throwable;

class ConfigAdminReInitCommand extends Command
{
    protected static $defaultName = 'config:admin:re-init';

    protected static $defaultDescription = '重新初始化管理员账户信息';

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('重新初始化管理员账户信息');

        try {
            // 检查数据库连接
            if (!config('database.connections')) {
                $output->writeln('<error>数据库未配置，请先完成安装步骤</error>');

                return Command::FAILURE;
            }

            /** @var QuestionHelper $helper */
            $helper = $this->getHelper('question');

            // 提示输入用户名
            $usernameQuestion = new Question('请输入管理员用户名 (默认: admin): ', 'admin');
            $username = $helper->ask($input, $output, $usernameQuestion);

            // 提示输入密码
            $passwordQuestion = new Question('请输入管理员密码 (默认: admin): ', 'admin');
            $passwordQuestion->setHidden(true);
            $passwordQuestion->setHiddenFallback(false);
            $password = $helper->ask($input, $output, $passwordQuestion);

            // 确认密码
            $confirmPasswordQuestion = new Question('请再次输入密码以确认: ');
            $confirmPasswordQuestion->setHidden(true);
            $confirmPasswordQuestion->setHiddenFallback(false);
            $confirmPassword = $helper->ask($input, $output, $confirmPasswordQuestion);

            if ($password !== $confirmPassword) {
                $output->writeln('<error>两次输入的密码不一致</error>');

                return Command::FAILURE;
            }

            // 提示输入昵称
            $nicknameQuestion = new Question('请输入管理员昵称 (默认: 超级管理员): ', '超级管理员');
            $nickname = $helper->ask($input, $output, $nicknameQuestion);

            // 确认操作
            $confirmQuestion = new ConfirmationQuestion(
                '确认要重新初始化ID为1的管理员信息吗？这将会覆盖现有信息 [y/N]: ',
                false
            );

            if (!$helper->ask($input, $output, $confirmQuestion)) {
                $output->writeln('操作已取消');

                return Command::SUCCESS;
            }

            // 更新或创建管理员信息
            $this->reInitAdmin($username, $password, $nickname, $output);

            $output->writeln('<info>管理员账户信息重新初始化成功!</info>');

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $output->writeln('<error>发生错误: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
    }

    /**
     * 重新初始化管理员
     *
     * @param string $username
     * @param string $password
     * @param string $nickname
     * @param OutputInterface $output
     *
     * @return void
     */
    private function reInitAdmin(string $username, string $password, string $nickname, OutputInterface $output)
    {
        $now = utc_now_string('Y-m-d H:i:s');

        $adminData = [
            'username' => $username,
            'password' => Util::passwordHash($password),
            'nickname' => $nickname,
            'updated_at' => $now,
        ];

        // 检查ID为1的管理员是否存在
        $admin = Admin::find(1);

        if ($admin) {
            // 更新现有管理员
            $output->writeln('更新现有的管理员账户...');
            $admin->fill($adminData);
            $admin->save();
        } else {
            // 创建新的管理员
            $output->writeln('创建新的管理员账户...');
            $adminData['created_at'] = $now;
            $admin = new Admin();
            $admin->fill($adminData);
            $admin->id = 1; // 强制设置ID为1
            $admin->save();

            // 确保ID为1
            if ($admin->id != 1) {
                Admin::where('id', $admin->id)->update(['id' => 1]);
            }
        }

        // 确保管理员拥有角色ID为1的角色
        // 使用原生数据库查询而不是Laravel Facade
        $pdo = Util::db()->getPdo();

        // 检查是否已存在角色关联
        $stmt = $pdo->prepare('SELECT COUNT(*) as count FROM wa_admin_roles WHERE admin_id = ? AND role_id = ?');
        $stmt->execute([1, 1]);
        $result = $stmt->fetch();

        // 如果不存在角色关联，则添加
        if ($result['count'] == 0) {
            $stmt = $pdo->prepare('INSERT INTO wa_admin_roles (admin_id, role_id) VALUES (?, ?)');
            $stmt->execute([1, 1]);
        }
    }
}
