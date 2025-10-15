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
use plugin\admin\app\controller\FloLinkController;
use plugin\admin\app\controller\MediaController;
use plugin\admin\app\controller\PostsController;
use plugin\admin\app\controller\WpImportController;
use plugin\admin\app\controller\SidebarController;
use plugin\admin\app\controller\ElasticController;
use plugin\admin\app\controller\PerformanceController;
use plugin\admin\app\controller\StaticCacheController;
use plugin\admin\app\controller\MailController;
use plugin\admin\app\controller\PluginSystemController;
use plugin\admin\app\controller\CommentController;
use Webman\Route;
use support\Request;

Route::group('/app/admin', function () {
    Route::any('/account/captcha/{type}', [AccountController::class, 'captcha']);

    Route::any('/dict/get/{name}', [DictController::class, 'get']);

    // Link 路由
    Route::group('/link', function () {
        Route::get('', [LinkController::class, 'index']);
        // 快速连接接口
        Route::get('/quickConnect', [app\controller\LinkController::class, 'quickConnect']);
        Route::get('/quickConnect/', [app\controller\LinkController::class, 'quickConnect']);
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

    // FloLink 浮动链接路由
    Route::group('/flolink', function () {
        Route::get('', [FloLinkController::class, 'index']);
        Route::get('/', [FloLinkController::class, 'index']);
        Route::get('/index', [FloLinkController::class, 'index']);
        Route::get('/list', [FloLinkController::class, 'list']);
        Route::get('/add', [FloLinkController::class, 'add']);
        Route::post('/add', [FloLinkController::class, 'add']);
        Route::get('/edit/{id}', [FloLinkController::class, 'edit']);
        Route::post('/edit/{id}', [FloLinkController::class, 'edit']);
        Route::post('/remove/{id}', [FloLinkController::class, 'remove']);
        Route::post('/restore/{id}', [FloLinkController::class, 'restore']);
        Route::post('/forceDelete/{id}', [FloLinkController::class, 'forceDelete']);
        Route::post('/batchRemove/{ids}', [FloLinkController::class, 'batchRemove']);
        Route::post('/batchRestore/{ids}', [FloLinkController::class, 'batchRestore']);
        Route::post('/batchForceDelete/{ids}', [FloLinkController::class, 'batchForceDelete']);
        Route::get('/get/{id}', [FloLinkController::class, 'get']);
        Route::post('/toggleStatus/{id}', [FloLinkController::class, 'toggleStatus']);
        Route::post('/clearCache', [FloLinkController::class, 'clearCache']);
    });

    // 互联协议路由（保留原 /link/connect）
    Route::group('/link/connect', function () {
        Route::get('', [\plugin\admin\app\controller\LinkConnectController::class, 'index']);
        Route::get('/', [\plugin\admin\app\controller\LinkConnectController::class, 'index']);
        Route::get('/index', [\plugin\admin\app\controller\LinkConnectController::class, 'index']);
        Route::get('/getConfig', [\plugin\admin\app\controller\LinkConnectController::class, 'getConfig']);
        Route::post('/saveConfig', [\plugin\admin\app\controller\LinkConnectController::class, 'saveConfig']);
        Route::get('/getExample', [\plugin\admin\app\controller\LinkConnectController::class, 'getExample']);
        Route::post('/testConnection', [\plugin\admin\app\controller\LinkConnectController::class, 'testConnection']);
        // 兼容新增接口
        Route::get('/generateLink', [\plugin\admin\app\controller\LinkConnectController::class, 'generateLink']);
        Route::post('/applyToPeer', [\plugin\admin\app\controller\LinkConnectController::class, 'applyToPeer']);
        // Token 管理（策略B）
        Route::get('/tokens', [\plugin\admin\app\controller\LinkConnectController::class, 'tokens']);
        Route::post('/generateToken', [\plugin\admin\app\controller\LinkConnectController::class, 'generateToken']);
        Route::post('/invalidateToken', [\plugin\admin\app\controller\LinkConnectController::class, 'invalidateToken']);
    });

    // 新增 /linkconnect 路由组（前端使用该前缀）
    Route::group('/linkconnect', function () {
        Route::get('', [\plugin\admin\app\controller\LinkConnectController::class, 'index']);
        Route::get('/', [\plugin\admin\app\controller\LinkConnectController::class, 'index']);
        Route::get('/index', [\plugin\admin\app\controller\LinkConnectController::class, 'index']);
        Route::get('/getConfig', [\plugin\admin\app\controller\LinkConnectController::class, 'getConfig']);
        Route::post('/saveConfig', [\plugin\admin\app\controller\LinkConnectController::class, 'saveConfig']);
        Route::get('/getExample', [\plugin\admin\app\controller\LinkConnectController::class, 'getExample']);
        Route::post('/testConnection', [\plugin\admin\app\controller\LinkConnectController::class, 'testConnection']);
        Route::get('/generateLink', [\plugin\admin\app\controller\LinkConnectController::class, 'generateLink']);
        Route::post('/applyToPeer', [\plugin\admin\app\controller\LinkConnectController::class, 'applyToPeer']);
        // Token 管理（策略B）
        Route::get('/tokens', [\plugin\admin\app\controller\LinkConnectController::class, 'tokens']);
        Route::post('/generateToken', [\plugin\admin\app\controller\LinkConnectController::class, 'generateToken']);
        Route::post('/invalidateToken', [\plugin\admin\app\controller\LinkConnectController::class, 'invalidateToken']);
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

    // Comment 路由
    Route::group('/comment', function () {
        Route::get('', [CommentController::class, 'index']);
        Route::get('/', [CommentController::class, 'index']);
        Route::get('/index', [CommentController::class, 'index']);
        Route::get('/list', [CommentController::class, 'list']);
        Route::post('/moderate', [CommentController::class, 'moderate']);
        Route::post('/delete', [CommentController::class, 'delete']);
        Route::post('/restore', [CommentController::class, 'restore']);
    });

    // Index 路由
    Route::group('/index', function () {
        Route::get('/get_site_info', [plugin\admin\app\controller\IndexController::class, 'getSiteInfo']);
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
        // 文本文件预览和编辑接口
        Route::get('/previewText/{id}', [MediaController::class, 'previewText']);
        Route::post('/saveText/{id}', [MediaController::class, 'saveText']);
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
        // ES 同步与重建接口
        Route::post('/rebuild', [ElasticController::class, 'rebuild'])->name('admin.elastic.rebuild');
        Route::post('/sync', [ElasticController::class, 'sync'])->name('admin.elastic.sync');
        Route::get('/index', [ElasticController::class, 'index']);
        Route::post('/save', [ElasticController::class, 'save']);
        Route::post('/createIndex', [ElasticController::class, 'createIndex']);


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
        Route::get('/cacheStats', [PerformanceController::class, 'cacheStats']);
    });

    // 静态缓存 路由（移动到 /app/admin 分组内部）
    Route::group('/static-cache', function () {
        Route::get('', [StaticCacheController::class, 'index']);
        Route::get('/', [StaticCacheController::class, 'index']);
        Route::get('/index', [StaticCacheController::class, 'index']);
        Route::post('/refresh', [StaticCacheController::class, 'refresh']);
        Route::get('/progress', [StaticCacheController::class, 'progress']);

        // URL 策略
        Route::get('/strategies/get', [StaticCacheController::class, 'strategiesGet']);
        Route::post('/strategies/save', [StaticCacheController::class, 'strategiesSave']);
        Route::post('/strategies/scan-posts', [StaticCacheController::class, 'strategiesScanPosts']);
    });

    // 插件系统 路由（独立插件管理）
    Route::group('/plugin-system', function () {
        Route::get('', [PluginSystemController::class, 'index']);
        Route::get('/', [PluginSystemController::class, 'index']);
        Route::get('/index', [PluginSystemController::class, 'index']);

        Route::get('/list', [PluginSystemController::class, 'list']);
        Route::post('/enable', [PluginSystemController::class, 'enable']);
        Route::post('/disable', [PluginSystemController::class, 'disable']);
        Route::post('/uninstall', [PluginSystemController::class, 'uninstall']);
        Route::get('/plugin-menus', [PluginSystemController::class, 'pluginMenus']);
    });

    // 邮件 路由
    Route::group('/mail', function () {
        Route::get('', [MailController::class, 'index']);
        Route::get('/', [MailController::class, 'index']);
        Route::get('/index', [MailController::class, 'index']);

        // 基础配置
        Route::get('/config', [MailController::class, 'configGet']);
        Route::post('/config-save', [MailController::class, 'configSave']);
        Route::post('/config-test', [MailController::class, 'configTest']);

        // 其他功能
        Route::get('/preview', [MailController::class, 'pagePreview']);
        // 多平台配置页（新）
        Route::get('/config-page', function() {
            $path = base_path() . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'mail' . DIRECTORY_SEPARATOR . 'config.html';
            if (is_file($path)) {
                return new \support\Response(200, ['Content-Type' => 'text/html; charset=utf-8'], (string)file_get_contents($path));
            }
            return new \support\Response(404, ['Content-Type' => 'text/plain; charset=utf-8'], 'mail config template not found');
        });
        Route::get('/send', [MailController::class, 'pageSend']);
        Route::get('/preview-render', [MailController::class, 'previewRender']);
        Route::get('/queue-stats', [MailController::class, 'queueStats']);
        Route::post('/enqueue-test', [MailController::class, 'enqueueTest']);

        // 多平台配置 API
        Route::get('/providers', [MailController::class, 'providersGet']);
        Route::post('/providers-save', [MailController::class, 'providersSave']);
        Route::post('/provider-test', [MailController::class, 'providerTest']);
    });
    
    // 配置路由
    Route::group('/config', function () {
        Route::get('/get_site_info', [plugin\admin\app\controller\ConfigController::class, 'get_site_info']);
    });
    
    // 插件沙箱路由
    Route::any('/pluginsandbox[/{slug}[/{action}]]', [plugin\admin\app\controller\PluginSystemController::class, 'handlePluginRequest']);
});

Route::fallback(function (Request $request) {
    return response($request->uri() . ' not found', 404);
}, 'admin');