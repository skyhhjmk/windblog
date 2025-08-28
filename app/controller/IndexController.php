<?php
/*
 * 这里面有很多屎山
 */

namespace app\controller;

use support\Request;
use app\model\Post;
use function Symfony\Component\Translation\t;

class IndexController
{
    /**
     * 不需要登录的方法
     */
    protected array $noNeedLogin = ['index'];

    public function index(Request $request, int $page = 1)
    {
        $count = Post::count('*');

        $posts_per_page = blog_config('posts_per_page', 10, true);

        if ($count <= $posts_per_page) {
            // 文章总数小于每页数量，则不需要分页
            $total_pages = 1;
            $pagination_html = '';
        } elseif ($count % $posts_per_page >= 0) {
            $max_display_pages = 10; // 最大显示页码数
            $total_pages = ceil($count / $posts_per_page);

            // 开始构建新的分页HTML结构
            $pagination_html = '<div class="flex flex-col items-center my-6">';
            $pagination_html .= '<div class="flex space-x-1 mb-4">';

            // 上一页按钮
            $prev_disabled = ($page <= 1) ? 'pointer-events-none opacity-50' : '';
            $pagination_html .= '<a class="flex items-center justify-center w-10 h-10 rounded-md border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 ' . $prev_disabled . '" href="' . (($page > 1) ? route('index.page', ['page' => $page - 1]) : 'javascript:void(0)') . '">';
            $pagination_html .= '<span class="sr-only">上一页</span>';
            $pagination_html .= '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>';
            $pagination_html .= '</a>';

            // 智能页码显示逻辑
            if ($total_pages <= $max_display_pages) {
                // 如果总页数小于等于最大显示页数，显示所有页码
                for ($i = 1; $i <= $total_pages; $i++) {
                    $active = ($i == $page) ? 'bg-blue-500 text-white border-blue-500' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50';
                    $pagination_html .= '<a class="flex items-center justify-center w-10 h-10 rounded-md border ' . $active . '" href="' . route('index.page', ['page' => $i]) . '">' . $i . '</a>';
                }
            } else {
                // 总页数超过最大显示页数，需要智能显示
                $side_pages = 2; // 当前页两侧各显示的页码数

                // 始终显示第一页
                $active = ($page == 1) ? 'bg-blue-500 text-white border-blue-500' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50';
                $pagination_html .= '<a class="flex items-center justify-center w-10 h-10 rounded-md border ' . $active . '" href="' . route('index.page', ['page' => 1]) . '">1</a>';

                // 处理第一页后的省略号
                if ($page > $side_pages + 2) {
                    $pagination_html .= '<span class="flex items-center justify-center w-10 h-10">...</span>';
                }

                // 计算中间显示的页码范围
                $start = max(2, $page - $side_pages);
                $end = min($total_pages - 1, $page + $side_pages);

                // 调整范围，确保不会与第一页或最后一页重叠
                if ($page <= $side_pages + 1) {
                    $end = min($total_pages - 1, $side_pages * 2 + 2);
                }

                if ($page >= $total_pages - $side_pages) {
                    $start = max(2, $total_pages - $side_pages * 2 - 1);
                }

                // 显示中间页码
                for ($i = $start; $i <= $end; $i++) {
                    $active = ($i == $page) ? 'bg-blue-500 text-white border-blue-500' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50';
                    $pagination_html .= '<a class="flex items-center justify-center w-10 h-10 rounded-md border ' . $active . '" href="' . route('index.page', ['page' => $i]) . '">' . $i . '</a>';
                }

                // 处理最后一页前的省略号
                if ($page < $total_pages - $side_pages - 1) {
                    $pagination_html .= '<span class="flex items-center justify-center w-10 h-10">...</span>';
                }

                // 始终显示最后一页
                $active = ($page == $total_pages) ? 'bg-blue-500 text-white border-blue-500' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50';
                $pagination_html .= '<a class="flex items-center justify-center w-10 h-10 rounded-md border ' . $active . '" href="' . route('index.page', ['page' => $total_pages]) . '">' . $total_pages . '</a>';
            }

            // 下一页按钮
            $next_disabled = ($page >= $total_pages) ? 'pointer-events-none opacity-50' : '';
            $pagination_html .= '<a class="flex items-center justify-center w-10 h-10 rounded-md border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 ' . $next_disabled . '" href="' . (($page < $total_pages) ? route('index.page', ['page' => $page + 1]) : 'javascript:void(0)') . '">';
            $pagination_html .= '<span class="sr-only">下一页</span>';
            $pagination_html .= '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>';
            $pagination_html .= '</a>';

            $pagination_html .= '</div>';

            // 手动跳转部分
            $pagination_html .= '<div class="flex items-center space-x-2">';
            $pagination_html .= '<input type="number" id="page-input" min="1" max="' . $total_pages . '" placeholder="页码" class="w-20 px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">';
            $pagination_html .= '<button onclick="jumpToPage(' . $total_pages . ')" class="px-4 py-2 bg-blue-500 text-white rounded-md hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">跳转</button>';
            $pagination_html .= '</div>';

            $pagination_html .= '</div>';
        } else {
            // 貌似计算失败，只显示手动翻页
            // 开始构建新的分页HTML结构
            $pagination_html = '<div class="flex flex-col items-center my-6">';
            $pagination_html .= '<div class="flex space-x-1 mb-4">';
            // 手动跳转部分
            $pagination_html .= '<div class="flex items-center space-x-2">';
            $pagination_html .= '<input type="number" id="page-input" min="1" max="99999" placeholder="页码" class="w-20 px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">';
            $pagination_html .= '<button onclick="jumpToPage(99999)" class="px-4 py-2 bg-blue-500 text-white rounded-md hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">跳转</button>';
            $pagination_html .= '</div>';

            $pagination_html .= '</div>';
        }

        if (empty($request->get())) {
            $cached = cache('blog_posts_page_' . $page);
            if ($cached) {
                return $cached;
            } else {
                $posts = Post::where('status', 'published')->orderByDesc('id')->forPage($page, $posts_per_page)->get();
                cache('blog_posts_page_' . $page . '_per_' . $posts_per_page, $posts, true);
            }
        } else {
            $posts = Post::where('status', 'published')->orderByDesc('id')->forPage($page, $posts_per_page)->get();
        }

        foreach ($posts as $post) {
            if ($post->excerpt === null || $post->excerpt === '') {
                // 自动生成文章摘要并保存
                $post->excerpt = mb_substr(strip_tags($post->content), 0, 200, 'UTF-8');
                $post->save();
            }

        }
        return view('index/index', [
            'page_title' => blog_config('title', 'WindBlog', true) . ' - count is -' . $count,
            'posts' => $posts,
            'pagination' => $pagination_html,
        ]);
    }
}