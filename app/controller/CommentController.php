<?php

namespace app\controller;

use app\model\Comment;
use app\model\Post;
use support\Request;
use support\Response;
use Webman\RateLimiter\Annotation\RateLimiter;

class CommentController
{
    /**
     * 提交评论
     *
     * @param Request $request
     * @param int $postId
     * @return Response
     */
    #[RateLimiter(limit: 3, ttl: 60)]
    public function submit(Request $request, int $postId): Response
    {
        // 检查文章是否存在
        $post = Post::where('id', $postId)->first();
        if (!$post) {
            return json(['code' => 404, 'msg' => '文章不存在']);
        }

        // 获取评论数据
        $content = $request->post('content');
        $parentId = (int) $request->post('parent_id', 0);
        $guestName = $request->post('guest_name');
        $guestEmail = $request->post('guest_email');

        // 基本验证
        if (empty($content)) {
            return json(['code' => 400, 'msg' => '评论内容不能为空']);
        }

        if (strlen($content) > 1000) {
            return json(['code' => 400, 'msg' => '评论内容不能超过1000个字符']);
        }

        // 游客信息验证
        if (empty($guestName) || empty($guestEmail)) {
            return json(['code' => 400, 'msg' => '姓名和邮箱不能为空']);
        }

        if (!filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
            return json(['code' => 400, 'msg' => '邮箱格式不正确']);
        }

        // 检查父评论是否存在
        if ($parentId > 0) {
            $parentComment = Comment::where('id', $parentId)->first();
            if (!$parentComment || $parentComment->post_id != $postId) {
                return json(['code' => 400, 'msg' => '父评论不存在或不属于该文章']);
            }
        }

        // 处理引用内容
        $quotedText = $request->post('quoted_text');
        if (!empty($quotedText)) {
            $content = "引用:{$quotedText}\n{$content}";
        }

        // 创建评论
        $comment = new Comment();
        $comment->post_id = $postId;
        $comment->content = $content;
        $comment->guest_name = $guestName;
        $comment->guest_email = $guestEmail;
        $comment->parent_id = $parentId > 0 ? $parentId : null;
        $comment->ip_address = $request->getRealIp();
        $comment->user_agent = $request->header('user-agent', '');
        $comment->status = 'approved'; // 默认直接批准

        if ($comment->save()) {
            // 返回新创建的评论
            $newComment = Comment::where('id', $comment->id)->with('author')->first();

            return json(['code' => 0, 'msg' => '评论提交成功', 'data' => $newComment]);
        }

        return json(['code' => 500, 'msg' => '评论提交失败']);
    }

    /**
     * 获取文章的评论列表
     *
     * @param Request $request
     * @param int $postId
     * @return Response
     */
    public function getList(Request $request, int $postId): Response
    {
        // 检查文章是否存在
        $post = Post::where('id', $postId)->first();
        if (!$post) {
            return json(['code' => 404, 'msg' => '文章不存在']);
        }

        // 获取评论列表，包含回复
        $comments = Comment::where('post_id', $postId)
            ->where('status', 'approved')
            ->whereNull('parent_id')
            ->with(['replies' => function ($query) {
                $query->where('status', 'approved')->with('author');
            }])
            ->with('author')
            ->orderBy('created_at', 'asc')
            ->get();

        return json(['code' => 0, 'data' => $comments]);
    }
}
