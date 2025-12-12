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

use app\process\Http;
use app\process\Performance;
use support\Log;
use support\Request;

global $argv;
$__cacheDriver = env('CACHE_DRIVER');
$__deployment = strtolower(trim((string) env('DEPLOYMENT_TYPE', 'datacenter')));

// 部署模式拆分（均强制附带 task 进程）
if ($__deployment === 'mailserver') {
    $procs = [
        'mail_worker' => [
            'handler' => app\process\MailWorker::class,
            'reloadable' => false,
            'constructor' => [],
            // 可按需设置并发：'count' => max(1, cpu_count()),
        ],
    ];
    // 无论如何，task 必须存在
    $procs['task'] = [
        'handler' => app\process\Task::class,
    ];

    return $procs;
}

if ($__deployment === 'ai-service') {
    $procs = [
        'ai_summary_worker' => [
            'handler' => app\process\AiSummaryWorker::class,
            'reloadable' => false,
            'constructor' => [],
        ],
        'ai_moderation_worker' => [
            'handler' => app\process\AiModerationWorker::class,
            'reloadable' => false,
            'constructor' => [],
        ],
    ];
    $procs['task'] = [
        'handler' => app\process\Task::class,
    ];

    return $procs;
}

if ($__deployment === 'link-service') {
    $procs = [
        'link_monitor' => [
            'handler' => app\process\LinkMonitor::class,
            'reloadable' => false,
            'constructor' => [],
        ],
        'link_connect_worker' => [
            'handler' => app\process\LinkConnectWorker::class,
            'reloadable' => false,
            'constructor' => [],
        ],
        'link_push_worker' => [
            'handler' => app\process\LinkPushWorker::class,
            'reloadable' => false,
            'constructor' => [],
        ],
        'link_audit_worker' => [
            'handler' => app\process\LinkAuditWorker::class,
            'reloadable' => false,
            'constructor' => [],
        ],
    ];
    $procs['task'] = [
        'handler' => app\process\Task::class,
    ];

    return $procs;
}

if ($__deployment === 'importer') {
    $procs = [
        'importer' => [
            'handler' => app\process\ImportProcess::class,
            'reloadable' => false,
            'constructor' => [],
        ],
        // TODO: 多worker模式
    ];
    $procs['task'] = [
        'handler' => app\process\Task::class,
    ];

    return $procs;
}

if ($__deployment === 'perf') {
    $procs = [];
    if (strtolower(trim((string) $__cacheDriver)) === 'redis') {
        $procs['performance'] = [
            'handler' => Performance::class,
            'count' => 1,
            'reloadable' => true,
            'constructor' => [60, 500],
        ];
    }
    $procs['task'] = [
        'handler' => app\process\Task::class,
    ];

    return $procs;
}

// 默认：datacenter 全量服务
$__processes = [
    'webman' => [
        'handler' => Http::class,
        'listen' => 'http://0.0.0.0:8787',
        'count' => env('WEB_SERVER_PROCESS_COUNT', cpu_count() * 4),
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
            'publicPath' => public_path(),
        ],
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
                'php', 'html', 'htm', 'js', 'css', 'json', 'xml', 'yaml', 'yml', 'twig', 'html.twig', 'tpl',
            ],
            'options' => [
                'enable_file_monitor' => !in_array('-d', $argv) && DIRECTORY_SEPARATOR === '/',
                'enable_memory_monitor' => DIRECTORY_SEPARATOR === '/',
            ],
        ],
    ],
    // 定时任务处理进程
    'task'  => [
        'handler'  => app\process\Task::class,
    ],

];
// 仅在 CACHE_DRIVER=redis 时注册性能采集进程
if (strtolower(trim((string) $__cacheDriver)) === 'redis') {
    $__processes['performance'] = [
        'handler' => Performance::class,
        'count' => 1,
        'reloadable' => true,
        'constructor' => [60, 500],
    ];
}

if (getenv('DB_DEFAULT')) {
    // 友链监控处理进程（存在 .env 时注册）
    $__processes['link_monitor'] = [
        'handler' => app\process\LinkMonitor::class,
        'reloadable' => false,
        'constructor' => [],
    ];
    // WordPress导入处理进程（存在 .env 时注册）
    $__processes['importer'] = [
        'handler' => app\process\ImportProcess::class,
        'reloadable' => false,
        'constructor' => [],
    ];
    // 全站静态化生成进程（存在 .env 时注册）
    $__processes['static_generator'] = [
        'handler' => app\process\StaticGenerator::class,
        'reloadable' => false,
        'constructor' => [
            // 目前不需要额外构造参数，如需可在此扩展
        ],
    ];

    // 邮件发送处理进程（存在 .env 时注册）
    $__processes['mail_worker'] = [
        'handler' => app\process\MailWorker::class,
        'reloadable' => false,
        'constructor' => [],
    ];

    // 定时发布处理进程（存在 .env 时注册）
    $__processes['scheduled_publish_worker'] = [
        'handler' => app\process\ScheduledPublishWorker::class,
        'reloadable' => false,
        'constructor' => [],
    ];

    // AI 摘要生成处理进程（存在 .env 时注册）
    $__processes['ai_summary_worker'] = [
        'handler' => app\process\AiSummaryWorker::class,
        'reloadable' => false,
        'constructor' => [],
    ];

    // AI 评论审核处理进程（存在 .env 时注册）
    $__processes['ai_moderation_worker'] = [
        'handler' => app\process\AiModerationWorker::class,
        'reloadable' => false,
        'constructor' => [],
    ];

    // AI 友链审核处理进程（存在 .env 时注册）
    $__processes['link_audit_worker'] = [
        'handler' => app\process\LinkAuditWorker::class,
        'reloadable' => false,
        'constructor' => [],
    ];

    // 友链互联处理进程（存在 .env 时注册）
    $__processes['link_connect_worker'] = [
        'handler' => app\process\LinkConnectWorker::class,
        'reloadable' => false,
        'constructor' => [],
    ];

    // 友链推送处理进程（存在 .env 时注册）
    $__processes['link_push_worker'] = [
        'handler' => app\process\LinkPushWorker::class,
        'reloadable' => false,
        'constructor' => [],
    ];
}

return $__processes;
