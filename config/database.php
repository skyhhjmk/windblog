<?php
return [
    // 默认数据库
    'default' => getenv('DB_DEFAULT') ?: 'pgsql',
    // 各种数据库配置
    'connections' => [
        'pgsql' => [
            'driver' => 'pgsql',
            'host' => getenv('DB_PGSQL_HOST') ?: 'localhost',
            'port' => getenv('DB_PGSQL_PORT') ?: '5432',
            'database' => getenv('DB_PGSQL_DATABASE') ?: 'windblog',
            'username' => getenv('DB_PGSQL_USERNAME') ?: 'root',
            'password' => getenv('DB_PGSQL_PASSWORD') ?: 'root',
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
    ]
];