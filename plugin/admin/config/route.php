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

use plugin\admin\app\controller\AccountController;
use plugin\admin\app\controller\DictController;
use plugin\admin\app\controller\EditorController;
use plugin\admin\app\controller\MediaController;
use plugin\admin\app\controller\PostsController;
use plugin\admin\app\controller\WpImportController;
use Webman\Route;
use support\Request;

Route::group('/app/admin', function () {
    Route::any('/account/captcha/{type}', [AccountController::class, 'captcha']);

    Route::any('/dict/get/{name}', [DictController::class, 'get']);

    // Post 路由
    Route::group('/posts', function () {
        Route::get('', [PostsController::class, 'index']);
        Route::get('/', [PostsController::class, 'index']);
        Route::get('/index', [PostsController::class, 'index']);
        Route::get('/list', [PostsController::class, 'list']);
        Route::delete('/remove/{id}', [PostsController::class, 'remove']);
        Route::post('/restore/{id}', [PostsController::class, 'restore']);
        Route::delete('/forceDelete/{id}', [PostsController::class, 'forceDelete']);
        Route::delete('/batchRemove/{ids}', [PostsController::class, 'batchRemove']);
        Route::post('/batchRestore/{ids}', [PostsController::class, 'batchRestore']);
        Route::delete('/batchForceDelete/{ids}', [PostsController::class, 'batchForceDelete']);
    });
    
    // Editor 路由
    Route::group('/editor', function () {
        Route::get('/vditor', [EditorController::class, 'vditor']);
        Route::get('/vditor/{id}', [EditorController::class, 'vditor']);
        Route::post('/save', [EditorController::class, 'save']);
        Route::post('/upload-image', [EditorController::class, 'uploadImage']);
    });
    
    // Media 路由
    Route::group('/media', function () {
        Route::get('', [MediaController::class, 'index']);
        Route::get('/', [MediaController::class, 'index']);
        Route::get('/index', [MediaController::class, 'index']);
        Route::get('/list', [MediaController::class, 'list']);
        Route::post('/upload', [MediaController::class, 'upload']);
        Route::post('/update/{id}', [MediaController::class, 'update']);
        Route::delete('/remove/{id}', [MediaController::class, 'remove']);
        Route::delete('/batchRemove/{ids}', [MediaController::class, 'batchRemove']);
        Route::get('/selector', [MediaController::class, 'selector']);
        Route::post('/regenerateThumbnail/{id}', [MediaController::class, 'regenerateThumbnail']);
    });

    // 工具路由
    Route::group('/tools', function () {
        Route::group('/wp-import', function () {
            Route::get('', [WpImportController::class, 'index'])->name('admin.tools.wp-import.index');
            Route::get('/', [WpImportController::class, 'index']);
            Route::get('/index', [WpImportController::class, 'index']);
            Route::get('/list', [WpImportController::class, 'list'])->name('admin.tools.wp-import.list');
            Route::any('/create', [WpImportController::class, 'create'])->name('admin.tools.wp-import.create');
            Route::post('/upload', [WpImportController::class, 'upload'])->name('admin.tools.wp-import.upload');
            Route::post('/submit', [WpImportController::class, 'submit'])->name('admin.tools.wp-import.submit');
        });
    });

});

Route::fallback(function (Request $request) {
    return response($request->uri() . ' not found', 404);
}, 'admin');