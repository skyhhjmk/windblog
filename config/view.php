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
//use Twig\Extra\Markdown\MarkdownExtension;
//use Twig\Extra\Markdown\DefaultMarkdown;
//use Twig\Extra\Markdown\MarkdownRuntime;
//use Twig\RuntimeLoader\RuntimeLoaderInterface;
use Twig\Extra\Cache\CacheExtension;
use Twig\Extra\String\StringExtension;
//use support\view\Twig;

return [
    'handler' => TwigTemplateService::class,
    'options' => [
        'cache' => false,
        'debug' => true,
        'auto_reload' => true,
        'view_suffix' => 'html.twig',
    ],
    'extension' => function ($twig) {
//        $twig->addRuntimeLoader(new class implements RuntimeLoaderInterface {
//            public function load($class)
//            {
//                if (MarkdownRuntime::class === $class) {
//                    return new MarkdownRuntime(new DefaultMarkdown());
//                }
//            }
//        });
        // 添加自定义path函数扩展
        $twig->addExtension(new PathExtension());
        // 添加自定义csrf_token函数扩展
        $twig->addExtension(new CsrfExtension());
        // 添加自定义trans函数扩展
//        $twig->addExtension(new TranslateExtension());
        // 添加markdown扩展
//        $twig->addExtension(new MarkdownExtension());
        // 添加缓存扩展
        $twig->addExtension(new CacheExtension());
        // 添加字符串扩展
        $twig->addExtension(new StringExtension());
    }
];
