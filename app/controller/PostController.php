<?php

namespace app\controller;

use support\Request;
use app\model\Post;
use support\Response;
use Webman\RateLimiter\Annotation\RateLimiter;

class PostController
{
    protected array $noNeedLogin = ['index'];

    #[RateLimiter(limit: 3,ttl: 3)]
    public function index(Request $request, mixed $keyword = null): Response
    {
        // 移除URL参数中的 .html 后缀
        if (is_string($keyword) && str_ends_with($keyword, '.html')) {
            $keyword = substr($keyword, 0, -5);
        }
        
        switch (blog_config('url_mode', 'mix', true)) {
            case 'slug':
                // slug模式
                $post = Post::where('slug', $keyword)->first();
                break;
            case 'id':
                // id模式
                $post = Post::where('id', $keyword)->first();
                if (!$post || $post['status'] != 'published') {
                    return view('error/404');
                }
                break;
            case 'mix':
                // 混合模式
                if (is_numeric($keyword)) {
                    $post = Post::where('id', $keyword)->first();
                    if ($post === null) {
                        $post = Post::where('slug', $keyword)->first();
                    }
                } elseif (is_string($keyword)) {
                    $post = Post::where('slug', $keyword)->first();
                } else {
                    return view('error/404');
                }

                if (!$post || $post['status'] !== 'published') {
                    return view('error/404');
                }
                break;
            default:
                return view('error/404');
                break;
        }
        
        // PJAX 优化：检测是否为 PJAX 请求
        $isPjax = (bool)$request->header('X-PJAX');

        // 获取侧边栏内容（仅非 PJAX 时获取）
        $sidebar = $isPjax ? null : \app\service\SidebarService::getSidebarContent($request, 'post');
        
        // 加载作者信息
        $post->load(['authors', 'primaryAuthor']);
        $primaryAuthor = $post->primaryAuthor->first();
        $authorName = $primaryAuthor ? $primaryAuthor->nickname : ($post->authors->first() ? $post->authors->first()->nickname : '未知作者');
        
        // 动态选择模板
        $viewName = $isPjax ? 'index/post.content' : 'index/post';

        return view($viewName, [
            'page_title' => blog_config('title', 'WindBlog', true) . ' - ' . $post['title'],
            'post' => $post,
            'author' => $authorName,
            'sidebar' => $sidebar
        ]);
    }
}