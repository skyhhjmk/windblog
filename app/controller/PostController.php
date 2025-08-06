<?php

namespace app\controller;

use support\Request;
use app\model\Post;

class PostController
{
    protected array $noNeedLogin = ['index'];

    public function index(Request $request, mixed $keyword = null)
    {
        switch (get_blog_config('url_mode', 'mix')) {
            case 'slug':
                // slug模式
                $post = Post::where('slug', $keyword)->first();
                break;
            case 'id':
                // id模式
                $post = Post::where('id', $keyword)->first();
                if (!$post || $post['status'] != 'published') {
                    return view('index/404');
                }
                break;
            case 'mix':
                // 混合模式
                $post = Post::where('id', $keyword)->first();
                if ($post === null) {
                    $post = Post::where('slug', $keyword)->first();
                }

                if (!$post || $post['status'] !== 'published') {
                    return view('index/404');
                }
                break;
            default:
                return view('index/404');
                break;
        }
        return view('index/post', [
            'page_title' => get_blog_config('title', 'WindBlog') . ' - ' . $post['title'],
            'post' => $post,
        ]);
    }
}