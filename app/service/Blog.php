<?php

namespace app\service;
use support\Db;

class Blog
{
    public function __construct()
    {
    }

    public function getPosts($page): array
    {
        $limit = Db::table('settings')->where('key', 'posts_per_page')->value('value') ?? 10;
        $posts = Db::table('posts')
            ->orderByDesc('created_at')
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get()
            ->toArray();
        return $posts;
    }
}