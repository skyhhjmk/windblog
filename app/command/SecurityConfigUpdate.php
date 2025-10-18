<?php

namespace app\command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 安全配置更新命令
 *
 * 用于更新和强化系统的安全配置
 */
class SecurityConfigUpdate extends Command
{
    protected static $defaultName = 'security:update-config';

    protected static $defaultDescription = 'Update security configuration settings';

    protected function configure()
    {
        $this->addOption('headers', null, InputOption::VALUE_NONE, 'Update security headers')
             ->addOption('csrf', null, InputOption::VALUE_NONE, 'Update CSRF settings')
             ->addOption('rate-limit', null, InputOption::VALUE_NONE, 'Update rate limiting')
             ->addOption('file-upload', null, InputOption::VALUE_NONE, 'Update file upload security')
             ->addOption('all', null, InputOption::VALUE_NONE, 'Update all security configurations');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>🔒 Updating Security Configuration...</info>');
        $output->writeln('');

        $updateHeaders = $input->getOption('headers') || $input->getOption('all');
        $updateCsrf = $input->getOption('csrf') || $input->getOption('all');
        $updateRateLimit = $input->getOption('rate-limit') || $input->getOption('all');
        $updateFileUpload = $input->getOption('file-upload') || $input->getOption('all');

        $results = [];

        if ($updateHeaders) {
            $results['headers'] = $this->updateSecurityHeaders($output);
        }

        if ($updateCsrf) {
            $results['csrf'] = $this->updateCsrfSettings($output);
        }

        if ($updateRateLimit) {
            $results['rate_limit'] = $this->updateRateLimitSettings($output);
        }

        if ($updateFileUpload) {
            $results['file_upload'] = $this->updateFileUploadSettings($output);
        }

        $this->displayResults($output, $results);

        return Command::SUCCESS;
    }

    /**
     * 更新安全头配置
     */
    private function updateSecurityHeaders($output): array
    {
        $output->writeln('<comment>Updating Security Headers...</comment>');

        // 创建安全头配置文件
        $securityHeaders = [
            'x_frame_options' => 'SAMEORIGIN',
            'x_content_type_options' => 'nosniff',
            'x_xss_protection' => '1; mode=block',
            'referrer_policy' => 'strict-origin-when-cross-origin',
            'permissions_policy' => [
                'camera' => '()',
                'microphone' => '()',
                'geolocation' => '()',
                'payment' => '()',
                'usb' => '()',
            ],
            'content_security_policy' => [
                'default-src' => "'self'",
                'script-src' => "'self' 'unsafe-inline' 'unsafe-eval'",
                'style-src' => "'self' 'unsafe-inline'",
                'img-src' => "'self' data: https:",
                'font-src' => "'self' data:",
                'connect-src' => "'self'",
                'frame-ancestors' => "'self'",
            ],
            'hsts_max_age' => 31536000, // 1年
            'hsts_include_subdomains' => true,
            'hsts_preload' => true,
        ];

        // 保存到配置文件
        $configPath = config_path('security.php');
        $configContent = "<?php\n\nreturn " . var_export($securityHeaders, true) . ";\n";

        if (file_put_contents($configPath, $configContent)) {
            return ['success' => true, 'message' => 'Security headers configuration updated'];
        }

        return ['success' => false, 'message' => 'Failed to update security headers configuration'];
    }

    /**
     * 更新CSRF设置
     */
    private function updateCsrfSettings($output): array
    {
        $output->writeln('<comment>Updating CSRF Settings...</comment>');

        $csrfSettings = [
            'enabled' => true,
            'token_name' => '_token',
            'session_key' => 'csrf_tokens',
            'token_ttl' => 3600, // 1小时
            'one_time_only' => true,
            'secure_cookie' => true,
            'http_only_cookie' => true,
            'same_site_cookie' => 'strict',
            'excluded_routes' => [
                'api/*',
                'webhook/*',
            ],
        ];

        // 保存到配置文件
        $configPath = config_path('csrf.php');
        $configContent = "<?php\n\nreturn " . var_export($csrfSettings, true) . ";\n";

        if (file_put_contents($configPath, $configContent)) {
            return ['success' => true, 'message' => 'CSRF settings updated'];
        }

        return ['success' => false, 'message' => 'Failed to update CSRF settings'];
    }

    /**
     * 更新速率限制设置
     */
    private function updateRateLimitSettings($output): array
    {
        $output->writeln('<comment>Updating Rate Limiting Settings...</comment>');

        $rateLimitSettings = [
            'enabled' => true,
            'storage' => 'redis', // redis, memory, database
            'limits' => [
                'login' => [
                    'requests' => 5,
                    'window' => 300, // 5分钟
                ],
                'api' => [
                    'requests' => 100,
                    'window' => 60, // 1分钟
                ],
                'upload' => [
                    'requests' => 10,
                    'window' => 300, // 5分钟
                ],
                'admin' => [
                    'requests' => 60,
                    'window' => 60, // 1分钟
                ],
            ],
            'response' => [
                'retry_after_header' => true,
                'retry_after_seconds' => 60,
                'message' => '请求过于频繁，请稍后再试',
            ],
        ];

        // 保存到配置文件
        $configPath = config_path('rate_limit.php');
        $configContent = "<?php\n\nreturn " . var_export($rateLimitSettings, true) . ";\n";

        if (file_put_contents($configPath, $configContent)) {
            return ['success' => true, 'message' => 'Rate limiting settings updated'];
        }

        return ['success' => false, 'message' => 'Failed to update rate limiting settings'];
    }

    /**
     * 更新文件上传安全设置
     */
    private function updateFileUploadSettings($output): array
    {
        $output->writeln('<comment>Updating File Upload Security Settings...</comment>');

        $fileUploadSettings = [
            'max_file_size' => 10 * 1024 * 1024, // 10MB
            'allowed_types' => [
                'image/jpeg' => ['jpg', 'jpeg'],
                'image/png' => ['png'],
                'image/gif' => ['gif'],
                'image/webp' => ['webp'],
                'application/pdf' => ['pdf'],
                'application/msword' => ['doc'],
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
                'text/plain' => ['txt'],
                'application/zip' => ['zip'],
            ],
            'dangerous_types' => [
                'application/x-php' => ['php', 'php3', 'php4', 'php5', 'phtml'],
                'application/x-httpd-php' => ['php', 'php3', 'php4', 'php5', 'phtml'],
                'text/x-php' => ['php', 'php3', 'php4', 'php5', 'phtml'],
                'application/javascript' => ['js'],
                'text/javascript' => ['js'],
                'text/html' => ['html', 'htm'],
            ],
            'scan_virus' => false, // 是否启用病毒扫描
            'quarantine_directory' => runtime_path('quarantine'),
            'log_uploads' => true,
            'require_auth' => true, // 是否要求用户登录才能上传
        ];

        // 保存到配置文件
        $configPath = config_path('file_upload.php');
        $configContent = "<?php\n\nreturn " . var_export($fileUploadSettings, true) . ";\n";

        if (file_put_contents($configPath, $configContent)) {
            return ['success' => true, 'message' => 'File upload security settings updated'];
        }

        return ['success' => false, 'message' => 'Failed to update file upload security settings'];
    }

    /**
     * 显示更新结果
     */
    private function displayResults(OutputInterface $output, array $results): void
    {
        $output->writeln('<info>Security Configuration Update Results:</info>');

        foreach ($results as $component => $result) {
            $status = $result['success'] ? '✅' : '❌';
            $output->writeln("  {$status} {$component}: {$result['message']}");
        }

        $output->writeln('');
        $output->writeln('<comment>Security configuration has been updated. Please restart the server for changes to take effect.</comment>');
        $output->writeln('<info>Run "php console restart" to apply the new security settings.</info>');
    }
}
