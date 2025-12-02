<?php

require_once __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$dbConfig = require_once __DIR__ . '/config/database.php';
$defaultConnection = $dbConfig['default'];
$connectionConfig = $dbConfig['connections'][$defaultConnection];

$phinxConfig = [];

switch ($defaultConnection) {
    case 'mysql':
        $phinxConfig = [
            'adapter' => 'mysql',
            'host' => env('DB_MYSQL_HOST') ?? $connectionConfig['host'] ?? 'localhost',
            'name' => env('DB_MYSQL_DATABASE') ?? $connectionConfig['database'] ?? 'windblog',
            'user' => env('DB_MYSQL_USERNAME') ?? $connectionConfig['username'] ?? 'root',
            'pass' => env('DB_MYSQL_PASSWORD') ?? $connectionConfig['password'] ?? 'root',
            'port' => env('DB_MYSQL_PORT') ?? $connectionConfig['port'] ?? 3306,
            'charset' => $connectionConfig['charset'] ?? 'utf8mb4',
        ];
        break;

    case 'pgsql':
        $phinxConfig = [
            'adapter' => 'pgsql',
            'host' => env('DB_PGSQL_HOST') ?? $connectionConfig['host'] ?? 'localhost',
            'name' => env('DB_PGSQL_DATABASE') ?? $connectionConfig['database'] ?? 'windblog',
            'user' => env('DB_PGSQL_USERNAME') ?? $connectionConfig['username'] ?? 'postgres',
            'pass' => env('DB_PGSQL_PASSWORD') ?? $connectionConfig['password'] ?? 'postgres',
            'port' => env('DB_PGSQL_PORT') ?? $connectionConfig['port'] ?? 5432,
            'charset' => env('DB_PGSQL_CHARSET') ?? $connectionConfig['charset'] ?? 'utf8',
            'schema' => $connectionConfig['schema'] ?? 'public', // PG 特有：默认 schema
            'sslmode' => $connectionConfig['sslmode'] ?? 'prefer', // PG 特有：SSL 模式
        ];
        break;

    case 'sqlite':
        $phinxConfig = [
            'adapter' => 'sqlite',
            'name' => env('DB_SQLITE_DATABASE') ?? $connectionConfig['database'] ?? runtime_path('windblog.db'),
            'charset' => $connectionConfig['charset'] ?? 'utf8',
        ];
        break;

    default:
        throw new \RuntimeException("不支持的数据库类型: {$defaultConnection}");
}

if (empty($phinxConfig['name']) && $defaultConnection !== 'sqlite') {
    throw new \RuntimeException('数据库名未配置，请检查 .env 或 config/database.php');
}
if (empty($phinxConfig['user']) && $defaultConnection !== 'sqlite') {
    throw new \RuntimeException('数据库用户名未配置，请检查 .env 或 config/database.php');
}

return [
    'paths' => [
        'migrations' => 'database/migrations', // 迁移文件目录
        'seeds' => 'database/seeds',      // 数据填充目录
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog', // Phinx 迁移日志表（自动创建）
        'default_environment' => 'default',  // 默认环境
        'default' => $phinxConfig, // 注入数据库配置
    ],
    'version_order' => 'creation', // 按迁移文件创建顺序执行
];
