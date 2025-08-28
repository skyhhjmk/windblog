<?php

namespace app\controller;

use support\Request;
use app\model\Posts;
use support\Response;

class PostController
{
    protected array $noNeedLogin = ['index'];

    public function index(Request $request, mixed $keyword = null): Response
    {
        switch (blog_config('url_mode', 'mix', true)) {
            case 'slug':
                // slug模式
                $post = Posts::where('slug', $keyword)->first();
                break;
            case 'id':
                // id模式
                $post = Posts::where('id', $keyword)->first();
                if (!$post || $post['status'] != 'published') {
                    return view('index/404');
                }
                break;
            case 'mix':
                // 混合模式
                if (is_numeric($keyword)) {
                    $post = Posts::where('id', $keyword)->first();
                    if ($post === null) {
                        $post = Posts::where('slug', $keyword)->first();
                    }
                } elseif (is_string($keyword)) {
                    $post = Posts::where('slug', $keyword)->first();
                } else {
                    return view('index/404');
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
            'page_title' => blog_config('title', 'WindBlog', true) . ' - ' . $post['title'],
            'post' => $post,
        ]);
    }
}