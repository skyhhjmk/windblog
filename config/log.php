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

// 根据 APP_DEBUG 环境变量动态设置日志级别
// 开发环境（APP_DEBUG=true）使用 DEBUG 级别，生产环境使用 INFO 级别
$logLevel = (getenv('APP_DEBUG') === 'true' || getenv('APP_DEBUG') === '1')
    ? Monolog\Logger::DEBUG
    : Monolog\Logger::INFO;

// 获取 Logstash 配置
$logstashHost = getenv('LOGSTASH_HOST') ?: 'logstash';
$logstashPort = getenv('LOGSTASH_PORT') ?: 5000;
$enableLogstash = getenv('ENABLE_LOGSTASH') === 'true' || getenv('ENABLE_LOGSTASH') === '1';

// 构建 handlers 数组
$handlers = [
    // 文件日志 handler - 保留 3 天，超过3天自动删除
    [
        'class' => Monolog\Handler\RotatingFileHandler::class,
        'constructor' => [
            runtime_path() . '/logs/webman.log',
            3, // 保留 3 天的日志文件，自动删除旧文件
            $logLevel,
        ],
        'formatter' => [
            'class' => Monolog\Formatter\LineFormatter::class,
            'constructor' => [null, 'Y-m-d H:i:s', true],
        ],
    ],
];

// 如果启用 Logstash，添加 Socket handler
if ($enableLogstash) {
    $handlers[] = [
        'class' => Monolog\Handler\SocketHandler::class,
        'constructor' => [
            "tcp://{$logstashHost}:{$logstashPort}",
            $logLevel,
        ],
        'formatter' => [
            'class' => Monolog\Formatter\JsonFormatter::class,
            'constructor' => [],
        ],
    ];
}

return [
    'default' => [
        'handlers' => $handlers,
    ],
];
