<?php
return [
    'paths' => [
        'migrations' => 'database/migrations',
        'seeds' => 'database/seeds'
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'pgsql',
        'dev' => [
            'adapter' => 'mysql',
            'host' => getenv('DB_MYSQL_HOST') ?: 'localhost',
            'name' => getenv('DB_MYSQL_DATABASE') ?: 'windblog',
            'user' => getenv('DB_MYSQL_USERNAME') ?: 'root',
            'pass' => getenv('DB_MYSQL_PASSWORD') ?: 'root',
            'port' => getenv('DB_MYSQL_PORT') ?: '3306',
            'charset' => 'utf8'
        ],
        'pgsql' => [
            'adapter' => 'pgsql',
            'host' => getenv('DB_PGSQL_HOST') ?: 'localhost',
            'name' => getenv('DB_PGSQL_DATABASE') ?: 'windblog',
            'user' => getenv('DB_PGSQL_USERNAME') ?: 'root',
            'pass' => getenv('DB_PGSQL_PASSWORD') ?: 'root',
            'port' => getenv('DB_PGSQL_PORT') ?: '5432',
            'charset' => 'utf8'
        ]
    ]
];