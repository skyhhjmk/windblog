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
use plugin\admin\app\controller\PostsController;
use Webman\Route;
use support\Request;

Route::any('/app/admin/account/captcha/{type}', [AccountController::class, 'captcha']);

Route::any('/app/admin/dict/get/{name}', [DictController::class, 'get']);

// Posts 路由
Route::group('/app/admin/posts', function () {
    Route::get('/', [PostsController::class, 'index']);
    Route::get('/index', [PostsController::class, 'index']);
    Route::get('/list', [PostsController::class, 'list']);
    Route::get('/create', [PostsController::class, 'create']);
    Route::post('/store', [PostsController::class, 'store']);
    Route::get('/edit/{id}', [PostsController::class, 'edit']);
    Route::post('/update/{id}', [PostsController::class, 'update']);
    Route::get('/view/{id}', [PostsController::class, 'view']);
    Route::delete('/remove/{id}', [PostsController::class, 'remove']);
    Route::delete('/batchRemove/{ids}', [PostsController::class, 'batchRemove']);
});

Route::fallback(function (Request $request) {
    return response($request->uri() . ' not found' , 404);
}, 'admin');
