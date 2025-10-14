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

//use support\view\Raw;
//use support\view\Blade;
//use support\view\ThinkPHP;
use app\service\TwigTemplateService;
use app\view\extension\CsrfExtension;
use app\view\extension\PathExtension;
//use app\view\extension\TranslateExtension;
use Twig\Extra\Cache\CacheExtension;
use Twig\Extra\Cache\CacheRuntime;
use Twig\Extra\String\StringExtension;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

//use support\view\Twig;

$debug = (bool) env('APP_DEBUG', false);
$cacheEnableEnv = env('TWIG_CACHE_ENABLE', null);
$cacheEnable = $cacheEnableEnv !== null ? filter_var($cacheEnableEnv, FILTER_VALIDATE_BOOL) : !$debug;
$cachePath = env('TWIG_CACHE_PATH', runtime_path() . DIRECTORY_SEPARATOR . 'twig_cache');
$autoReloadEnv = env('TWIG_AUTO_RELOAD', null);
$autoReload = $autoReloadEnv !== null ? filter_var($autoReloadEnv, FILTER_VALIDATE_BOOL) : $debug;

return [
    'handler' => TwigTemplateService::class,
    'options' => [
        'cache' => $cacheEnable ? $cachePath : false,
        'debug' => $debug,
        'auto_reload' => $autoReload,
        'strict_variables' => false,
        'view_suffix' => 'html.twig',
    ],
    'extension' => function ($twig) {
        // 添加自定义path函数扩展
        $twig->addExtension(new PathExtension());
        // 添加自定义csrf_token函数扩展
        $twig->addExtension(new CsrfExtension());
        // 添加自定义trans函数扩展
        //        $twig->addExtension(new TranslateExtension());
        // 添加缓存扩展
        $twig->addExtension(new CacheExtension());

        // 为缓存扩展注入默认的 PSR-16 适配器（Redis）
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([
            CacheRuntime::class => function () {
                // 使用独立的 cache 连接库（config/redis.php 中的 database=1）
                return new \app\service\cache\RedisCacheAdapter([
                    'connection' => 'cache',
                    'prefix' => env('TWIG_CACHE_PREFIX', 'twig:fragment:'),
                    'default_ttl' => (int) env('TWIG_CACHE_DEFAULT_TTL', 300),
                ]);
            },
        ]));

        // 添加字符串扩展
        $twig->addExtension(new StringExtension());
    },
];
