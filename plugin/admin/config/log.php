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

return [
    'default' => [
        'handlers' => [
            [
                'class' => Monolog\Handler\RotatingFileHandler::class,
                'constructor' => [
                    runtime_path() . '/logs/webman.log',
                    7, //$maxFiles
                    $logLevel,
                ],
                'formatter' => [
                    'class' => Monolog\Formatter\LineFormatter::class,
                    'constructor' => [null, 'Y-m-d H:i:s', true],
                ],
            ],
        ],
    ],
];
