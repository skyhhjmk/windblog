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
use app\view\extension\CsrfExtension;
use app\view\extension\PathExtension;
use support\view\Twig;

//use support\view\Blade;
//use support\view\ThinkPHP;

return [
    'handler' => Twig::class,
    'options' => [
        'cache' => false,
        'debug' => true,
        'auto_reload' => true,
        'view_suffix' => 'html.twig',
    ],
    'extension' => function ($twig) {
        // 添加自定义path函数扩展
        $twig->addExtension(new PathExtension());
        // 添加自定义csrf_token函数扩展
        $twig->addExtension(new CsrfExtension());
    }
];