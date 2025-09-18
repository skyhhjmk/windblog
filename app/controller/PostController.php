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
        return view('index/post', [
            'page_title' => blog_config('title', 'WindBlog', true) . ' - ' . $post['title'],
            'post' => $post,
        ]);
    }
}