<?php

namespace app\controller;

use app\model\Comment;
use app\model\Post;
use app\model\User;
use Exception;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

class CommentController
{
    /**
     * 允许的HTML标签（用于评论内容）
     */
    private const ALLOWED_TAGS = '<p><br><strong><em><a><ul><ol><li><blockquote><code><pre>';

    /**
     * 提交评论
     *
     * @param Request $request
     * @param int $postId
     *
     * @return Response
     * @throws Throwable
     */
    public function submit(Request $request, int $postId): Response
    {
        // 检查文章是否存在且允许评论
        $post = Post::where('id', $postId)->first();
        if (!$post) {
            return json(['code' => 404, 'msg' => '文章不存在']);
        }

        // 检查文章是否允许评论
        if (!$post->allow_comments) {
            return json(['code' => 403, 'msg' => '该文章已关闭评论']);
        }

        // 检查用户登录状态
        $session = $request->session();
        $userId = $session->get('user_id');

        if (!$userId) {
            return json(['code' => 401, 'msg' => '请先登录再评论']);
        }

        // 检查用户是否已激活
        $user = User::find($userId);
        if (!$user) {
            return json(['code' => 401, 'msg' => '用户不存在，请重新登录']);
        }

        if (!$user->canComment()) {
            return json(['code' => 403, 'msg' => '您的账户未激活或已被禁用，无法评论']);
        }

        // 获取评论数据
        $content = trim($request->post('content', ''));
        $parentId = (int) $request->post('parent_id', 0);
        $guestName = $user->nickname; // 使用用户昵称
        $guestEmail = $user->email; // 使用用户邮箱
        $quotedText = trim($request->post('quoted_text', ''));
        $quotedCommentId = (int) $request->post('quoted_comment_id', 0);

        // 1. 基本验证
        if (empty($content)) {
            return json(['code' => 400, 'msg' => '评论内容不能为空']);
        }

        // 检查最小长度
        if (mb_strlen($content, 'UTF-8') < 2) {
            return json(['code' => 400, 'msg' => '评论内容至少需要2个字符']);
        }

        // 检查最大长度
        if (mb_strlen($content, 'UTF-8') > 1000) {
            return json(['code' => 400, 'msg' => '评论内容不能超过1000个字符']);
        }

        // 2. 检查父评论是否存在
        $parentComment = null;
        if ($parentId > 0) {
            $parentComment = Comment::where('id', $parentId)
                ->where('post_id', $postId)
                ->where('status', 'approved')
                ->first();

            if (!$parentComment) {
                return json(['code' => 400, 'msg' => '父评论不存在或不属于该文章']);
            }
        }

        // 4. 安全处理内容 - 防止XSS攻击
        $sanitizedContent = $this->sanitizeContent($content);

        // 5. 处理引用内容（增强功能）
        $quoteData = null;
        if (!empty($quotedText)) {
            // 安全处理引用文本
            $sanitizedQuotedText = $this->sanitizeQuotedText($quotedText);

            // 限制引用长度
            if (mb_strlen($sanitizedQuotedText, 'UTF-8') > 200) {
                $sanitizedQuotedText = mb_substr($sanitizedQuotedText, 0, 200, 'UTF-8') . '...';
            }

            // 如果引用的是评论（quoted_comment_id > 0），需要验证评论是否存在
            if ($quotedCommentId > 0) {
                $quotedComment = Comment::where('id', $quotedCommentId)
                    ->where('post_id', $postId)
                    ->where('status', 'approved')
                    ->first();

                if ($quotedComment) {
                    // 构建结构化的引用数据（引用评论）
                    $quoteData = [
                        'type' => 'comment',
                        'comment_id' => $quotedComment->id,
                        'author' => htmlspecialchars($quotedComment->guest_name, ENT_QUOTES, 'UTF-8'),
                        'content' => $sanitizedQuotedText,
                        'timestamp' => $quotedComment->created_at->format('Y-m-d H:i:s'),
                    ];
                }
            } else {
                // 引用文章内容（quoted_comment_id = 0 或未提供）
                $quoteData = [
                    'type' => 'post',
                    'content' => $sanitizedQuotedText,
                    'timestamp' => date('Y-m-d H:i:s'),
                ];
            }
        }

        // 6. 额外的安全检查
        // 检查是否包含过多URL（防止垃圾评论）
        $urlCount = preg_match_all('/https?:\/\/[^\s]+/i', $sanitizedContent);
        if ($urlCount > 3) {
            return json(['code' => 400, 'msg' => '评论中包含过多链接']);
        }

        // 检查是否重复评论（简单防刷）
        $recentComment = Comment::where('post_id', $postId)
            ->where('guest_email', $guestEmail)
            ->where('created_at', '>', date('Y-m-d H:i:s', time() - 60))
            ->first();

        if ($recentComment && $recentComment->content === $sanitizedContent) {
            return json(['code' => 400, 'msg' => '请不要重复提交相同的评论']);
        }

        // 6. 创建评论
        try {
            $comment = new Comment();
            $comment->post_id = $postId;
            $comment->user_id = $userId; // 关联用户ID
            $comment->content = $sanitizedContent;
            $comment->guest_name = htmlspecialchars($guestName, ENT_QUOTES, 'UTF-8');
            $comment->guest_email = $guestEmail;
            $comment->parent_id = $parentId > 0 ? $parentId : null;
            $comment->ip_address = $request->getRealIp();
            $comment->user_agent = substr($request->header('user-agent', ''), 0, 255);

            // 存储引用数据（JSON格式）
            if ($quoteData) {
                $comment->quoted_data = json_encode($quoteData, JSON_UNESCAPED_UNICODE);
            }

            // 默认状态（可配置为需要审核）
            $requireModeration = blog_config('comment_moderation', true, true);
            $comment->status = $requireModeration ? 'pending' : 'approved';

            if ($comment->save()) {
                // 返回新创建的评论
                $newComment = Comment::where('id', $comment->id)->with('author')->first();

                // 如果需要审核，返回提示信息
                $message = $requireModeration
                    ? '评论已提交，等待审核'
                    : '评论提交成功';

                return json([
                    'code' => 0,
                    'msg' => $message,
                    'data' => [
                        'comment' => $newComment,
                        'requires_moderation' => $requireModeration,
                    ],
                ]);
            }

            return json(['code' => 500, 'msg' => '评论提交失败']);

        } catch (Exception $e) {
            Log::error('Comment submission failed: ' . $e->getMessage());

            return json(['code' => 500, 'msg' => '评论提交失败，请稍后重试']);
        }
    }

    /**
     * 获取文章的评论列表
     * 注意：即使文章关闭评论，也允许查看已有评论
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

        // 注意：不再检查 allow_comments，允许查看已有评论

        // 获取分页参数
        $page = max(1, (int) $request->get('page', 1));
        $perPage = min(50, max(10, (int) $request->get('per_page', 20)));
        $sortOrder = $request->get('sort', 'asc'); // asc 或 desc
        $sortOrder = in_array($sortOrder, ['asc', 'desc']) ? $sortOrder : 'asc';

        // 获取评论列表，包含回复
        $query = Comment::where('post_id', $postId)
            ->where('status', 'approved')
            ->whereNull('parent_id')
            ->with(['replies' => function ($query) use ($sortOrder) {
                $query->where('status', 'approved')
                    ->with('author')
                    ->orderBy('created_at', $sortOrder);
            }])
            ->with('author')
            ->orderBy('created_at', $sortOrder);

        // 获取总数
        $total = $query->count();

        // 分页获取
        $comments = $query->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        // 处理引用数据
        $comments = $comments->map(function ($comment) {
            return $this->formatComment($comment);
        });

        return json([
            'code' => 0,
            'data' => [
                'comments' => $comments,
                'pagination' => [
                    'total' => $total,
                    'per_page' => $perPage,
                    'current_page' => $page,
                    'last_page' => ceil($total / $perPage),
                ],
            ],
        ]);
    }

    /**
     * 净化评论内容（防止XSS）
     *
     * @param string $content
     * @return string
     */
    private function sanitizeContent(string $content): string
    {
        // 1. 移除潜在危险的标签，只保留安全的HTML标签
        $content = strip_tags($content, self::ALLOWED_TAGS);

        // 2. 转义特殊字符
        // 注意：如果允许HTML标签，需要更精细的处理
        // 这里我们采用更安全的策略：完全转义HTML
        $content = htmlspecialchars($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 3. 移除潜在的脚本和事件处理器
        $content = preg_replace('/on\w+\s*=\s*["\'][^"\']*["\']|on\w+\s*=\s*\S+/i', '', $content);

        // 4. 移除javascript:伪协议
        $content = preg_replace('/javascript:/i', '', $content);

        // 5. 过滤null字节
        $content = str_replace(chr(0), '', $content);

        // 6. 标准化换行符
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        // 7. 限制连续换行
        $content = preg_replace("/\n{3,}/", "\n\n", $content);

        return trim($content);
    }

    /**
     * 净化引用文本（更严格的处理）
     *
     * @param string $text
     * @return string
     */
    private function sanitizeQuotedText(string $text): string
    {
        // 引用文本完全移除HTML标签
        $text = strip_tags($text);

        // 转义所有特殊字符
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 移除多余的空白
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /**
     * 格式化评论数据（包含引用信息）
     *
     * @param Comment $comment
     * @return array
     */
    private function formatComment(Comment $comment): array
    {
        $data = $comment->toArray();

        // 解析引用数据
        if (!empty($comment->quoted_data)) {
            try {
                $data['quote'] = json_decode($comment->quoted_data, true);
            } catch (Exception $e) {
                $data['quote'] = null;
            }
        } else {
            $data['quote'] = null;
        }

        // 递归处理回复
        if (!empty($data['replies'])) {
            $data['replies'] = array_map(function ($reply) {
                return $this->formatComment(Comment::find($reply['id']));
            }, $data['replies']);
        }

        return $data;
    }
}
