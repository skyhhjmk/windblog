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
//        app\middleware\AuthCheck::class,
    app\middleware\SecurityHeaders::class,
    app\middleware\Lang::class,
    app\middleware\DebugToolkit::class,
    app\middleware\IpChecker::class,
];

if (file_exists(base_path() . '.env')) {
    $must_installed = [
        app\middleware\StaticCacheRedirect::class,
        app\middleware\CSRFMiddleware::class,
        app\middleware\PluginSupport::class,
    ];

    $global = array_merge($global, $must_installed);
}

$middleware = [
    // 全局中间件
    '' => $global,
];

return $middleware;