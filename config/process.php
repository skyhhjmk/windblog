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

use support\Log;
use support\Request;
use app\process\Http;

global $argv;

return [
    'webman' => [
        'handler' => Http::class,
        'listen' => 'http://0.0.0.0:8787',
        'count' => cpu_count() * 4,
//        'count' => 1,
        'user' => '',
        'group' => '',
        'reusePort' => true,
        'eventLoop' => '',
        'context' => [],
        'constructor' => [
            'requestClass' => Request::class,
            'logger' => Log::channel('default'),
            'appPath' => app_path(),
            'publicPath' => public_path()
        ]
    ],
    'webman2' => [
        'handler' => Http::class,
        'listen' => 'http://0.0.0.0:8788',
        'count' => cpu_count() * 4,
//        'count' => 1,
        'user' => '',
        'group' => '',
        'reusePort' => true,
        'eventLoop' => '',
        'context' => [],
        'constructor' => [
            'requestClass' => Request::class,
            'logger' => Log::channel('default'),
            'appPath' => app_path(),
            'publicPath' => public_path()
        ]
    ],
    // File update detection and automatic reload
    'monitor' => [
        'handler' => app\process\Monitor::class,
        'reloadable' => false,
        'constructor' => [
            // Monitor these directories
            'monitorDir' => array_merge([
                app_path(),
                config_path(),
                base_path() . '/process',
                base_path() . '/support',
                base_path() . '/resource',
                base_path() . '/.env',
            ], glob(base_path() . '/plugin/*/app'), glob(base_path() . '/plugin/*/config'), glob(base_path() . '/plugin/*/api')),
            // Files with these suffixes will be monitored
            'monitorExtensions' => [
                'php', 'html', 'htm', 'env', 'js', 'css', 'json', 'xml', 'yaml', 'yml', 'twig', 'html.twig', 'tpl'
            ],
            'options' => [
                'enable_file_monitor' => !in_array('-d', $argv) && DIRECTORY_SEPARATOR === '/',
                'enable_memory_monitor' => DIRECTORY_SEPARATOR === '/',
            ]
        ]
    ],
    // 定时任务处理进程
    'task'  => [
        'handler'  => app\process\Task::class
    ],
    // WordPress导入处理进程
    'importer' => [
        'handler' => app\process\ImportProcess::class,
        'reloadable' => false,
        'constructor' => []
    ],
    // HTTP回调处理进程
    'http_callback' => [
        'handler' => app\process\HttpCallback::class,
        'reloadable' => false,
        'constructor' => [
            // SSL证书验证配置
            'verify_ssl' => false, // 是否验证SSL证书，设置为false可跳过SSL验证
            'ca_cert_path' => null // CA证书路径，如果为空则尝试使用系统默认证书
        ]
    ],
    // 友链监控处理进程
    'link_monitor' => [
        'handler' => app\process\LinkMonitor::class,
        'reloadable' => false,
        'constructor' => []
    ],
    // 全站静态化生成进程
    'static_generator' => [
        'handler' => app\process\StaticGenerator::class,
        'reloadable' => false,
        'constructor' => [
            // 目前不需要额外构造参数，如需可在此扩展
        ]
    ]
];