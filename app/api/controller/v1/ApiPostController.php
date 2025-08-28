<?php

namespace app\api\controller\v1;

use app\model\Post;
use support\Request;
use support\Response;
use Webman\RateLimiter\Annotation\RateLimiter;

class ApiPostController
{

    /**
     * 不需要登录的方法
     */
    protected array $noNeedLogin = ['index', 'get'];

    public function index(Request $request): Response
    {
        return json([
            'code' => 200,
            'message' => trans('Success'),
        ]);
    }

    #[RateLimiter(limit: 3,ttl: 3)]
    public function get(Request $request, ?int $id = null): Response
    {
        // 验证ID
        if (empty($id) || !is_numeric($id) || $id < 1 || $id > 2147483646/* int最大值-1 */) {
            return json([
                'code' => 400,
                'message' => trans('Missing input parameter :parameter', ['parameter' => 'id']) // 缺少输入参数 id
            ]);
        }
        $post = Post::where('id', $id)->where('status', 'published');
        var_dump($post);
        return json([
            'code' => 200,
            'message' => trans('Success'),
            'id' => $id,
            'title' => $post->title,
            'slug' => $post->slug,
            'content_type' => $post->content_type,
            'data' => $post->content
        ]);
    }
}