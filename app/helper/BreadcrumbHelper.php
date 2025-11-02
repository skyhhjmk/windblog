<?php

namespace app\helper;

use app\model\Category;
use app\model\Post;
use app\model\Tag;

/**
 * 面包屑导航辅助类
 * 用于生成SEO友好的面包屑导航数据
 */
class BreadcrumbHelper
{
    /**
     * 生成首页面包屑
     *
     * @return array
     */
    public static function forHome(): array
    {
        return [
            [
                'name' => '首页',
                'url' => '/',
            ],
        ];
    }

    /**
     * 生成文章页面包屑
     *
     * @param Post $post 文章对象
     *
     * @return array
     */
    public static function forPost(Post $post): array
    {
        $breadcrumbs = [
            [
                'name' => '首页',
                'url' => '/',
            ],
        ];

        // 添加主分类（如果存在）
        $primaryCategory = $post->categories()->first();
        if ($primaryCategory) {
            $breadcrumbs[] = [
                'name' => $primaryCategory->name,
                'url' => '/category/' . $primaryCategory->slug . '.html',
            ];
        }

        // 添加当前文章
        $breadcrumbs[] = [
            'name' => $post->title,
            'url' => '/post/' . $post->slug . '.html',
        ];

        return $breadcrumbs;
    }

    /**
     * 生成分类页面包屑
     *
     * @param Category|null $category 分类对象
     * @param bool          $isList   是否为分类列表页
     *
     * @return array
     */
    public static function forCategory(?Category $category = null, bool $isList = false): array
    {
        $breadcrumbs = [
            [
                'name' => '首页',
                'url' => '/',
            ],
        ];

        if ($isList) {
            // 分类列表页
            $breadcrumbs[] = [
                'name' => '全部分类',
                'url' => '/category',
            ];
        } elseif ($category) {
            // 单个分类页
            $breadcrumbs[] = [
                'name' => '全部分类',
                'url' => '/category',
            ];
            $breadcrumbs[] = [
                'name' => $category->name,
                'url' => '/category/' . $category->slug . '.html',
            ];
        }

        return $breadcrumbs;
    }

    /**
     * 生成标签页面包屑
     *
     * @param Tag|null $tag    标签对象
     * @param bool     $isList 是否为标签列表页
     *
     * @return array
     */
    public static function forTag(?Tag $tag = null, bool $isList = false): array
    {
        $breadcrumbs = [
            [
                'name' => '首页',
                'url' => '/',
            ],
        ];

        if ($isList) {
            // 标签列表页
            $breadcrumbs[] = [
                'name' => '全部标签',
                'url' => '/tag',
            ];
        } elseif ($tag) {
            // 单个标签页
            $breadcrumbs[] = [
                'name' => '全部标签',
                'url' => '/tag',
            ];
            $breadcrumbs[] = [
                'name' => $tag->name,
                'url' => '/tag/' . $tag->slug . '.html',
            ];
        }

        return $breadcrumbs;
    }

    /**
     * 生成搜索页面包屑
     *
     * @param string $keyword 搜索关键词
     *
     * @return array
     */
    public static function forSearch(string $keyword = ''): array
    {
        $breadcrumbs = [
            [
                'name' => '首页',
                'url' => '/',
            ],
            [
                'name' => '搜索',
                'url' => '/search',
            ],
        ];

        if (!empty($keyword)) {
            $breadcrumbs[] = [
                'name' => '搜索: ' . $keyword,
                'url' => '/search?q=' . urlencode($keyword),
            ];
        }

        return $breadcrumbs;
    }

    /**
     * 生成用户中心页面包屑
     *
     * @param string $pageName 页面名称
     *
     * @return array
     */
    public static function forUserCenter(string $pageName = '用户中心'): array
    {
        return [
            [
                'name' => '首页',
                'url' => '/',
            ],
            [
                'name' => $pageName,
                'url' => '/user/center',
            ],
        ];
    }

    /**
     * 生成友情链接页面包屑
     *
     * @return array
     */
    public static function forLinks(): array
    {
        return [
            [
                'name' => '首页',
                'url' => '/',
            ],
            [
                'name' => '友情链接',
                'url' => '/link',
            ],
        ];
    }

    /**
     * 生成关于页面包屑
     *
     * @return array
     */
    public static function forAbout(): array
    {
        return [
            [
                'name' => '首页',
                'url' => '/',
            ],
            [
                'name' => '关于',
                'url' => '/about',
            ],
        ];
    }

    /**
     * 生成自定义面包屑
     *
     * @param array $items       面包屑项数组，每项包含 name 和 url
     * @param bool  $includeHome 是否包含首页
     *
     * @return array
     */
    public static function custom(array $items, bool $includeHome = true): array
    {
        $breadcrumbs = [];

        if ($includeHome) {
            $breadcrumbs[] = [
                'name' => '首页',
                'url' => '/',
            ];
        }

        foreach ($items as $item) {
            if (isset($item['name'])) {
                $breadcrumbs[] = [
                    'name' => $item['name'],
                    'url' => $item['url'] ?? '#',
                ];
            }
        }

        return $breadcrumbs;
    }
}
