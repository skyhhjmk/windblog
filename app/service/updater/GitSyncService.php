<?php

namespace app\service\updater;

/**
 * Git同步服务（用于dev通道）
 */
class GitSyncService
{
    /**
     * @var string Git执行命令
     */
    private string $gitCommand = 'git';

    /**
     * 构造函数
     */
    public function __construct()
    {
        // 检查git命令是否可用
        $this->checkGitAvailability();
    }

    /**
     * 检查git命令是否可用
     *
     * @throws \RuntimeException
     */
    private function checkGitAvailability(): void
    {
        $result = $this->executeShellCommand("{$this->gitCommand} --version");
        if (strpos($result, 'git version') === false) {
            throw new \RuntimeException('Git命令不可用，请确保已安装Git');
        }
    }

    /**
     * 执行shell命令
     *
     * @param string $command
     *
     * @return string
     * @throws \RuntimeException
     */
    private function executeShellCommand(string $command): string
    {
        $output = [];
        $returnVar = 0;

        exec($command, $output, $returnVar);

        if ($returnVar !== 0) {
            throw new \RuntimeException(implode(PHP_EOL, $output));
        }

        return implode(PHP_EOL, $output);
    }

    /**
     * 同步主分支代码
     *
     * @param string|null $mirror 自定义镜像源
     *
     * @return array {success: bool, message: string, log: array}
     */
    public function syncMainBranch(?string $mirror = null): array
    {
        $log = [];

        try {
            // 检查当前目录是否为git仓库
            $this->checkGitRepository();
            $log[] = '当前目录是git仓库';

            // 如果提供了自定义镜像源，更新remote url
            if ($mirror) {
                $this->updateRemoteUrl($mirror);
                $log[] = "已更新远程仓库地址为: {$mirror}";
            }

            // 获取当前分支
            $currentBranch = $this->getCurrentBranch();
            $log[] = "当前分支: {$currentBranch}";

            // 拉取最新代码
            $pullResult = $this->executeGitCommand('pull origin main');
            $log[] = "拉取代码结果: {$pullResult}";

            // 检查是否有冲突
            if (str_contains($pullResult, 'CONFLICT')) {
                return [
                    'success' => false,
                    'message' => '代码拉取时发生冲突，请手动解决',
                    'log' => $log,
                ];
            }

            // 确保ROOT_PATH常量已定义
            if (!defined('ROOT_PATH')) {
                define('ROOT_PATH', dirname(dirname(dirname(__DIR__))));
            }

            // 检查是否需要更新依赖
            if (file_exists(ROOT_PATH . '/composer.json')) {
                $log[] = '检查依赖更新...';
                // TODO: 考虑是否自动执行 composer install
            }

            return [
                'success' => true,
                'message' => '主分支代码同步成功',
                'log' => $log,
            ];
        } catch (\Throwable $e) {
            $log[] = "错误: {$e->getMessage()}";

            return [
                'success' => false,
                'message' => '代码同步失败: ' . $e->getMessage(),
                'log' => $log,
            ];
        }
    }

    /**
     * 检查当前目录是否为git仓库
     *
     * @throws \RuntimeException
     */
    private function checkGitRepository(): void
    {
        $result = $this->executeGitCommand('rev-parse --is-inside-work-tree');
        if (trim($result) !== 'true') {
            throw new \RuntimeException('当前目录不是git仓库');
        }
    }

    /**
     * 执行git命令
     *
     * @param string $command
     *
     * @return string
     * @throws \RuntimeException
     */
    private function executeGitCommand(string $command): string
    {
        return $this->executeShellCommand("{$this->gitCommand} {$command}");
    }

    /**
     * 更新远程仓库地址
     *
     * @param string $mirror
     *
     * @return string
     * @throws \RuntimeException
     */
    private function updateRemoteUrl(string $mirror): string
    {
        return $this->executeGitCommand("remote set-url origin {$mirror}");
    }

    /**
     * 获取当前分支
     *
     * @return string
     * @throws \RuntimeException
     */
    private function getCurrentBranch(): string
    {
        $result = $this->executeGitCommand('rev-parse --abbrev-ref HEAD');

        return trim($result);
    }

    /**
     * 获取当前git状态
     *
     * @return array {success: bool, message: string, status: string}
     */
    public function getStatus(): array
    {
        try {
            $status = $this->executeGitCommand('status');

            return [
                'success' => true,
                'message' => '获取git状态成功',
                'status' => $status,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => '获取git状态失败: ' . $e->getMessage(),
                'status' => '',
            ];
        }
    }
}
