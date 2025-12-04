<?php

namespace app\service\updater;

use Phinx\Config\Config;
use Phinx\Config\ConfigInterface;
use Phinx\Migration\Manager;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * 数据库迁移服务
 */
class DatabaseMigrationService
{
    /**
     * @var ConfigInterface|null Phinx配置
     */
    private ?ConfigInterface $config = null;

    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->initPhinxConfig();
    }

    /**
     * 初始化Phinx配置
     */
    private function initPhinxConfig(): void
    {
        // 获取默认数据库类型
        $defaultDb = env('DB_DEFAULT', 'pgsql');

        // 根据默认数据库类型从环境变量中获取正确的数据库连接信息
        $dbConfig = [];

        switch ($defaultDb) {
            case 'mysql':
                $dbConfig = [
                    'adapter' => 'mysql',
                    'host' => env('DB_MYSQL_HOST', 'localhost'),
                    'name' => env('DB_MYSQL_DATABASE', 'windblog'),
                    'user' => env('DB_MYSQL_USERNAME', 'root'),
                    'pass' => env('DB_MYSQL_PASSWORD', 'root'),
                    'port' => env('DB_MYSQL_PORT', 3306),
                    'charset' => 'utf8mb4',
                ];
                break;

            case 'pgsql':
                $dbConfig = [
                    'adapter' => 'pgsql',
                    'host' => env('DB_PGSQL_HOST', 'localhost'),
                    'name' => env('DB_PGSQL_DATABASE', 'windblog'),
                    'user' => env('DB_PGSQL_USERNAME', 'postgres'),
                    'pass' => env('DB_PGSQL_PASSWORD', 'postgres'),
                    'port' => env('DB_PGSQL_PORT', 5432),
                    'charset' => 'utf8',
                ];
                break;

            case 'sqlite':
                $dbConfig = [
                    'adapter' => 'sqlite',
                    'name' => env('DB_SQLITE_DATABASE', runtime_path('windblog.db')),
                    'charset' => 'utf8',
                ];
                break;

            default:
                // 默认使用MySQL配置
                $dbConfig = [
                    'adapter' => 'mysql',
                    'host' => env('DB_MYSQL_HOST', 'localhost'),
                    'name' => env('DB_MYSQL_DATABASE', 'windblog'),
                    'user' => env('DB_MYSQL_USERNAME', 'root'),
                    'pass' => env('DB_MYSQL_PASSWORD', 'root'),
                    'port' => env('DB_MYSQL_PORT', 3306),
                    'charset' => 'utf8mb4',
                ];
        }

        // 创建Phinx配置
        $this->config = new Config([
            'paths' => [
                'migrations' => base_path() . '/database/migrations',
                'seeds' => base_path() . '/database/seeds',
            ],
            'environments' => [
                'default_migration_table' => 'phinxlog',
                'default_environment' => 'production',
                'production' => $dbConfig,
            ],
        ]);
    }

    /**
     * 执行数据库迁移
     *
     * @return array {success: bool, message: string, migrations: array}
     */
    public function migrate(): array
    {
        try {
            $input = new ArrayInput([]);
            $output = new BufferedOutput();
            $manager = new Manager($this->config, $input, $output);

            // 执行迁移 - 传递环境参数
            $manager->migrate('production');

            $outputContent = $output->fetch();
            $migrations = $this->parseMigrationOutput($outputContent);

            return [
                'success' => true,
                'message' => '数据库迁移执行成功',
                'migrations' => $migrations,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => '数据库迁移执行异常: ' . $e->getMessage(),
                'migrations' => [],
            ];
        }
    }

    /**
     * 解析迁移输出
     *
     * @param string $output
     *
     * @return array
     */
    private function parseMigrationOutput(string $output): array
    {
        // 解析迁移输出，提取迁移信息
        $migrations = [];
        $lines = explode(PHP_EOL, $output);

        foreach ($lines as $line) {
            if (str_contains($line, '==')) {
                $migrations[] = trim($line);
            }
        }

        return $migrations;
    }

    /**
     * 获取迁移状态
     *
     * @return array {success: bool, message: string, status: array}
     */
    public function getStatus(): array
    {
        try {
            $manager = new Manager($this->config, new ArrayInput([]), new BufferedOutput());

            // 获取迁移列表
            $migrations = $manager->getMigrations('production');
            $status = [];

            // 解析迁移状态
            if (isset($migrations['production'])) {
                foreach ($migrations['production'] as $migration) {
                    $status[] = [
                        'status' => $migration['migration']->isMigrating() ? 'up' : 'down',
                        'migration' => $migration['version'] . ' - ' . $migration['migration']->getName(),
                    ];
                }
            }

            return [
                'success' => true,
                'message' => '获取迁移状态成功',
                'status' => $status,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => '获取迁移状态异常: ' . $e->getMessage(),
                'status' => [],
            ];
        }
    }

    /**
     * 解析状态输出
     *
     * @param string $output
     *
     * @return array
     */
    private function parseStatusOutput(string $output): array
    {
        // 解析状态输出，提取迁移状态信息
        $status = [];
        $lines = explode(PHP_EOL, $output);

        foreach ($lines as $line) {
            if (str_contains($line, '---')) {
                continue;
            }

            if (str_contains($line, 'Status')) {
                continue;
            }

            if (preg_match('/^(\w+)\s+(.+)$/', $line, $matches)) {
                $status[] = [
                    'status' => $matches[1],
                    'migration' => $matches[2],
                ];
            }
        }

        return $status;
    }
}
