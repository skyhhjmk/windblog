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
$global = [
    app\middleware\AuthCheck::class,
    app\middleware\SecurityHeaders::class,
    app\middleware\Lang::class,
//    app\middleware\DebugToolkit::class,
//    app\middleware\IpChecker::class,
    // 新增的安全和性能优化中间件
    app\middleware\SecureFileUpload::class,
//    app\middleware\EnhancedAuthCheck::class,
];

if (getenv('DB_DEFAULT')) {
    $must_installed = [
        // 首屏极快返回骨架（最高优先级，用户首次访问时立即看到 loading）
        app\middleware\InstantFirstPaint::class,
        // 命中静态缓存则直接返回（骨架页二次请求时命中缓存）
        app\middleware\StaticCacheRedirect::class,
        // 常规中间件
        app\middleware\CSRFMiddleware::class,
    ];

    $global = array_merge($global, $must_installed);
}

$middleware = [
    // 全局中间件
    '' => $global,
];

return $middleware;
