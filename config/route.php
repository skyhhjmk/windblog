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

Route::disableDefaultRoute();

//Route::any('/push/{id}', function ($id) {
//    return view('push/id-test', ['id' => $id]);
//});

// 文章阅读页路由 - 支持 .html 后缀
Route::any('/post/{keyword}', [app\controller\PostController::class, 'index']);
Route::any('/post/{keyword}.html', [app\controller\PostController::class, 'index']);

// 评论相关路由
Route::any('/comment/submit/{postId}', [app\controller\CommentController::class, 'submit']);
Route::any('/comment/list/{postId}', [app\controller\CommentController::class, 'getList']);

// 页面路由 - 支持 .html 后缀
// 首页分页路由 -> IndexController
Route::any('/page/{page}', [app\controller\IndexController::class, 'index'])->name('index.page');
Route::any('/page/{page}.html', [app\controller\IndexController::class, 'index'])->name('index.page.html');

// 首页路由
Route::any('/', [app\controller\IndexController::class, 'index'])->name('index.index');

// 搜索路由
Route::any('/search', [app\controller\SearchController::class, 'index'])->name('search.index');
Route::any('/search/ajax', [app\controller\SearchController::class, 'ajax'])->name('search.ajax');
// 搜索分页路由
Route::any('/search/page/{page}', [app\controller\SearchController::class, 'index'])->name('search.page');
Route::any('/search/page/{page}.html', [app\controller\SearchController::class, 'index'])->name('search.page.html');

// 分类/标签 汇总入口
Route::any('/category', [app\controller\CategoryController::class, 'list'])->name('category.list');
Route::any('/tag', [app\controller\TagController::class, 'list'])->name('tag.list');

// 链接页&分页路由
Route::any('/link', [app\controller\LinkController::class, 'index'])->name('link.index');
Route::any('/link/', [app\controller\LinkController::class, 'index']);
Route::any('/link/goto/{id}', [app\controller\LinkController::class, 'goto'])->name('link.goto');
Route::any('/link/info/{id}', [app\controller\LinkController::class, 'info'])->name('link.info');
Route::any('/link/page/{page}', [app\controller\LinkController::class, 'index'])->name('link.page');
Route::any('/link/request', [app\controller\LinkController::class, 'request'])->name('link.request');
Route::any('/link/connect/apply', [app\controller\LinkController::class, 'connectApply'])->name('link.connect.apply');

// 分类浏览（兼容旧路由）
Route::any('/category/{slug}', [app\controller\CategoryController::class, 'index'])->name('category.index');
Route::any('/category/{slug}.html', [app\controller\CategoryController::class, 'index'])->name('category.index.html');
Route::any('/category/{slug}/page/{page}', [app\controller\CategoryController::class, 'index'])->name('category.page');
Route::any('/category/{slug}/page/{page}.html', [app\controller\CategoryController::class, 'index'])->name('category.page.html');

// 标签浏览（兼容旧路由）
Route::any('/tag/{slug}', [app\controller\TagController::class, 'index'])->name('tag.index');
Route::any('/tag/{slug}.html', [app\controller\TagController::class, 'index'])->name('tag.index.html');
Route::any('/tag/{slug}/page/{page}', [app\controller\TagController::class, 'index'])->name('tag.page');
Route::any('/tag/{slug}/page/{page}.html', [app\controller\TagController::class, 'index'])->name('tag.page.html');

// 简写分类路由
Route::any('/c', [app\controller\CategoryController::class, 'list'])->name('c.list');
Route::any('/c/{slug}', [app\controller\CategoryController::class, 'index'])->name('c.index');
Route::any('/c/{slug}.html', [app\controller\CategoryController::class, 'index'])->name('c.index.html');
Route::any('/c/{slug}/page/{page}', [app\controller\CategoryController::class, 'index'])->name('c.page');
Route::any('/c/{slug}/page/{page}.html', [app\controller\CategoryController::class, 'index'])->name('c.page.html');

// 简写标签路由
Route::any('/t', [app\controller\TagController::class, 'list'])->name('t.list');
Route::any('/t/{slug}', [app\controller\TagController::class, 'index'])->name('t.index');
Route::any('/t/{slug}.html', [app\controller\TagController::class, 'index'])->name('t.index.html');
Route::any('/t/{slug}/page/{page}', [app\controller\TagController::class, 'index'])->name('t.page');
Route::any('/t/{slug}/page/{page}.html', [app\controller\TagController::class, 'index'])->name('t.page.html');

// 调试 API
Route::get('/api/hello', function () {
    return json(['hello' => 'webman']);
});

// Rainyun API工具路由
Route::any('/rainyun', [app\controller\RainyunController::class, 'index'])->name('rainyun.index');

// REST API v1
Route::group('/api/v1', function () {
    // 文章相关API
    Route::get('/post/{id}', [\app\api\controller\v1\ApiPostController::class, 'get']);
    Route::get('/posts', [\app\api\controller\v1\ApiPostController::class, 'index']);
    // 文章内容API（支持 GET 和 POST）
    Route::any('/post/content/{keyword}', [\app\api\controller\v1\ApiPostController::class, 'content']);
});

// 友链互联API
Route::post('/api/wind-connect', [app\controller\LinkController::class, 'windConnect']);

// 动画演示页面路由
Route::any('/animation-demo', [app\controller\AnimationDemoController::class, 'index'])->name('animation.demo');
Route::any('/animation-demo.html', [app\controller\AnimationDemoController::class, 'index'])->name('animation.demo.html');

Route::fallback(function () {
    return view('error/404');
});
