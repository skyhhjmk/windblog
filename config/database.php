<?php
return [
    // 默认数据库
    'default' => 'mysql',
    // 各种数据库配置
    'connections' => [

        'mysql' => [
            'driver' => 'mysql',
            'host' => 'localhost',
            'port' => 3306,
            'database' => 'windblog',
            'username' => 'root',
            'password' => 'root',
            'unix_socket' => '',
            'charset' => 'utf8',
            'collation' => 'utf8_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
        ],
        'pgsql' => [
            'driver' => 'pgsql',
            'host' => 'localhost',
            'port' => 5432,
            'database' => 'windblog',
            'username' => 'root',
            'password' => 'root',
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
            'sslmode' => 'prefer',
        ],
    ]
];