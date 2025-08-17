<?php

namespace app\admin\controller;

use app\model\Media;
use app\model\User;
use support\Request;
use app\model\Post;

class IndexController
{

    public function index(Request $request)
    {
        return view('admin/index/index', [
            'post_count' => Post::count(),
            'user_count' => User::count(),
            'media_count' => Media::count(),
            'comment_count' => '0'
        ]);
    }
}