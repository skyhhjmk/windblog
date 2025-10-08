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
        
        // PJAX 优化：检测是否为 PJAX 请求（兼容 header/_pjax 参数/XHR）
        $isPjax = ($request->header('X-PJAX') !== null)
            || (bool)$request->get('_pjax')
            || strtolower((string)$request->header('X-Requested-With')) === 'xmlhttprequest';

        // 获取侧边栏内容（PJAX 与非 PJAX 均获取）
        $sidebar = \app\service\SidebarService::getSidebarContent($request, 'post');
        
        // 加载作者信息与分类、标签
        $post->load(['authors', 'primaryAuthor', 'categories', 'tags']);
        $primaryAuthor = $post->primaryAuthor->first();
        $authorName = $primaryAuthor ? $primaryAuthor->nickname : ($post->authors->first() ? $post->authors->first()->nickname : '未知作者');
        
        // 动态选择模板：PJAX 返回片段，非 PJAX 返回完整页面
        $viewName = $isPjax ? 'index/post.content' : 'index/post';

        // 非 PJAX 请求启用页面级缓存（TTL=120），键：list:post:index:{hash}:1:{locale}
        $locale = $request->header('Accept-Language') ?? 'zh-CN';
        $route = 'post:index';
        $params = [
            'keyword' => $keyword,
            'mode' => blog_config('url_mode', 'mix', true),
        ];
        $paramsHash = substr(sha1(json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 0, 16);
        $cacheKey = sprintf('list:%s:%s:%d:%s', $route, $paramsHash, 1, $locale);

        if (!$isPjax) {
            $conn = \support\Redis::connection('cache');
            $cached = $conn->get($cacheKey);
            if ($cached !== null) {
                return new Response(200, ['X-Cache' => 'HIT'], $cached);
            }
        }

        $resp = view($viewName, [
            'page_title' => $post['title'] . ' - ' . blog_config('title', 'WindBlog', true),
            'post' => $post,
            'author' => $authorName,
            'sidebar' => $sidebar
        ]);

        if (!$isPjax) {
            \support\Redis::connection('cache')->setex($cacheKey, 120, $resp->rawBody());
        }

        // 动作：文章内容渲染完成（需权限 content:action.post_rendered）
        \app\service\PluginService::do_action('content.post_rendered', [
            'slug' => is_string($keyword) ? $keyword : null,
            'id' => is_numeric($keyword) ? (int)$keyword : null
        ]);

        // 过滤器：文章响应（需权限 content:filter.post_response）
        $resp = \app\service\PluginService::apply_filters('content.post_response_filter', $resp);

        return $resp;
    }
}