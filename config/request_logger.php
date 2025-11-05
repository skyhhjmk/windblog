<?php

/**
 * RequestLogger 中间件配置
 *
 * 功能说明：
 * - 数据库查询自动记录为 debug 级别
 * - 请求耗时可配置为 info 或 debug 级别
 * - debug_mode 开启时，记录堆栈追踪信息
 */

return [
    // 日志通道（对应 config/log.php 中的 channel）
    // 环境变量：REQUEST_LOGGER_CHANNEL
    'channel' => env('REQUEST_LOGGER_CHANNEL', 'default'),

    // 请求耗时日志级别：info 或 debug
    // 环境变量：REQUEST_LOGGER_TIMING_LEVEL
    'timing_log_level' => env('REQUEST_LOGGER_TIMING_LEVEL', 'info'),

    // 是否启用 debug 模式（开启后会记录堆栈追踪）
    // 环境变量：REQUEST_LOGGER_DEBUG_MODE 或 APP_DEBUG
    'debug_mode' => env('REQUEST_LOGGER_DEBUG_MODE', env('APP_DEBUG', false)),

    // 是否启用链路追踪（显示请求生命周期的详细信息）
    // 环境变量：REQUEST_LOGGER_ENABLE_TRACE
    'enable_trace' => env('REQUEST_LOGGER_ENABLE_TRACE', true),

    // 不记录日志的配置
    'dontReport' => [
        // 跳过的应用模块
        'app' => [
            // 'admin',
        ],

        // 跳过的路径（前缀匹配）
        'path' => [
            '/app/admin/monitor',
            '/app/admin/upload',
            // '/api/health',
        ],

        // 跳过的控制器
        'controller' => [
            // 'app\controller\HealthController',
        ],

        // 跳过的方法 [controller, action]
        'action' => [
            // ['app\controller\IndexController', 'index'],
        ],
    ],

    // 异常记录配置
    'exception' => [
        // 是否记录异常
        // 环境变量：REQUEST_LOGGER_EXCEPTION_ENABLE
        'enable' => env('REQUEST_LOGGER_EXCEPTION_ENABLE', true),

        // 不需要记录的异常类型
        'dontReport' => [
            // \support\exception\BusinessException::class,
        ],
    ],
];
