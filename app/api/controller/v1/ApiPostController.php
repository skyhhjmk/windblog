<?php

namespace app\api\controller\v1;

use app\model\Post;
use app\service\FloLinkService;
use Exception;
use support\Log;
use support\Request;
use support\Response;
use Throwable;
use Webman\RateLimiter\Annotation\RateLimiter;

class ApiPostController
{
    /**
     * 不需要登录的方法
     */
    protected array $noNeedLogin = [];

    public function index(Request $request): Response
    {
        return json([
            'code' => 200,
            'message' => trans('Success'),
        ]);
    }

    public function get(Request $request, ?int $id = null): Response
    {
        // 验证ID
        if (empty($id) || !is_numeric($id) || $id < 1 || $id > 2147483646/* int最大值-1 */) {
            return json([
                'code' => 400,
                'message' => trans('Missing input parameter :parameter', ['parameter' => 'id']), // 缺少输入参数 id
            ]);
        }

        // 查询文章及作者信息
        $post = Post::with(['authors' => function ($query) {
            $query->select('wa_users.id', 'username', 'nickname', 'email', 'avatar');
        }])->find($id);

        if (!$post) {
            return json([
                'code' => 404,
                'message' => trans('Post not found'),
            ]);
        }

        // 返回文章详情
        return json([
            'code' => 200,
            'message' => trans('Success'),
            'data' => [
                'id' => $post->id,
                'title' => $post->title,
                'slug' => $post->slug,
                'content' => $post->content,
                'content_type' => $post->content_type ?? 'markdown',
                'summary' => $post->summary,
                'status' => $post->status,
                'visibility' => $post->visibility ?? 'public',
                'password' => $post->password,
                'featured' => (bool) $post->featured,
                'allow_comments' => (bool) $post->allow_comments,
                'comment_count' => $post->comment_count ?? 0,
                'published_at' => $post->published_at,
                'created_at' => $post->created_at,
                'updated_at' => $post->updated_at,
                'authors' => $post->authors ? $post->authors->toArray() : [],
            ],
        ]);
    }

    /**
     * 获取文章内容（用于前端异步加载）
     *
     * @param Request $request
     * @param mixed   $keyword
     *
     * @return Response
     * @throws Throwable
     * @deprecated 不再使用，内容已直接嵌入到模板中以优化 SEO
     */
    #[RateLimiter(limit: 10, ttl: 3)]
    public function content(Request $request, mixed $keyword = null): Response
    {
        // 移除URL参数中的 .html 后缀
        if (is_string($keyword) && str_ends_with($keyword, '.html')) {
            $keyword = substr($keyword, 0, -5);
        }

        // 根据 URL 模式查找文章
        switch (blog_config('url_mode', 'mix', true)) {
            case 'slug':
                $post = Post::where('slug', $keyword)->first();
                break;
            case 'id':
                $post = Post::where('id', $keyword)->first();
                break;
            case 'mix':
            default:
                if (is_numeric($keyword)) {
                    $post = Post::where('id', $keyword)->first();
                    if ($post === null) {
                        $post = Post::where('slug', $keyword)->first();
                    }
                } else {
                    $post = Post::where('slug', $keyword)->first();
                }
                break;
        }

        if (!$post || $post['status'] !== 'published') {
            return json([
                'code' => 404,
                'message' => trans('Post not found'),
            ]);
        }

        // 使用FloLink处理文章内容
        $content = $post->content;
        if (blog_config('flolink_enabled', true)) {
            try {
                $content = FloLinkService::processContent($content);
            } catch (Exception $e) {
                Log::error('FloLink处理失败: ' . $e->getMessage());
            }
        }

        // 返回内容（客户端缓存 5 分钟）
        return json([
            'code' => 200,
            'message' => trans('Success'),
            'data' => [
                'id' => $post->id,
                'title' => $post->title,
                'content' => $content,
                'content_type' => $post->content_type ?? 'markdown',
            ],
        ])->withHeaders([
            'Cache-Control' => 'public, max-age=300',
            'Vary' => 'Accept-Encoding',
        ]);
    }
}
