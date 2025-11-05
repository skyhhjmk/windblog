<?php

namespace app\controller;

use app\annotation\EnableInstantFirstPaint;
use app\helper\BreadcrumbHelper;
use app\model\Post;
use app\service\FloLinkService;
use app\service\PJAXHelper;
use app\service\SidebarService;
use Exception;
use support\Log;
use support\Request;
use support\Response;

class PostController
{
    /**
     * 不需要登录的方法
     * index: 文章详情页，公开访问（包括公开文章和密码文章）
     */
    protected array $noNeedLogin = ['index'];

    #[EnableInstantFirstPaint]
    public function index(Request $request, mixed $keyword = null): Response
    {
        // 移除URL参数中的 .html 后缀
        if (is_string($keyword) && str_ends_with($keyword, '.html')) {
            $keyword = substr($keyword, 0, -5);
        }

        // 统一使用 slug 模式，提高性能并降低错误率
        $post = Post::where('slug', $keyword)
            ->where('status', 'published')
            ->first();

        if (!$post) {
            return view('error/404');
        }

        // 使用PJAXHelper检测是否为PJAX请求
        $isPjax = PJAXHelper::isPJAX($request);

        // 获取侧边栏内容（PJAX 与非 PJAX 均获取）
        $sidebar = SidebarService::getSidebarContent($request, 'post');

        // 加载作者信息与分类、标签
        $post->load(['authors', 'primaryAuthor', 'categories', 'tags']);
        $primaryAuthor = $post->primaryAuthor->first();
        $authorName = $primaryAuthor ? $primaryAuthor->nickname : ($post->authors->first() ? $post->authors->first()->nickname : '未知作者');

        if ($post->visibility === 'public') {

            // 生成面包屑导航
            $breadcrumbs = BreadcrumbHelper::forPost($post);

            // 使用FloLink处理文章内容
            if (blog_config('flolink_enabled', true)) {
                try {
                    $post->content = FloLinkService::processContent($post->content);
                } catch (Exception $e) {
                    Log::error('FloLink处理失败: ' . $e->getMessage());
                    // 处理失败时使用原始内容
                }
            }

            // 动态选择模板：PJAX 返回片段，非 PJAX 返回完整页面
            $viewName = PJAXHelper::getViewName('index/post', $isPjax);

            // 非 PJAX 请求启用页面级缓存（TTL=120）
            $cacheKey = null;
            if (!$isPjax) {
                $locale = $request->header('Accept-Language') ?? 'zh-CN';
                $route = 'post.index';
                $params = ['slug' => $keyword];
                $cacheKey = PJAXHelper::generateCacheKey($route, $params, 1, $locale);
            }

            // 创建带缓存的PJAX响应
            $resp = PJAXHelper::createResponse(
                $request,
                $viewName,
                [
                    'page_title' => $post['title'] . ' - ' . blog_config('title', 'WindBlog', true),
                    'post' => $post,
                    'author' => $authorName,
                    'sidebar' => $sidebar,
                    'breadcrumbs' => $breadcrumbs,
                ],
                $cacheKey,
                120,
                'page'
            );
        } elseif ($post->visibility === 'password') {
            $accessble = false;
            $current_password = $request->get('password');
            if ($current_password) {
                if (password_verify($current_password, $post->password)) {
                    $accessble = true;
                } else {
                    $note = '密码错误';
                }
            }
            if ($accessble) {
                // 生成面包屑导航
                $breadcrumbs = BreadcrumbHelper::forPost($post);

                // 使用FloLink处理文章内容
                if (blog_config('flolink_enabled', true)) {
                    try {
                        $post->content = FloLinkService::processContent($post->content);
                    } catch (Exception $e) {
                        Log::error('FloLink处理失败: ' . $e->getMessage());
                        // 处理失败时使用原始内容
                    }
                }

                // 动态选择模板：PJAX 返回片段，非 PJAX 返回完整页面
                $viewName = PJAXHelper::getViewName('index/post', $isPjax);

                // 非 PJAX 请求启用页面级缓存（TTL=120）
                $cacheKey = null;
                if (!$isPjax) {
                    $locale = $request->header('Accept-Language') ?? 'zh-CN';
                    $route = 'post.index';
                    $params = ['slug' => $keyword];
                    $cacheKey = PJAXHelper::generateCacheKey($route, $params, 1, $locale);
                }

                // 创建带缓存的PJAX响应
                $resp = PJAXHelper::createResponse(
                    $request,
                    $viewName,
                    [
                        'page_title' => $post['title'] . ' - ' . blog_config('title', 'WindBlog', true),
                        'post' => $post,
                        'author' => $authorName,
                        'sidebar' => $sidebar,
                        'breadcrumbs' => $breadcrumbs,
                    ],
                    $cacheKey,
                    120,
                    'page'
                );
            } else {
                $viewName = PJAXHelper::getViewName('lock/post', $isPjax);
                $resp = view($viewName, [
                    'note' => $note ?? null,
                    'post' => $post,
                    'author' => $authorName,
                    'sidebar' => $sidebar,
                ]);
            }
        }

        if (empty($resp)) {
            $resp = view('error/404');
        }

        return $resp;
    }
}
