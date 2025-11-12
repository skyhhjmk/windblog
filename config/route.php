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

use app\model\UserOAuthBinding;
use app\service\CSRFService;
use Webman\Route;

Route::disableDefaultRoute();

//Route::any('/push/{id}', function ($id) {
//    return view('push/id-test', ['id' => $id]);
//});

// 文章阅读页路由 - 支持 .html 后缀
Route::any('/post/{keyword}', [app\controller\PostController::class, 'index'])->name('post.index');
Route::any('/post/{keyword}.html', [app\controller\PostController::class, 'index'])->name('post.index.html');

// 评论相关路由
Route::any('/comment/submit/{postId}', [app\controller\CommentController::class, 'submit']);
Route::any('/comment/list/{postId}', [app\controller\CommentController::class, 'getList']);
Route::get('/comment/status/{id}', [app\controller\CommentController::class, 'status']);

// 用户相关路由
Route::get('/user/register', function () {
    // 获取OAuth配置
    $oauthProviders = UserOAuthBinding::getSupportedProviders();

    // 生成CSRF token
    $csrf = (new CSRFService())->generateToken(request(), '_token');

    return view('user/register', [
        'oauthProviders' => $oauthProviders,
        'csrf_token' => $csrf,
    ]);
})->name('user.register.page');
Route::post('/user/register', [app\controller\UserController::class, 'register'])->name('user.register');
Route::get('/user/login', function () {
    // 获取OAuth配置
    $oauthProviders = UserOAuthBinding::getSupportedProviders();

    // 生成OAuth state防止CSRF攻击
    $session = request()->session();
    $oauthState = bin2hex(random_bytes(16));
    $session->set('oauth_state', $oauthState);

    // 生成CSRF token
    $csrf = (new CSRFService())->generateToken(request(), '_token');

    return view('user/login', [
        'oauthProviders' => $oauthProviders,
        'oauthState' => $oauthState,
        'csrf_token' => $csrf,
    ]);
})->name('user.login.page');
Route::post('/user/login', [app\controller\UserController::class, 'login'])->name('user.login');
Route::any('/user/logout', [app\controller\UserController::class, 'logout'])->name('user.logout');
Route::get('/user/activate', [app\controller\UserController::class, 'activate'])->name('user.activate');
Route::post('/user/resend-activation', [app\controller\UserController::class, 'resendActivation'])->name('user.resend.activation');

// 验证码路由
Route::get('/captcha/image', [app\controller\CaptchaController::class, 'image'])->name('captcha.image');
Route::get('/captcha/config', [app\controller\CaptchaController::class, 'config'])->name('captcha.config');
Route::get('/user/profile', [app\controller\UserController::class, 'profile'])->name('user.profile');

// 忘记密码 / 重置密码
Route::get('/user/forgot-password', [app\controller\UserController::class, 'forgotPasswordPage'])->name('user.forgot.password.page');
Route::post('/user/forgot-password', [app\controller\UserController::class, 'forgotPassword'])->name('user.forgot.password');
Route::get('/user/reset-password', [app\controller\UserController::class, 'resetPasswordPage'])->name('user.reset.password.page');
Route::post('/user/reset-password', [app\controller\UserController::class, 'resetPassword'])->name('user.reset.password');
Route::get('/user/profile/api', [app\controller\UserController::class, 'profileApi'])->name('user.profile.api');
Route::post('/user/profile/update', [app\controller\UserController::class, 'updateProfile'])->name('user.profile.update');
Route::get('/user/center', [app\controller\UserController::class, 'center'])->name('user.center');

// OAuth 2.0 预留路由
Route::get('/oauth/{provider}/redirect', [app\controller\UserController::class, 'oauthRedirect'])->name('oauth.redirect');
Route::get('/oauth/{provider}/callback', [app\controller\UserController::class, 'oauthCallback'])->name('oauth.callback');
Route::post('/oauth/{provider}/bind', [app\controller\UserController::class, 'bindOAuth'])->name('oauth.bind');
Route::post('/oauth/{provider}/unbind', [app\controller\UserController::class, 'unbindOAuth'])->name('oauth.unbind');

// 在线用户统计路由
Route::get('/online/count', [app\controller\OnlineController::class, 'count'])->name('online.count');
Route::get('/online/list', [app\controller\OnlineController::class, 'list'])->name('online.list');
Route::get('/online/stats', [app\controller\OnlineController::class, 'stats'])->name('online.stats');
Route::post('/online/heartbeat', [app\controller\OnlineController::class, 'heartbeat'])->name('online.heartbeat');
Route::post('/online/online', [app\controller\OnlineController::class, 'online'])->name('online.online');
Route::post('/online/offline', [app\controller\OnlineController::class, 'offline'])->name('online.offline');
Route::get('/online/check/{userId}', [app\controller\OnlineController::class, 'check'])->name('online.check');

// 页面路由 - 支持 .html 后缀
// 首页分页路由 -> IndexController
Route::any('/page/{page}', [app\controller\IndexController::class, 'index'])->name('index.page');
Route::any('/page/{page}.html', [app\controller\IndexController::class, 'index'])->name('index.page.html');

// 首页路由
Route::any('/', [app\controller\IndexController::class, 'index'])->name('index.index');
Route::any('/index.html', [app\controller\IndexController::class, 'index'])->name('index.index.html');

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
Route::get('/link/connect/check-status', [app\controller\LinkController::class, 'checkTaskStatus'])->name('link.connect.check.status');
Route::post('/link/connect/receive', [app\controller\LinkController::class, 'connectReceive'])->name('link.connect.receive');
Route::any('/link/quick-connect', [app\controller\LinkController::class, 'quickConnect'])->name('link.quick.connect');

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

// Rainyun API工具路由
Route::any('/rainyun', [app\controller\RainyunController::class, 'index'])->name('rainyun.index');

// 友链互联API
Route::post('/api/wind-connect', [app\controller\LinkController::class, 'windConnect'])->name('wind.connect');

// 动画演示页面路由
Route::any('/animation-demo', [app\controller\AnimationDemoController::class, 'index'])->name('animation.demo');
Route::any('/animation-demo.html', [app\controller\AnimationDemoController::class, 'index'])->name('animation.demo.html');

// Sitemap 路由
Route::get('/sitemap.xml', [app\controller\SitemapController::class, 'sitemap'])->name('sitemap.xml');
Route::get('/index_sitemap.xml', [app\controller\SitemapController::class, 'index'])->name('sitemap.index');
Route::get('/sitemap/posts.xml', [app\controller\SitemapController::class, 'posts'])->name('sitemap.posts');
Route::get('/sitemap/categories.xml', [app\controller\SitemapController::class, 'categories'])->name('sitemap.categories');
Route::get('/sitemap/tags.xml', [app\controller\SitemapController::class, 'tags'])->name('sitemap.tags');
Route::get('/sitemap/archives.xml', [app\controller\SitemapController::class, 'archives'])->name('sitemap.archives');
Route::get('/g_sitemap', [app\controller\SitemapController::class, 'graphical'])->name('sitemap.graphical');
Route::get('/g_sitemap.html', [app\controller\SitemapController::class, 'graphical'])->name('sitemap.graphical.html');

Route::fallback(function () {
    return view('error/404')->withStatus(404);
});
