<?php

namespace app\controller;

use app\annotation\CSRFVerify;
use app\model\Comment;
use app\model\Post;
use app\model\User;
use app\service\CaptchaService;
use Exception;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

class CommentController
{
    /**
     * 不需要登录的方法
     * submit: 提交评论，支持游客评论
     * getList: 获取文章评论列表，公开访问
     * status: 查询评论状态，公开访问
     */
    protected array $noNeedLogin = ['submit', 'getList', 'status'];
    /**
     * 允许的HTML标签及属性（用于评论内容）
     */
    private const ALLOWED_TAGS = [
        'p' => [],
        'br' => [],
        'strong' => [],
        'b' => [],
        'em' => [],
        'i' => [],
        'ul' => [],
        'ol' => [],
        'li' => [],
        'blockquote' => [],
        'code' => [],
        'pre' => [],
        'a' => ['href', 'title'],  // 链接只允许 href 和 title 属性
    ];

    /**
     * 允许的URL协议白名单
     */
    private const ALLOWED_PROTOCOLS = ['http', 'https', 'mailto'];

    /**
     * 提交评论
     *
     * @param Request $request
     * @param int $postId
     *
     * @return Response
     * @throws Throwable
     */
    #[CSRFVerify(tokenName: '_token', methods: ['POST'])]
    public function submit(Request $request, int $postId): Response
    {
        // 验证验证码
        [$ok, $msg] = CaptchaService::verify($request);
        if (!$ok) {
            return json(['code' => 400, 'msg' => $msg]);
        }
        // 检查文章是否存在且允许评论
        $post = Post::where('id', $postId)->first();
        if (!$post) {
            return json(['code' => 404, 'msg' => '文章不存在']);
        }

        // 检查文章是否允许评论
        if (!$post->allow_comments) {
            return json(['code' => 403, 'msg' => '该文章已关闭评论']);
        }

        // 支持游客评论：存在登录则按登录用户，否则按访客字段
        $session = $request->session();
        $userId = $session->get('user_id');
        $user = null;
        if ($userId) {
            // 从数据库实时获取最新的用户信息，确保联动性
            $user = User::find($userId);
            if (!$user || !$user->canComment()) {
                return json(['code' => 403, 'msg' => '账户不可用，无法评论']);
            }
        }

        // 获取评论数据
        $content = trim($request->post('content', ''));
        $parentId = (int) $request->post('parent_id', 0);
        // 登录用户：强制使用用户中心的最新信息，实现实时联动
        // 游客：使用表单提交的信息
        $guestName = $user ? $user->nickname : trim((string) $request->post('guest_name', ''));
        $guestEmail = $user ? $user->email : trim((string) $request->post('guest_email', ''));
        $quotedText = trim($request->post('quoted_text', ''));
        $quotedCommentId = (int) $request->post('quoted_comment_id', 0);

        // 基本验证
        if (empty($content)) {
            return json(['code' => 400, 'msg' => '评论内容不能为空']);
        }

        // 检查最小长度
        $minLength = (int) blog_config('comment_min_length', 2, true);
        if (mb_strlen($content, 'UTF-8') < $minLength) {
            return json(['code' => 400, 'msg' => "评论内容至少需要{$minLength}个字符"]);
        }

        // 游客必填校验
        if (!$user) {
            if ($guestName === '') {
                return json(['code' => 400, 'msg' => '请填写您的昵称']);
            }
            if ($guestEmail === '' || !filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
                return json(['code' => 400, 'msg' => '请填写有效的邮箱']);
            }
        }

        // 检查最大长度
        $maxLength = (int) blog_config('comment_max_length', 1000, true);
        if (mb_strlen($content, 'UTF-8') > $maxLength) {
            return json(['code' => 400, 'msg' => "评论内容不能超过{$maxLength}个字符"]);
        }

        // 检查父评论是否存在
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

        // 安全处理内容 - 防止XSS攻击
        $sanitizedContent = $this->sanitizeContent($content);

        // 处理引用内容（增强功能）
        $quoteData = null;
        if (!empty($quotedText)) {
            // 安全处理引用文本
            $sanitizedQuotedText = $this->sanitizeQuotedText($quotedText);

            // 限制引用长度
            $maxQuoteLength = (int) blog_config('comment_max_quote_length', 200, true);
            if (mb_strlen($sanitizedQuotedText, 'UTF-8') > $maxQuoteLength) {
                $sanitizedQuotedText = mb_substr($sanitizedQuotedText, 0, $maxQuoteLength, 'UTF-8') . '...';
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
                    'timestamp' => utc_now_string('Y-m-d H:i:s'),
                ];
            }
        }

        // 额外的安全检查
        // 检查是否包含过多URL（防止垃圾评论）
        $maxUrls = (int) blog_config('comment_max_urls', 3, true);
        $urlCount = preg_match_all('/https?:\/\/[^\s]+/i', $sanitizedContent);
        if ($urlCount > $maxUrls) {
            return json(['code' => 400, 'msg' => '评论中包含过多链接']);
        }

        // 检查是否重复评论（增强防刷）
        // 检查相同内容的重复评论
        $duplicateWindow = (int) blog_config('comment_duplicate_window', 300, true);
        $recentComment = Comment::where('post_id', $postId)
            ->where('guest_email', $guestEmail)
            ->where('created_at', '>', utc_now()->subSeconds($duplicateWindow))
            ->first();

        if ($recentComment && $recentComment->content === $sanitizedContent) {
            return json(['code' => 400, 'msg' => '请不要重复提交相同的评论']);
        }

        // 检查评论频率
        $frequencyWindow = (int) blog_config('comment_frequency_window', 60, true);
        $maxComments = (int) blog_config('comment_max_frequency', 3, true);
        $recentCommentCount = Comment::where('post_id', $postId)
            ->where('guest_email', $guestEmail)
            ->where('created_at', '>', utc_now()->subSeconds($frequencyWindow))
            ->count();

        if ($recentCommentCount >= $maxComments) {
            return json(['code' => 429, 'msg' => '评论过于频繁,请稍后再试']);
        }

        // AI自动审核（改为非阻塞：入队由独立进程处理）
        $aiModerationEnabled = blog_config('comment_ai_moderation_enabled', false, true);
        $aiModerationResult = null;

        // 创建评论
        try {
            $comment = new Comment();
            $comment->post_id = $postId;
            $comment->user_id = $userId ?: null; // 关联用户ID（游客为null）
            $comment->content = $sanitizedContent;
            $comment->guest_name = htmlspecialchars($guestName, ENT_QUOTES, 'UTF-8');
            $comment->guest_email = $guestEmail;
            $comment->parent_id = $parentId > 0 ? $parentId : null;
            $comment->ip_address = $request->getRealIp();
            $comment->user_agent = substr($request->header('user-agent', ''), 0, 255);

            // 存储引用数据（JSON格式）
            if ($quoteData) {
                $comment->quoted_data = json_encode($quoteData, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }

            // 非阻塞：先设置状态，AI结果由Worker回写
            $requireModeration = blog_config('comment_moderation', true, true);
            $comment->status = $requireModeration ? 'pending' : 'approved';

            if ($comment->save()) {
                // 预加载关联关系，避免重新查询
                $comment->load('author');
                $newComment = $comment;

                // 入队AI审核（若开启）
                if ($aiModerationEnabled) {
                    try {
                        $aiPriority = (int) blog_config('comment_ai_moderation_priority', 5, true);
                        \app\service\AIModerationService::enqueue(['comment_id' => $comment->id, 'priority' => $aiPriority]);
                    } catch (\Throwable $e) {
                        Log::warning('Enqueue moderation failed: ' . $e->getMessage());
                    }
                }

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
     * 单条评论状态
     * GET /comment/status/{id}
     */
    public function status(Request $request, int $id): Response
    {
        $comment = Comment::withTrashed()->find($id);
        if (!$comment) {
            return json(['code' => 404, 'msg' => '评论不存在']);
        }

        return json([
            'code' => 0,
            'data' => [
                'id' => $comment->id,
                'status' => $comment->status,
                'ai_moderation_result' => $comment->ai_moderation_result,
                'ai_moderation_reason' => $comment->ai_moderation_reason,
                'ai_moderation_confidence' => $comment->ai_moderation_confidence,
                'created_at' => $comment->created_at,
            ],
        ]);
    }

    /**
     * 获取文章的评论列表
     * 注意：即使文章关闭评论，也允许查看已有评论
     *
     * @param Request $request
     * @param int $postId
     *
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

        // 排序参数白名单验证（防止SQL注入）
        $sortOrder = $request->get('sort', 'asc');
        $sortOrder = in_array(strtolower($sortOrder), ['asc', 'desc']) ? strtolower($sortOrder) : 'asc';

        // 获取评论列表，包含回复，预加载user关联避免N+1查询
        $query = Comment::where('post_id', $postId)
            ->where('status', 'approved')
            ->whereNull('parent_id')
            ->with(['replies' => function ($query) use ($sortOrder) {
                $query->where('status', 'approved')
                    ->with('user')  // 预加载user关联
                    ->orderBy('created_at', $sortOrder);
            }])
            ->with('user')  // 预加载user关联
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
     * 使用 DOMDocument 精确控制允许的标签和属性
     *
     * @param string $content
     *
     * @return string
     */
    private function sanitizeContent(string $content): string
    {
        // 预处理：过滤null字节和标准化换行
        $content = str_replace(chr(0), '', $content);
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $content = preg_replace("/\n{3,}/", "\n\n", $content);
        $content = trim($content);

        if (empty($content)) {
            return '';
        }

        // 使用 DOMDocument 进行 HTML 过滤
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');

        // 加载 HTML，使用 UTF-8 编码
        $dom->loadHTML(
            '<?xml encoding="UTF-8">' . '<div>' . $content . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        // 递归清理节点
        $this->sanitizeNode($dom->documentElement);

        // 获取清理后的 HTML
        $sanitized = '';
        $body = $dom->getElementsByTagName('div')->item(0);
        if ($body) {
            foreach ($body->childNodes as $child) {
                $sanitized .= $dom->saveHTML($child);
            }
        }

        // 最后的安全检查：移除残留的危险内容
        $sanitized = $this->removeRemainingThreats($sanitized);

        return trim($sanitized);
    }

    /**
     * 递归清理 DOM 节点
     *
     * @param \DOMNode $node
     *
     * @return void
     */
    private function sanitizeNode(\DOMNode $node): void
    {
        // 从后往前遍历子节点，以便安全删除
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $tagName = strtolower($child->nodeName);

                // 检查标签是否在白名单中
                if (!isset(self::ALLOWED_TAGS[$tagName])) {
                    // 不允许的标签：保留文本内容，移除标签
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                } else {
                    // 允许的标签：清理属性
                    $this->sanitizeAttributes($child, self::ALLOWED_TAGS[$tagName]);
                    // 递归处理子节点
                    $this->sanitizeNode($child);
                }
            } elseif ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                // 文本节点保留
                continue;
            } else {
                // 其他类型节点（如注释）：删除
                $node->removeChild($child);
            }
        }
    }

    /**
     * 清理节点属性
     *
     * @param \DOMElement $element
     * @param array       $allowedAttrs
     *
     * @return void
     */
    private function sanitizeAttributes(\DOMElement $element, array $allowedAttrs): void
    {
        $attributesToRemove = [];

        // 遍历所有属性
        foreach ($element->attributes as $attr) {
            $attrName = strtolower($attr->name);
            $attrValue = $attr->value;

            // 移除事件处理器属性（on*）
            if (str_starts_with($attrName, 'on')) {
                $attributesToRemove[] = $attr->name;
                continue;
            }

            // 移除危险属性
            if (in_array($attrName, ['style', 'class', 'id', 'data-*'], true)) {
                $attributesToRemove[] = $attr->name;
                continue;
            }

            // 检查是否在允许列表中
            if (!in_array($attrName, $allowedAttrs, true)) {
                $attributesToRemove[] = $attr->name;
                continue;
            }

            // 特殊处理 href 属性：验证 URL 协议
            if ($attrName === 'href') {
                if (!$this->isUrlSafe($attrValue)) {
                    $attributesToRemove[] = $attr->name;
                }
            }
        }

        // 删除不允许的属性
        foreach ($attributesToRemove as $attrName) {
            $element->removeAttribute($attrName);
        }
    }

    /**
     * 检查 URL 是否安全
     *
     * @param string $url
     *
     * @return bool
     */
    private function isUrlSafe(string $url): bool
    {
        $url = trim($url);

        if (empty($url)) {
            return false;
        }

        // 移除空白字符和控制字符
        if (preg_match('/[\x00-\x1f\x7f\s]/', $url)) {
            return false;
        }

        // 检查危险协议
        $dangerousProtocols = [
            'javascript:', 'data:', 'vbscript:', 'file:', 'about:',
            'javascript&colon;', 'data&colon;',  // HTML 实体编码绕过
        ];

        $urlLower = strtolower($url);
        foreach ($dangerousProtocols as $protocol) {
            if (str_starts_with($urlLower, $protocol)) {
                return false;
            }
        }

        // 如果是相对 URL 或锚点，允许
        if (str_starts_with($url, '/') || str_starts_with($url, '#')) {
            return true;
        }

        // 解析 URL 并验证协议
        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['scheme'])) {
            // 没有协议的相对 URL，允许
            return true;
        }

        $scheme = strtolower($parsed['scheme']);

        return in_array($scheme, self::ALLOWED_PROTOCOLS, true);
    }

    /**
     * 移除残留的威胁（最后一道防线）
     *
     * @param string $content
     *
     * @return string
     */
    private function removeRemainingThreats(string $content): string
    {
        // 移除可能的 JavaScript 伪协议（各种编码形式）
        $patterns = [
            '/javascript\s*:/i',
            '/&#(0*106|0*74|x0*6a|x0*4a);/i',  // j
            '/vbscript\s*:/i',
            '/data\s*:[^,]*script/i',
            '/on\w+\s*=/i',  // 事件处理器
        ];

        foreach ($patterns as $pattern) {
            $content = preg_replace($pattern, '', $content);
        }

        return $content;
    }

    /**
     * 净化引用文本（更严格的处理）
     *
     * @param string $text
     *
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
     * 如果是已登录用户的评论，动态获取用户中心的最新信息
     *
     * @param Comment $comment
     *
     * @return array
     */
    private function formatComment(Comment $comment): array
    {
        $data = $comment->toArray();

        // 如果评论关联了用户ID，使用用户中心的最新信息（实现实时联动）
        if (!empty($comment->user_id)) {
            // 优先使用预加载的user关联，避免N+1查询
            if ($comment->relationLoaded('user') && $comment->user) {
                $user = $comment->user;
            } else {
                // 如果没有预加载，才动态查询
                $user = User::find($comment->user_id);
            }

            if ($user) {
                $data['guest_name'] = $user->nickname;
                $data['guest_email'] = $user->email;
                $data['author'] = [
                    'id' => $user->id,
                    'username' => $user->username,
                    'nickname' => $user->nickname,
                    'email' => $user->email,
                    'avatar_url' => $user->getAvatarUrl(80, 'identicon'),
                ];
            } else {
                // 用户不存在，使用存储的信息
                $data['author'] = null;
            }
        } else {
            // 游客评论，使用存储的guest_name和guest_email
            $data['author'] = null;
        }

        // 解析引用数据（数据在存储时已经过安全清理）
        if (!empty($comment->quoted_data)) {
            try {
                $data['quote'] = json_decode($comment->quoted_data, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                Log::warning('Invalid quoted_data JSON for comment ' . $comment->id . ': ' . $e->getMessage());
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
