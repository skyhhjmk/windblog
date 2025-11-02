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

// Logstash 配置（更健壮）
$enableLogstash = getenv('ENABLE_LOGSTASH') === 'true' || getenv('ENABLE_LOGSTASH') === '1';
$proto = getenv('LOGSTASH_PROTO') ?: 'tcp';
$port = getenv('LOGSTASH_PORT') ?: 5000;
$dsn = getenv('LOGSTASH_DSN') ?: '';
$hostEnv = getenv('LOGSTASH_HOST') ?: 'logstash';

// 解析容器环境，用于决定回退候选
$inContainerEnv = getenv('IN_CONTAINER');
$inContainer = $inContainerEnv === false ? true : in_array(strtolower(trim($inContainerEnv)), ['true', '1', 'yes'], true);

// 选择第一个可解析的主机名
$resolvedDsn = '';
if ($enableLogstash) {
    if ($dsn) {
        $resolvedDsn = $dsn;
    } else {
        $candidates = array_unique(array_filter([
            $hostEnv,
            'logstash',              // 服务名
            'windblog_logstash',     // container_name 回退
            $inContainer ? null : '127.0.0.1',
            $inContainer ? null : 'localhost',
        ]));
        foreach ($candidates as $h) {
            $ip = @gethostbyname($h);
            if ($ip && $ip !== $h) { // 解析成功
                $resolvedDsn = sprintf('%s://%s:%s', $proto, $h, $port);
                break;
            }
        }
    }
}

// 构建 handlers 数组
$handlers = [
    // 文件日志 handler - 保留 3 天，超过3天自动删除
    [
        'class' => Monolog\Handler\RotatingFileHandler::class,
        'constructor' => [
            runtime_path() . '/logs/windblog.log',
            3,
            $logLevel,
        ],
        'formatter' => [
            'class' => Monolog\Formatter\LineFormatter::class,
            'constructor' => [null, 'Y-m-d H:i:s', true],
        ],
    ],
];

// 如果启用 Logstash 且已得到 DSN，则添加 Socket handler（设置合理超时，防止阻塞/抛错）
if ($enableLogstash && $resolvedDsn) {
    $handlers[] = [
        'class' => Monolog\Handler\SocketHandler::class,
        'constructor' => [
            $resolvedDsn,
            $logLevel,
            true,  // bubble
            1,     // connectionTimeout 秒
            2,     // write timeout 秒
            false,  // persistent
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
