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
use plugin\admin\app\controller\LinkController;
use plugin\admin\app\controller\MediaController;
use plugin\admin\app\controller\PostsController;
use plugin\admin\app\controller\WpImportController;
use plugin\admin\app\controller\SidebarController;
use plugin\admin\app\controller\ElasticController;
use plugin\admin\app\controller\PerformanceController;
use Webman\Route;
use support\Request;

Route::group('/app/admin', function () {
    Route::any('/account/captcha/{type}', [AccountController::class, 'captcha']);

    Route::any('/dict/get/{name}', [DictController::class, 'get']);

    // Link 路由
    Route::group('/link', function () {
        Route::get('', [LinkController::class, 'index']);
        Route::get('/', [LinkController::class, 'index']);
        Route::get('/index', [LinkController::class, 'index']);
        Route::get('/list', [LinkController::class, 'list']);
        Route::get('/add', [LinkController::class, 'add']);
        Route::post('/add', [LinkController::class, 'add']);
        Route::get('/edit/{id}', [LinkController::class, 'edit']);
        Route::post('/edit/{id}', [LinkController::class, 'edit']);
        Route::get('/view/{id}', [LinkController::class, 'view']);
        Route::get('/audit/{id}', [LinkController::class, 'audit']);
        Route::post('/detectSite', [LinkController::class, 'detectSite']);
        Route::post('/batchApprove/{ids}', [LinkController::class, 'batchApprove']);
        Route::post('/batchReject/{ids}', [LinkController::class, 'batchReject']);
        Route::delete('/remove/{id}', [LinkController::class, 'remove']);
        Route::post('/restore/{id}', [LinkController::class, 'restore']);
        Route::delete('/forceDelete/{id}', [LinkController::class, 'forceDelete']);
        Route::delete('/batchRemove/{ids}', [LinkController::class, 'batchRemove']);
        Route::post('/batchRestore/{ids}', [LinkController::class, 'batchRestore']);
        Route::delete('/batchForceDelete/{ids}', [LinkController::class, 'batchForceDelete']);
        Route::get('/get/{id}', [LinkController::class, 'get']);
    });

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
        Route::get('/authors', [EditorController::class, 'getAuthors']);
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
            Route::get('/create', [WpImportController::class, 'create'])->name('admin.tools.wp-import.create');
//            Route::post('/upload', [WpImportController::class, 'upload'])->name('admin.tools.wp-import.upload');
            Route::post('/submit', [WpImportController::class, 'submit'])->name('admin.tools.wp-import.submit');
            Route::get('/status/{id}', [WpImportController::class, 'status'])->name('admin.tools.wp-import.status');
            Route::post('/reset/{id}', [WpImportController::class, 'reset'])->name('admin.tools.wp-import.reset');
            Route::post('/delete/{id}', [WpImportController::class, 'delete'])->name('admin.tools.wp-import.delete');
        });
    });

    // 侧边栏管理路由
    Route::group('/sidebar', function () {
        Route::get('', [SidebarController::class, 'index'])->name('admin.sidebar.index');
        Route::get('/', [SidebarController::class, 'index']);
        Route::get('/index', [SidebarController::class, 'index']);
        Route::get('/getPages', [SidebarController::class, 'getPages'])->name('admin.sidebar.getPages');
        Route::get('/getAvailableWidgets', [SidebarController::class, 'getAvailableWidgets'])->name('admin.sidebar.getAvailableWidgets');
        Route::get('/getSidebar', [SidebarController::class, 'getSidebar'])->name('admin.sidebar.getSidebar');
        Route::post('/addWidget', [SidebarController::class, 'addWidget'])->name('admin.sidebar.addWidget');
        Route::post('/removeWidget', [SidebarController::class, 'removeWidget'])->name('admin.sidebar.removeWidget');
        Route::post('/updateWidget', [SidebarController::class, 'updateWidget'])->name('admin.sidebar.updateWidget');
        Route::post('/saveSidebar', [SidebarController::class, 'saveSidebar'])->name('admin.sidebar.saveSidebar');
    });

    // Elasticsearch 路由
    Route::group('/elastic', function () {
        Route::get('', [ElasticController::class, 'index']);
        Route::get('/', [ElasticController::class, 'index']);
        Route::get('/index', [ElasticController::class, 'index']);
        Route::post('/save', [ElasticController::class, 'save']);
        Route::post('/createIndex', [ElasticController::class, 'createIndex']);
        Route::post('/rebuild', [ElasticController::class, 'rebuild']);
        Route::get('/get', [ElasticController::class, 'get']);
        Route::get('/test', [ElasticController::class, 'testConnection']);
        Route::get('/logs', [ElasticController::class, 'logs']);
        Route::post('/clearLogs', [ElasticController::class, 'clearLogs']);
    });

    // 性能监控 路由
    Route::group('/performance', function () {
        Route::get('', [PerformanceController::class, 'index']);
        Route::get('/', [PerformanceController::class, 'index']);
        Route::get('/index', [PerformanceController::class, 'index']);
        Route::get('/redisStatus', [PerformanceController::class, 'redisStatus']);
        Route::get('/opcacheStatus', [PerformanceController::class, 'opcacheStatus']);
        Route::get('/series', [PerformanceController::class, 'series']);
    });
});

Route::fallback(function (Request $request) {
    return response($request->uri() . ' not found', 404);
}, 'admin');