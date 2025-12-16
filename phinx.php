<?php

/**
 * Phinx配置文件
 */

use Dotenv\Dotenv;

// 尝试加载环境变量
if (file_exists(__DIR__ . '/.env')) {
    try {
        $dotenv = Dotenv::createImmutable(__DIR__);
        $dotenv->load();
    } catch (Exception $e) {
        // 环境变量加载失败，继续使用默认值
    }
}

// 数据库配置
$dbDefault = $_ENV['DB_DEFAULT'] ?? getenv('DB_DEFAULT') ?: 'pgsql';

$dbConfigs = [
    'pgsql' => [
        'adapter' => 'pgsql',
        'host' => $_ENV['DB_PGSQL_HOST'] ?? getenv('DB_PGSQL_HOST') ?: '127.0.0.1',
        'name' => $_ENV['DB_PGSQL_DATABASE'] ?? getenv('DB_PGSQL_DATABASE') ?: 'windblog',
        'user' => $_ENV['DB_PGSQL_USERNAME'] ?? getenv('DB_PGSQL_USERNAME') ?: 'postgres',
        'pass' => $_ENV['DB_PGSQL_PASSWORD'] ?? getenv('DB_PGSQL_PASSWORD') ?: 'postgres',
        'port' => $_ENV['DB_PGSQL_PORT'] ?? getenv('DB_PGSQL_PORT') ?: 5432,
        'charset' => 'utf8',
    ],
    'mysql' => [
        'adapter' => 'mysql',
        'host' => $_ENV['DB_MYSQL_HOST'] ?? getenv('DB_MYSQL_HOST') ?: '127.0.0.1',
        'name' => $_ENV['DB_MYSQL_DATABASE'] ?? getenv('DB_MYSQL_DATABASE') ?: 'windblog',
        'user' => $_ENV['DB_MYSQL_USERNAME'] ?? getenv('DB_MYSQL_USERNAME') ?: 'root',
        'pass' => $_ENV['DB_MYSQL_PASSWORD'] ?? getenv('DB_MYSQL_PASSWORD') ?: '',
        'port' => $_ENV['DB_MYSQL_PORT'] ?? getenv('DB_MYSQL_PORT') ?: 3306,
        'charset' => 'utf8mb4',
    ],
    'sqlite' => [
        'adapter' => 'sqlite',
        'name' => $_ENV['DB_SQLITE_DATABASE'] ?? getenv('DB_SQLITE_DATABASE') ?: __DIR__ . '/runtime/database.sqlite',
    ],
];

return [
    'paths' => [
        'migrations' => __DIR__ . '/database/migrations',
        'seeds' => __DIR__ . '/database/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_database' => $dbDefault,
        $dbDefault => $dbConfigs[$dbDefault],
    ],
    'version_order' => 'creation',
];
