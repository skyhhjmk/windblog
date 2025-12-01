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
use plugin\admin\app\controller\AiSummaryController;
use plugin\admin\app\controller\CategoryController;
use plugin\admin\app\controller\CommentController;
use plugin\admin\app\controller\DictController;
use plugin\admin\app\controller\EditorController;
use plugin\admin\app\controller\ElasticController;
use plugin\admin\app\controller\FloLinkController;
use plugin\admin\app\controller\LinkConnectController;
use plugin\admin\app\controller\LinkController;
use plugin\admin\app\controller\MailController;
use plugin\admin\app\controller\MediaController;
use plugin\admin\app\controller\PerformanceController;
use plugin\admin\app\controller\PostsController;
use plugin\admin\app\controller\SidebarController;
use plugin\admin\app\controller\StaticCacheController;
use plugin\admin\app\controller\TagController;
use plugin\admin\app\controller\WpImportController;
use support\Request;
use support\Response;
use Webman\Route;

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
        Route::post('/autoAudit/{id}', [LinkController::class, 'autoAudit']);
        Route::post('/monitor', [LinkController::class, 'monitor']);
        Route::post('/batchApprove/{ids}', [LinkController::class, 'batchApprove']);
        Route::post('/batchReject/{ids}', [LinkController::class, 'batchReject']);
        Route::delete('/remove/{id}', [LinkController::class, 'remove']);
        Route::post('/restore/{id}', [LinkController::class, 'restore']);
        Route::delete('/forceDelete/{id}', [LinkController::class, 'forceDelete']);
        Route::delete('/batchRemove/{ids}', [LinkController::class, 'batchRemove']);
        Route::post('/batchRestore/{ids}', [LinkController::class, 'batchRestore']);
        Route::delete('/batchForceDelete/{ids}', [LinkController::class, 'batchForceDelete']);
        Route::get('/get/{id}', [LinkController::class, 'get']);

        // AI审核管理
        Route::group('/moderation', function () {
            Route::get('', [plugin\admin\app\controller\LinkModerationController::class, 'index']);
            Route::get('/', [plugin\admin\app\controller\LinkModerationController::class, 'index']);
            Route::get('/index', [plugin\admin\app\controller\LinkModerationController::class, 'index']);
            Route::get('/stats', [plugin\admin\app\controller\LinkModerationController::class, 'stats']);
            Route::get('/logs', [plugin\admin\app\controller\LinkModerationController::class, 'logs']);
            Route::post('/triggerAudit', [plugin\admin\app\controller\LinkModerationController::class, 'triggerAudit']);
            Route::post('/batchRemoderate', [plugin\admin\app\controller\LinkModerationController::class, 'batchRemoderate']);
            Route::post('/batchAutoAudit', [plugin\admin\app\controller\LinkModerationController::class, 'batchAutoAudit']);
            Route::any('/config', [plugin\admin\app\controller\LinkModerationController::class, 'config']);
        });
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
        Route::get('', [LinkConnectController::class, 'index']);
        Route::get('/', [LinkConnectController::class, 'index']);
        Route::get('/index', [LinkConnectController::class, 'index']);
        Route::get('/getConfig', [LinkConnectController::class, 'getConfig']);
        Route::post('/saveConfig', [LinkConnectController::class, 'saveConfig']);
        Route::get('/getExample', [LinkConnectController::class, 'getExample']);
        Route::post('/testConnection', [LinkConnectController::class, 'testConnection']);
        // 兼容新增接口
        Route::get('/generateLink', [LinkConnectController::class, 'generateLink']);
        Route::post('/applyToPeer', [LinkConnectController::class, 'applyToPeer']);
        // Token 管理（策略B）
        Route::get('/tokens', [LinkConnectController::class, 'tokens']);
        Route::post('/generateToken', [LinkConnectController::class, 'generateToken']);
        Route::post('/invalidateToken', [LinkConnectController::class, 'invalidateToken']);
    });

    // 新增 /linkconnect 路由组（前端使用该前缀）
    Route::group('/linkconnect', function () {
        Route::get('', [LinkConnectController::class, 'index']);
        Route::get('/', [LinkConnectController::class, 'index']);
        Route::get('/index', [LinkConnectController::class, 'index']);
        Route::get('/getConfig', [LinkConnectController::class, 'getConfig']);
        Route::post('/saveConfig', [LinkConnectController::class, 'saveConfig']);
        Route::get('/getExample', [LinkConnectController::class, 'getExample']);
        Route::post('/testConnection', [LinkConnectController::class, 'testConnection']);
        Route::get('/generateLink', [LinkConnectController::class, 'generateLink']);
        Route::post('/applyToPeer', [LinkConnectController::class, 'applyToPeer']);
        // Token 管理（策略B）
        Route::get('/tokens', [LinkConnectController::class, 'tokens']);
        Route::post('/generateToken', [LinkConnectController::class, 'generateToken']);
        Route::post('/invalidateToken', [LinkConnectController::class, 'invalidateToken']);
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

    // Category 路由
    Route::group('/category', function () {
        Route::get('', [CategoryController::class, 'index']);
        Route::get('/', [CategoryController::class, 'index']);
        Route::get('/index', [CategoryController::class, 'index']);
        Route::get('/list', [CategoryController::class, 'list']);
        Route::get('/get/{id}', [CategoryController::class, 'get']);
        Route::post('/create', [CategoryController::class, 'create']);
        Route::post('/update/{id}', [CategoryController::class, 'update']);
        Route::delete('/remove/{id}', [CategoryController::class, 'remove']);
        Route::post('/restore/{id}', [CategoryController::class, 'restore']);
        Route::delete('/forceDelete/{id}', [CategoryController::class, 'forceDelete']);
        Route::delete('/batchRemove/{ids}', [CategoryController::class, 'batchRemove']);
        Route::post('/batchRestore/{ids}', [CategoryController::class, 'batchRestore']);
        Route::delete('/batchForceDelete/{ids}', [CategoryController::class, 'batchForceDelete']);
        Route::get('/parents', [CategoryController::class, 'parents']);
    });

    // Ads 路由
    Route::group('/ads', function () {
        Route::get('', [plugin\admin\app\controller\AdsController::class, 'index']);
        Route::get('/', [plugin\admin\app\controller\AdsController::class, 'index']);
        Route::get('/index', [plugin\admin\app\controller\AdsController::class, 'index']);
        Route::get('/list', [plugin\admin\app\controller\AdsController::class, 'list']);
        Route::get('/add', [plugin\admin\app\controller\AdsController::class, 'add']);
        Route::post('/add', [plugin\admin\app\controller\AdsController::class, 'add']);
        Route::get('/edit/{id}', [plugin\admin\app\controller\AdsController::class, 'edit']);
        Route::post('/edit/{id}', [plugin\admin\app\controller\AdsController::class, 'edit']);
        Route::get('/get/{id}', [plugin\admin\app\controller\AdsController::class, 'get']);
        Route::post('/remove/{id}', [plugin\admin\app\controller\AdsController::class, 'remove']);
        Route::post('/restore/{id}', [plugin\admin\app\controller\AdsController::class, 'restore']);
        Route::post('/toggleEnabled/{id}', [plugin\admin\app\controller\AdsController::class, 'toggleEnabled']);

        // 全局 Google AdSense 配置
        Route::get('/config', [plugin\admin\app\controller\AdsController::class, 'config']);
        Route::get('/config/get', [plugin\admin\app\controller\AdsController::class, 'getConfig']);
        Route::post('/config/save', [plugin\admin\app\controller\AdsController::class, 'saveConfig']);
    });

    // Tag 路由
    Route::group('/tag', function () {
        Route::get('', [TagController::class, 'index']);
        Route::get('/', [TagController::class, 'index']);
        Route::get('/index', [TagController::class, 'index']);
        Route::get('/list', [TagController::class, 'list']);
        Route::get('/get/{id}', [TagController::class, 'get']);
        Route::post('/create', [TagController::class, 'create']);
        Route::post('/update/{id}', [TagController::class, 'update']);
        Route::delete('/remove/{id}', [TagController::class, 'remove']);
        Route::post('/restore/{id}', [TagController::class, 'restore']);
        Route::delete('/forceDelete/{id}', [TagController::class, 'forceDelete']);
        Route::delete('/batchRemove/{ids}', [TagController::class, 'batchRemove']);
        Route::post('/batchRestore/{ids}', [TagController::class, 'batchRestore']);
        Route::delete('/batchForceDelete/{ids}', [TagController::class, 'batchForceDelete']);
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

        // AI审核管理
        Route::group('/moderation', function () {
            Route::get('', [plugin\admin\app\controller\CommentModerationController::class, 'index']);
            Route::get('/', [plugin\admin\app\controller\CommentModerationController::class, 'index']);
            Route::get('/index', [plugin\admin\app\controller\CommentModerationController::class, 'index']);
            Route::get('/stats', [plugin\admin\app\controller\CommentModerationController::class, 'stats']);
            Route::get('/logs', [plugin\admin\app\controller\CommentModerationController::class, 'logs']);
            Route::post('/batch-remoderate', [plugin\admin\app\controller\CommentModerationController::class, 'batchRemoderate']);
            Route::any('/config', [plugin\admin\app\controller\CommentModerationController::class, 'config']);
        });
    });

    // Index 路由
    Route::group('/index', function () {
        Route::get('/get_site_info', [plugin\admin\app\controller\IndexController::class, 'getSiteInfo']);
    });

    // Editor 路由
    Route::group('/editor', function () {
        Route::get('/vditor', [EditorController::class, 'vditor']);
        Route::get('/vditor/{id}', [EditorController::class, 'vditor']);
        Route::get('/post/{id}', [EditorController::class, 'post']);
        Route::post('/save', [EditorController::class, 'save']);
        Route::post('/upload-image', [EditorController::class, 'uploadImage']);
        Route::get('/authors', [EditorController::class, 'getAuthors']);
    });

    // AI 设置 路由组
    Route::group('/ai', function () {
        // AI 摘要
        Route::group('/summary', function () {
            Route::get('', [AiSummaryController::class, 'index']);
            Route::get('/', [AiSummaryController::class, 'index']);
            Route::get('/index', [AiSummaryController::class, 'index']);
            Route::get('/articles', [AiSummaryController::class, 'articles']);
            Route::get('/stats', [AiSummaryController::class, 'stats']);
            Route::get('/status', [AiSummaryController::class, 'getStatus']);
            Route::post('/set-meta', [AiSummaryController::class, 'setMeta']);
            Route::post('/enqueue', [AiSummaryController::class, 'enqueue']);
            Route::post('/reset', [AiSummaryController::class, 'resetStuckTask']);
            Route::post('/reset-all-stuck', [AiSummaryController::class, 'resetAllStuckTasks']);
            Route::get('/prompt', [AiSummaryController::class, 'promptGet']);
            Route::post('/prompt-save', [AiSummaryController::class, 'promptSave']);
        });

        // AI 测试
        Route::group('/test', function () {
            Route::get('', [plugin\admin\app\controller\AiTestController::class, 'index']);
            Route::get('/', [plugin\admin\app\controller\AiTestController::class, 'index']);
            Route::get('/index', [plugin\admin\app\controller\AiTestController::class, 'index']);
            Route::get('/providers', [plugin\admin\app\controller\AiTestController::class, 'getProviders']);
            Route::get('/media', [plugin\admin\app\controller\AiTestController::class, 'getMedia']);
            Route::post('/test', [plugin\admin\app\controller\AiTestController::class, 'test']);
            Route::get('/task-status', [plugin\admin\app\controller\AiTestController::class, 'getTaskStatus']);
            Route::post('/save-template', [plugin\admin\app\controller\AiTestController::class, 'saveTemplate']);
            Route::get('/templates', [plugin\admin\app\controller\AiTestController::class, 'getTemplates']);
            Route::post('/delete-template', [plugin\admin\app\controller\AiTestController::class, 'deleteTemplate']);
        });

        // 提供方管理
        Route::group('/providers', function () {
            Route::get('', [plugin\admin\app\controller\AiProviderController::class, 'index']);
            Route::get('/', [plugin\admin\app\controller\AiProviderController::class, 'index']);
            Route::get('/list', [plugin\admin\app\controller\AiProviderController::class, 'list']);
            Route::get('/detail', [plugin\admin\app\controller\AiProviderController::class, 'detail']);
            Route::post('/create', [plugin\admin\app\controller\AiProviderController::class, 'create']);
            Route::post('/update', [plugin\admin\app\controller\AiProviderController::class, 'update']);
            Route::post('/delete', [plugin\admin\app\controller\AiProviderController::class, 'delete']);
            Route::post('/toggle-enabled', [plugin\admin\app\controller\AiProviderController::class, 'toggleEnabled']);
            Route::get('/templates', [plugin\admin\app\controller\AiProviderController::class, 'templates']);
            Route::get('/template-detail', [plugin\admin\app\controller\AiProviderController::class, 'templateDetail']);
            Route::post('/test', [plugin\admin\app\controller\AiProviderController::class, 'test']);
            Route::post('/fetch-models', [plugin\admin\app\controller\AiProviderController::class, 'fetchModels']);
        });

        // 选择管理（提供方或轮询组）
        Route::group('/selection', function () {
            Route::get('/get', [AiSummaryController::class, 'getSelection']);
            Route::post('/set', [AiSummaryController::class, 'setSelection']);
        });

        // 轮询组管理
        Route::group('/polling-groups', function () {
            Route::get('', [plugin\admin\app\controller\AiPollingGroupController::class, 'index']);
            Route::get('/', [plugin\admin\app\controller\AiPollingGroupController::class, 'index']);
            Route::get('/list', [plugin\admin\app\controller\AiPollingGroupController::class, 'list']);
            Route::post('/create', [plugin\admin\app\controller\AiPollingGroupController::class, 'create']);
            Route::post('/update', [plugin\admin\app\controller\AiPollingGroupController::class, 'update']);
            Route::post('/delete', [plugin\admin\app\controller\AiPollingGroupController::class, 'delete']);
            Route::post('/toggle-enabled', [plugin\admin\app\controller\AiPollingGroupController::class, 'toggleEnabled']);
            Route::get('/available-providers', [plugin\admin\app\controller\AiPollingGroupController::class, 'availableProviders']);
        });
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
        // 失败媒体管理接口
        Route::get('/failed-list', [MediaController::class, 'failedList']);
        Route::post('/retry-failed/{id}', [MediaController::class, 'retryFailed']);
        // 获取媒体引用的文章列表
        Route::get('/references/{id}', [MediaController::class, 'getReferences']);
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
            // 失败媒体管理接口
            Route::get('/failed-media', [WpImportController::class, 'failedMedia'])->name('admin.tools.wp-import.failed-media');
            Route::get('/failed-media-list', [WpImportController::class, 'failedMediaList'])->name('admin.tools.wp-import.failed-media-list');
            Route::post('/retry-failed-media/{id}', [WpImportController::class, 'retryFailedMedia'])->name('admin.tools.wp-import.retry-failed-media');
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
        Route::get('/get', [StaticCacheController::class, 'get']);
        Route::post('/save', [StaticCacheController::class, 'save']);

        // URL 策略
        Route::get('/strategies/get', [StaticCacheController::class, 'strategiesGet']);
        Route::post('/strategies/save', [StaticCacheController::class, 'strategiesSave']);
        Route::post('/strategies/scan-posts', [StaticCacheController::class, 'strategiesScanPosts']);

        // 增强功能
        Route::group('/enhanced', function () {
            Route::get('/config', [StaticCacheController::class, 'getEnhancedConfig']);
            Route::post('/config', [StaticCacheController::class, 'saveEnhancedConfig']);
            Route::post('/update-version', [StaticCacheController::class, 'updateVersion']);
            Route::post('/clear-all', [StaticCacheController::class, 'clearAll']);
            Route::get('/stats', [StaticCacheController::class, 'getStats']);
            Route::post('/reset-stats', [StaticCacheController::class, 'resetStats']);
            Route::get('/strategies', [StaticCacheController::class, 'getEnhancedStrategies']);
            Route::post('/strategies', [StaticCacheController::class, 'saveEnhancedStrategies']);
            Route::post('/warmup', [StaticCacheController::class, 'warmup']);
            Route::get('/warmup-urls', [StaticCacheController::class, 'getWarmupUrls']);
            Route::post('/warmup-urls', [StaticCacheController::class, 'saveWarmupUrls']);
        });
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
        Route::get('/config-page', function () {
            $path = base_path() . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'mail' . DIRECTORY_SEPARATOR . 'config.html';
            if (is_file($path)) {
                return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], (string) file_get_contents($path));
            }

            return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8'], 'mail config template not found');
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

    // OAuth平台管理路由
    Route::group('/oauth', function () {
        Route::get('', [plugin\admin\app\controller\OAuthController::class, 'index']);
        Route::get('/', [plugin\admin\app\controller\OAuthController::class, 'index']);
        Route::get('/index', [plugin\admin\app\controller\OAuthController::class, 'index']);
        Route::get('/list', [plugin\admin\app\controller\OAuthController::class, 'list']);
        Route::get('/get', [plugin\admin\app\controller\OAuthController::class, 'get']);
        Route::post('/save', [plugin\admin\app\controller\OAuthController::class, 'save']);
        Route::post('/delete', [plugin\admin\app\controller\OAuthController::class, 'delete']);
        Route::post('/toggle', [plugin\admin\app\controller\OAuthController::class, 'toggle']);
    });
});

Route::fallback(function (Request $request) {
    return response($request->uri() . ' not found', 404);
}, 'admin');
