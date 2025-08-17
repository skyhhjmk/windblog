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

Route::any('/push/{id}', function ($id) {
    return view('push/id-test', ['id' => $id]);
});

// 文章阅读页路由
Route::any('/post/{keyword}', [app\controller\PostController::class, 'index']);

// 首页&分页路由
Route::any('/', [app\controller\IndexController::class, 'index'])->name('index.index');
Route::any('/page/{page}', [app\controller\IndexController::class, 'index'])->name('index.page');

// 调试API路由
Route::group('/debug', function () {
    Route::get('/server-info', [app\api\controller\DebugController::class, 'serverInfo']);
    Route::get('/request-info', [app\api\controller\DebugController::class, 'requestInfo']);
    Route::get('/response-info', [app\api\controller\DebugController::class, 'responseInfo']);
});

// 管理后台路由
/*Route::group('/admin', function () {
    Route::any('', [app\admin\controller\IndexController::class, 'index'])->name('admin.index.index');
    // wordpress 导入数据路由
    Route::group('/wordpress-import', function () {
        Route::any('', [app\admin\controller\ImportController::class, 'index'])->name('admin.import.index');
        Route::post('/upload', [app\admin\controller\ImportController::class, 'upload'])->name('admin.import.upload');
        Route::post('/force-upload', [app\admin\controller\ImportController::class, 'forceUpload'])->name('admin.import.force-upload');
        Route::any('/status/{id}', [app\admin\controller\ImportController::class, 'status'])->name('admin.import.status');
        Route::post('/reset/{id}', [app\admin\controller\ImportController::class, 'reset'])->name('admin.import.reset');
        Route::post('/delete/{id}', [app\admin\controller\ImportController::class, 'delete'])->name('admin.import.delete');
    });

    // 文章管理路由
    Route::group('/posts', function () {
        Route::any('', [app\admin\controller\PostsController::class, 'index'])->name('admin.posts.index');
        Route::any('/page/{page}', [app\admin\controller\PostsController::class, 'index'])->name('admin.posts.index');
    });

    // 媒体库路由
    Route::group('/media', function () {
        Route::any('', [app\admin\controller\MediaController::class, 'index'])->name('admin.media.index');
        Route::any('/upload', [app\admin\controller\MediaController::class, 'upload'])->name('admin.media.upload');
        Route::post('/do-upload', [app\admin\controller\MediaController::class, 'doUpload'])->name('admin.media.do-upload');
        Route::any('/edit/{id}', [app\admin\controller\MediaController::class, 'edit'])->name('admin.media.edit');
        Route::post('/update/{id}', [app\admin\controller\MediaController::class, 'update'])->name('admin.media.update');
        Route::post('/delete/{id}', [app\admin\controller\MediaController::class, 'delete'])->name('admin.media.delete');
    });

    // 编辑器路由
    Route::group('/editor', function () {
        Route::post('/save', [app\admin\controller\EditorController::class, 'save'])->name('admin.editor.save');
        Route::post('/upload-image', [app\admin\controller\EditorController::class, 'uploadImage'])->name('admin.editor.upload-image');
        Route::any('/{id}[/{editor}]', [app\admin\controller\EditorController::class, 'index'])->name('admin.editor.index');
    });
});*/



Route::fallback(function () {
    return view('index/404');
});