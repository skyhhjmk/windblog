<?php

/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

use Webman\Session\FileSessionHandler;
use Webman\Session\RedisClusterSessionHandler;
use Webman\Session\RedisSessionHandler;

// 从数据库读取session配置
$dbSessionConfig = [];
try {
    if (function_exists('blog_config') && function_exists('is_installed') && is_installed()) {
        $dbSessionConfig = blog_config('session_config', null, false) ?: [];
    }
} catch (Throwable $e) {
    // 忽略错误，使用默认配置
}

// 默认配置
$defaultConfig = [
    'handler' => FileSessionHandler::class,
    'type' => 'file',
    'config' => [
        'file' => [
            'save_path' => runtime_path() . '/sessions',
        ],
        'redis' => [
            'host' => '127.0.0.1',
            'port' => 6379,
            'auth' => '',
            'timeout' => 2,
            'database' => '0',
            'prefix' => 'redis_session_',
        ],
        'redis_cluster' => [
            'host' => ['127.0.0.1:7000', '127.0.0.1:7001', '127.0.0.1:7001'],
            'timeout' => 2,
            'auth' => '',
            'prefix' => 'redis_session_',
        ],
    ],
    'session_name' => 'PHPSID',
    'auto_update_timestamp' => false,
    'lifetime' => 7 * 24 * 60 * 60,
    'cookie_lifetime' => 365 * 24 * 60 * 60,
    'cookie_path' => '/',
    'domain' => '',
    'http_only' => true,
    'secure' => false,
    'same_site' => 'strict',
    'gc_probability' => [1, 1000],
];

// 如果有数据库配置，使用数据库配置覆盖默认配置
if (!empty($dbSessionConfig)) {
    // 映射handler类名
    $handlerMap = [
        'FileSessionHandler' => FileSessionHandler::class,
        'RedisSessionHandler' => RedisSessionHandler::class,
        'RedisClusterSessionHandler' => RedisClusterSessionHandler::class,
    ];

    if (isset($dbSessionConfig['handler']) && isset($handlerMap[$dbSessionConfig['handler']])) {
        $defaultConfig['handler'] = $handlerMap[$dbSessionConfig['handler']];
    }

    if (isset($dbSessionConfig['type'])) {
        $defaultConfig['type'] = $dbSessionConfig['type'];
    }

    // 基本配置
    if (isset($dbSessionConfig['session_name'])) {
        $defaultConfig['session_name'] = $dbSessionConfig['session_name'];
    }
    if (isset($dbSessionConfig['auto_update_timestamp'])) {
        $defaultConfig['auto_update_timestamp'] = (bool) $dbSessionConfig['auto_update_timestamp'];
    }
    if (isset($dbSessionConfig['lifetime'])) {
        $defaultConfig['lifetime'] = (int) $dbSessionConfig['lifetime'];
    }
    if (isset($dbSessionConfig['cookie_lifetime'])) {
        $defaultConfig['cookie_lifetime'] = (int) $dbSessionConfig['cookie_lifetime'];
    }
    if (isset($dbSessionConfig['cookie_path'])) {
        $defaultConfig['cookie_path'] = $dbSessionConfig['cookie_path'];
    }
    if (isset($dbSessionConfig['domain'])) {
        $defaultConfig['domain'] = $dbSessionConfig['domain'];
    }
    if (isset($dbSessionConfig['http_only'])) {
        $defaultConfig['http_only'] = (bool) $dbSessionConfig['http_only'];
    }
    if (isset($dbSessionConfig['secure'])) {
        $defaultConfig['secure'] = (bool) $dbSessionConfig['secure'];
    }
    if (isset($dbSessionConfig['same_site'])) {
        $defaultConfig['same_site'] = $dbSessionConfig['same_site'];
    }

    // File配置
    if (!empty($dbSessionConfig['file_save_path'])) {
        $defaultConfig['config']['file']['save_path'] = $dbSessionConfig['file_save_path'];
    }

    // Redis配置
    if (isset($dbSessionConfig['redis_host'])) {
        $defaultConfig['config']['redis']['host'] = $dbSessionConfig['redis_host'];
    }
    if (isset($dbSessionConfig['redis_port'])) {
        $defaultConfig['config']['redis']['port'] = (int) $dbSessionConfig['redis_port'];
    }
    if (isset($dbSessionConfig['redis_auth'])) {
        $defaultConfig['config']['redis']['auth'] = $dbSessionConfig['redis_auth'];
    }
    if (isset($dbSessionConfig['redis_timeout'])) {
        $defaultConfig['config']['redis']['timeout'] = (int) $dbSessionConfig['redis_timeout'];
    }
    if (isset($dbSessionConfig['redis_database'])) {
        $defaultConfig['config']['redis']['database'] = $dbSessionConfig['redis_database'];
    }
    if (isset($dbSessionConfig['redis_prefix'])) {
        $defaultConfig['config']['redis']['prefix'] = $dbSessionConfig['redis_prefix'];
    }
}

return $defaultConfig;
