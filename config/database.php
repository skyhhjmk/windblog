<?php

$__deployment = strtolower(trim((string) env('DEPLOYMENT_TYPE', 'datacenter')));

return [
    'default' => $__deployment === 'edge' ? 'edge' : (getenv('DB_DEFAULT') ?: 'pgsql'),
    'connections' => [
        'pgsql' => [
            'driver' => 'pgsql',
            'host' => getenv('DB_PGSQL_HOST') ?: 'localhost',
            'port' => getenv('DB_PGSQL_PORT') ?: '5432',
            'database' => getenv('DB_PGSQL_DATABASE') ?: 'windblog',
            'username' => getenv('DB_PGSQL_USERNAME') ?: 'postgres',
            'password' => getenv('DB_PGSQL_PASSWORD') ?: 'postgres',
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
            'sslmode' => 'prefer',
            'options' => [
                PDO::ATTR_PERSISTENT => false,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ],
        ],
        'mysql' => [
            'driver' => 'mysql',
            'host' => getenv('DB_MYSQL_HOST') ?: 'localhost',
            'port' => getenv('DB_MYSQL_PORT') ?: '3306',
            'database' => getenv('DB_MYSQL_DATABASE') ?: 'windblog',
            'username' => getenv('DB_MYSQL_USERNAME') ?: 'root',
            'password' => getenv('DB_MYSQL_PASSWORD') ?: 'root',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
            'options' => [
                PDO::ATTR_PERSISTENT => false,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ],
        ],
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => getenv('DB_SQLITE_DATABASE') ?: runtime_path('windblog.db'),
            'prefix' => '',
            'foreign_key_constraints' => getenv('DB_SQLITE_FOREIGN_KEYS') ?: true,
        ],
        'edge' => [
            'driver' => 'pgsql',
            'host' => getenv('DB_EDGE_HOST') ?: 'localhost',
            'port' => getenv('DB_EDGE_PORT') ?: '5432',
            'database' => getenv('DB_EDGE_DATABASE') ?: 'windblog_edge',
            'username' => getenv('DB_EDGE_USERNAME') ?: 'postgres',
            'password' => getenv('DB_EDGE_PASSWORD') ?: 'postgres',
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
            'sslmode' => 'prefer',
            'options' => [
                PDO::ATTR_PERSISTENT => false,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ],
        ],
    ],
];
