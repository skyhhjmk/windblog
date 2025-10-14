<?php

namespace app\command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * 环境检查命令
 *
 * 检查PHP版本、扩展、权限等运行环境要求
 */
class CheckRequirements extends Command
{
    protected static $defaultName = 'check:requirements';

    protected static $defaultDescription = 'Check system requirements for running WindBlog';

    /**
     * 必需的PHP扩展
     */
    private const REQUIRED_EXTENSIONS = [
        'pdo',
        'pdo_pgsql',
        'mbstring',
        'json',
        'openssl',
        'curl',
        'pcntl',
        'posix',
    ];

    /**
     * 推荐的PHP扩展
     */
    private const RECOMMENDED_EXTENSIONS = [
        'redis' => 'For Redis cache support',
        'apcu' => 'For APCu cache support',
        'imagick' => 'For advanced image processing',
        'gd' => 'For image processing',
        'zip' => 'For archive support',
        'bcmath' => 'For precise decimal calculations',
        'intl' => 'For internationalization support',
        'exif' => 'For image metadata reading',
        'event' => 'For better performance',
    ];

    /**
     * 需要写权限的目录
     */
    private const WRITABLE_DIRS = [
        'runtime',
        'runtime/logs',
        'runtime/views',
        'runtime/sessions',
        'runtime/cache',
        'public/uploads',
        'public/cache',
    ];

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('WindBlog System Requirements Check');

        $allPassed = true;

        // 检查PHP版本
        $allPassed = $this->checkPhpVersion($io) && $allPassed;

        // 检查必需扩展
        $allPassed = $this->checkRequiredExtensions($io) && $allPassed;

        // 检查推荐扩展
        $this->checkRecommendedExtensions($io);

        // 检查目录权限
        $allPassed = $this->checkDirectoryPermissions($io) && $allPassed;

        // 检查配置文件
        $allPassed = $this->checkConfigFiles($io) && $allPassed;

        // 检查依赖服务
        $this->checkDependencies($io);

        // 检查PHP配置
        $this->checkPhpConfiguration($io);

        // 总结
        $io->newLine();
        if ($allPassed) {
            $io->success('All required checks passed! Your system is ready to run WindBlog.');

            return self::SUCCESS;
        } else {
            $io->error('Some required checks failed. Please fix the issues above before running WindBlog.');

            return self::FAILURE;
        }
    }

    /**
     * 检查PHP版本
     */
    private function checkPhpVersion(SymfonyStyle $io): bool
    {
        $io->section('PHP Version');

        $requiredVersion = '8.2.0';
        $currentVersion = PHP_VERSION;

        if (version_compare($currentVersion, $requiredVersion, '>=')) {
            $io->success("PHP version $currentVersion (>= $requiredVersion required) ✓");

            return true;
        } else {
            $io->error("PHP version $currentVersion is too old. Minimum required: $requiredVersion ✗");

            return false;
        }
    }

    /**
     * 检查必需的PHP扩展
     */
    private function checkRequiredExtensions(SymfonyStyle $io): bool
    {
        $io->section('Required PHP Extensions');

        $missing = [];
        $installed = [];

        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            if (extension_loaded($extension)) {
                $installed[] = $extension;
            } else {
                $missing[] = $extension;
            }
        }

        if (!empty($installed)) {
            $io->listing(array_map(fn ($ext) => "$ext ✓", $installed));
        }

        if (!empty($missing)) {
            $io->error('Missing required extensions:');
            $io->listing(array_map(fn ($ext) => "$ext ✗", $missing));

            return false;
        }

        $io->success('All required extensions are installed ✓');

        return true;
    }

    /**
     * 检查推荐的PHP扩展
     */
    private function checkRecommendedExtensions(SymfonyStyle $io): void
    {
        $io->section('Recommended PHP Extensions');

        $table = new Table($output = $io);
        $table->setHeaders(['Extension', 'Status', 'Purpose']);

        foreach (self::RECOMMENDED_EXTENSIONS as $extension => $purpose) {
            $status = extension_loaded($extension) ? '<info>✓ Installed</info>' : '<comment>○ Not installed</comment>';
            $table->addRow([$extension, $status, $purpose]);
        }

        $table->render();
    }

    /**
     * 检查目录写权限
     */
    private function checkDirectoryPermissions(SymfonyStyle $io): bool
    {
        $io->section('Directory Permissions');

        $basePath = base_path();
        $errors = [];
        $success = [];

        foreach (self::WRITABLE_DIRS as $dir) {
            $fullPath = $basePath . DIRECTORY_SEPARATOR . $dir;

            // 创建目录（如果不存在）
            if (!is_dir($fullPath)) {
                if (@mkdir($fullPath, 0o755, true)) {
                    $success[] = "$dir (created) ✓";
                } else {
                    $errors[] = "$dir (failed to create) ✗";
                    continue;
                }
            }

            // 检查写权限
            if (is_writable($fullPath)) {
                $success[] = "$dir ✓";
            } else {
                $errors[] = "$dir (not writable) ✗";
            }
        }

        if (!empty($success)) {
            $io->listing($success);
        }

        if (!empty($errors)) {
            $io->error('The following directories are not writable:');
            $io->listing($errors);
            $io->note('Run: chmod -R 755 runtime public/uploads public/cache');

            return false;
        }

        $io->success('All required directories are writable ✓');

        return true;
    }

    /**
     * 检查配置文件
     */
    private function checkConfigFiles(SymfonyStyle $io): bool
    {
        $io->section('Configuration Files');

        $basePath = base_path();
        $envFile = $basePath . DIRECTORY_SEPARATOR . '.env';

        if (file_exists($envFile)) {
            $io->success('.env file exists ✓');

            // 检查必需的环境变量
            $requiredVars = [
                'DB_PGSQL_HOST',
                'DB_PGSQL_PORT',
                'DB_PGSQL_DATABASE',
                'DB_PGSQL_USERNAME',
                'DB_PGSQL_PASSWORD',
            ];

            $missing = [];
            foreach ($requiredVars as $var) {
                if (empty(getenv($var))) {
                    $missing[] = $var;
                }
            }

            if (!empty($missing)) {
                $io->warning('Missing required environment variables:');
                $io->listing($missing);
            }

            return true;
        } else {
            $io->error('.env file not found ✗');
            $io->note('Copy .env.example to .env and configure your environment');

            return false;
        }
    }

    /**
     * 检查依赖服务
     */
    private function checkDependencies(SymfonyStyle $io): void
    {
        $io->section('External Dependencies');

        $table = new Table($output = $io);
        $table->setHeaders(['Service', 'Required', 'Status']);

        // PostgreSQL
        $pgStatus = $this->checkPostgreSQL();
        $table->addRow(['PostgreSQL', 'Yes', $pgStatus]);

        // Redis
        $redisStatus = $this->checkRedis();
        $table->addRow(['Redis', 'Optional', $redisStatus]);

        // RabbitMQ
        $rabbitStatus = $this->checkRabbitMQ();
        $table->addRow(['RabbitMQ', 'Yes', $rabbitStatus]);

        // ElasticSearch
        $esStatus = $this->checkElasticSearch();
        $table->addRow(['ElasticSearch', 'Optional', $esStatus]);

        $table->render();
    }

    /**
     * 检查PostgreSQL连接
     */
    private function checkPostgreSQL(): string
    {
        try {
            $host = getenv('DB_PGSQL_HOST') ?: 'localhost';
            $port = getenv('DB_PGSQL_PORT') ?: '5432';
            $database = getenv('DB_PGSQL_DATABASE') ?: '';
            $username = getenv('DB_PGSQL_USERNAME') ?: '';
            $password = getenv('DB_PGSQL_PASSWORD') ?: '';

            if (empty($database)) {
                return '<comment>○ Not configured</comment>';
            }

            $dsn = "pgsql:host=$host;port=$port;dbname=$database";
            $pdo = new \PDO($dsn, $username, $password, [
                \PDO::ATTR_TIMEOUT => 3,
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);

            return '<info>✓ Connected</info>';
        } catch (\Exception $e) {
            return '<error>✗ Failed: ' . $e->getMessage() . '</error>';
        }
    }

    /**
     * 检查Redis连接
     */
    private function checkRedis(): string
    {
        if (!extension_loaded('redis')) {
            return '<comment>○ Extension not installed</comment>';
        }

        try {
            $host = getenv('REDIS_HOST') ?: '127.0.0.1';
            $port = getenv('REDIS_PORT') ?: 6379;
            $password = getenv('REDIS_PASSWORD') ?: null;

            $redis = new \Redis();
            $redis->connect($host, (int) $port, 3);

            if ($password) {
                $redis->auth($password);
            }

            $redis->ping();

            return '<info>✓ Connected</info>';
        } catch (\Exception $e) {
            return '<comment>○ Not available</comment>';
        }
    }

    /**
     * 检查RabbitMQ连接
     */
    private function checkRabbitMQ(): string
    {
        // 简单检查，实际环境可以尝试连接
        return '<comment>○ Manual check required</comment>';
    }

    /**
     * 检查ElasticSearch连接
     */
    private function checkElasticSearch(): string
    {
        // 简单检查，实际环境可以尝试连接
        return '<comment>○ Manual check required</comment>';
    }

    /**
     * 检查PHP配置
     */
    private function checkPhpConfiguration(SymfonyStyle $io): void
    {
        $io->section('PHP Configuration');

        $table = new Table($output = $io);
        $table->setHeaders(['Setting', 'Current Value', 'Recommended', 'Status']);

        // Memory limit
        $memoryLimit = ini_get('memory_limit');
        $memoryStatus = $this->compareMemory($memoryLimit, '256M') >= 0 ? '✓' : '⚠';
        $table->addRow(['memory_limit', $memoryLimit, '>= 256M', $memoryStatus]);

        // Max execution time
        $maxExecTime = ini_get('max_execution_time');
        $execStatus = $maxExecTime == 0 || $maxExecTime >= 60 ? '✓' : '⚠';
        $table->addRow(['max_execution_time', $maxExecTime, '>= 60', $execStatus]);

        // Upload max filesize
        $uploadMax = ini_get('upload_max_filesize');
        $uploadStatus = $this->compareMemory($uploadMax, '20M') >= 0 ? '✓' : '⚠';
        $table->addRow(['upload_max_filesize', $uploadMax, '>= 20M', $uploadStatus]);

        // Post max size
        $postMax = ini_get('post_max_size');
        $postStatus = $this->compareMemory($postMax, '20M') >= 0 ? '✓' : '⚠';
        $table->addRow(['post_max_size', $postMax, '>= 20M', $postStatus]);

        // Disable functions
        $disableFunctions = ini_get('disable_functions');
        $criticalFunctions = ['exec', 'shell_exec', 'proc_open', 'pcntl_fork', 'pcntl_signal'];
        $disabledCritical = [];
        foreach ($criticalFunctions as $func) {
            if (stripos($disableFunctions, $func) !== false) {
                $disabledCritical[] = $func;
            }
        }
        $funcStatus = empty($disabledCritical) ? '✓' : '⚠';
        $table->addRow(['disable_functions', empty($disabledCritical) ? 'OK' : implode(', ', $disabledCritical), 'None critical', $funcStatus]);

        $table->render();

        if (!empty($disabledCritical)) {
            $io->warning('Some critical functions are disabled. Run: php webman fix-disable-functions');
        }
    }

    /**
     * 比较内存大小
     */
    private function compareMemory(string $size1, string $size2): int
    {
        $bytes1 = $this->convertToBytes($size1);
        $bytes2 = $this->convertToBytes($size2);

        return $bytes1 <=> $bytes2;
    }

    /**
     * 转换内存大小为字节
     */
    private function convertToBytes(string $size): int
    {
        $size = trim($size);
        $last = strtolower($size[strlen($size) - 1]);
        $value = (int) $size;

        switch ($last) {
            case 'g':
                $value *= 1024;
                // no break
            case 'm':
                $value *= 1024;
                // no break
            case 'k':
                $value *= 1024;
        }

        return $value;
    }
}
