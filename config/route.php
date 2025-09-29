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

use Webman\Route;

//Route::disableDefaultRoute();

//Route::any('/push/{id}', function ($id) {
//    return view('push/id-test', ['id' => $id]);
//});

// 文章阅读页路由 - 支持 .html 后缀
Route::any('/post/{keyword}', [app\controller\PostController::class, 'index']);
Route::any('/post/{keyword}.html', [app\controller\PostController::class, 'index']);

// 页面路由 - 支持 .html 后缀
//Route::any('/page/{keyword}', [app\controller\PageController::class, 'index']);
//Route::any('/page/{keyword}.html', [app\controller\PageController::class, 'index']);

// 首页路由
Route::any('/', [app\controller\IndexController::class, 'index'])->name('index.index');

// 搜索路由
Route::any('/search', [app\controller\SearchController::class, 'index'])->name('search.index');
Route::any('/search/ajax', [app\controller\SearchController::class, 'ajax'])->name('search.ajax');

// 链接页&分页路由
Route::any('/link', [app\controller\LinkController::class, 'index'])->name('link.index');
Route::any('/link/goto/{id}', [app\controller\LinkController::class, 'goto'])->name('link.goto');
Route::any('/link/info/{id}', [app\controller\LinkController::class, 'info'])->name('link.info');
Route::any('/link/page/{page}', [app\controller\LinkController::class, 'index'])->name('link.page');

// 调试 API
Route::get('/api/hello', function() {
    return json(['hello' => 'webman']);
});

// REST API v1
Route::group('/api/v1', function () {
    // 文章相关API
    Route::get('/post/{id}', [\app\api\controller\v1\ApiPostController::class, 'get']);
    Route::get('/posts', [\app\api\controller\v1\ApiPostController::class, 'index']);
});

Route::fallback(function () {
    return view('error/404');
});