<?php

namespace plugin\admin\app\controller;

use app\model\Author;
use app\model\Media;
use app\model\Post;
use app\service\CacheService;
use app\service\MediaLibraryService;
use app\service\SlugTranslateService;
use Exception;
use support\Db;
use support\Log;
use support\Request;
use support\Response;

/**
 * 编辑器控制器
 * 负责处理文章编辑相关功能
 */
class EditorController
{
    /**
     * 编辑器页面
     *
     * @param Request $request
     * @param int $id 可选的文章ID，用于编辑已有文章
     *
     * @return Response
     */
    public function vditor(Request $request, int $id = 0): Response
    {
        // 如果提供了ID，则查询文章信息
        $post = null;
        if ($id > 0) {
            $post = Post::find($id);
        }

        // 传递必要的数据到视图
        return view('editor/vditor', [
            'post' => $post,
            'id' => $id,
        ]);
    }

    /**
     * 保存文章
     *
     * @param Request $request
     *
     * @return Response
     * @throws Exception
     */
    public function save(Request $request): Response
    {
        // 获取请求数据
        $post_id = $request->post('post_id', 0);
        $title = $request->post('title', '');
        $content = $request->post('content', '');
        $status = $request->post('status', 'draft');
        $visibility = $request->post('visibility', 'public');
        $password = $request->post('password', '');
        $allow_comments = $request->post('allow_comments', 1);
        $featured = $request->post('featured', 0);
        $authors = $request->post('authors', []);
        $categories = $request->post('categories', []);
        $tags = $request->post('tags', []);
        // SEO 字段
        $seo_title = $request->post('seo_title', '');
        $seo_description = $request->post('seo_description', '');
        $seo_keywords = $request->post('seo_keywords', '');
        // AI 摘要相关字段现在通过单独的保存摘要接口处理

        // 验证输入
        if (empty($title)) {
            return json(['code' => 1, 'msg' => '请输入文章标题']);
        }

        if (empty($content)) {
            return json(['code' => 1, 'msg' => '请输入文章内容']);
        }

        // 获取当前管理员ID（从session中的admin数组获取）
        $adminInfo = $request->session()->get('admin', []);
        $adminId = $adminInfo['id'] ?? 0;
        if ($adminId <= 0) {
            return json(['code' => 1, 'msg' => '管理员未登录或权限不足']);
        }

        // 准备数据
        $data = [
            'title' => $title,
            'content' => $content,
            'status' => $status,
            'visibility' => $visibility,
            'allow_comments' => $allow_comments ? 1 : 0,
            'featured' => $featured ? 1 : 0,
            'seo_title' => $seo_title ?: null,
            'seo_description' => $seo_description ?: null,
            'seo_keywords' => $seo_keywords ?: null,
            'updated_at' => utc_now_string('Y-m-d H:i:s'),
        ];
        // AI摘要相关字段通过单独的保存接口处理，不在此更新

        // 调试日志
        Log::info('EditorController::save - 接收到的数据', [
            'allow_comments_raw' => $allow_comments,
            'allow_comments_processed' => $data['allow_comments'],
            'featured_raw' => $featured,
            'featured_processed' => $data['featured'],
            'visibility' => $visibility,
            'post_id' => $post_id,
        ]);

        // 如果可见性是密码保护，处理密码字段
        if ($visibility === 'password') {
            if (!empty($password)) {
                // 只有修改或添加密码时才处理
                $data['password'] = password_hash($password, PASSWORD_DEFAULT);
            }
            // 为空代表不修改密码，则不操作密码字段
        } else {
            $data['password'] = null; // 清空密码
        }

        try {
            if ($post_id > 0) {
                // 更新已有文章
                $post = Post::find($post_id);
                if (!$post) {
                    return json(['code' => 1, 'msg' => '文章不存在']);
                }
                $originalContent = (string) $post->content;
                $post->update($data);
                $contentChanged = $originalContent !== $content;

                // 删除现有的作者关联
                Db::table('post_author')->where('post_id', $post_id)->delete();
            } else {
                // 创建新文章，并设置 author_id
                $data['created_at'] = utc_now_string('Y-m-d H:i:s');
                $data['author_id'] = $adminId;
                $post = Post::create($data);
                $post_id = $post->id;
                $contentChanged = true;
            }

            // AI摘要相关逻辑已移至单独的保存摘要接口，不再自动处理

            // 处理多作者
            if (!empty($authors) && is_array($authors)) {
                $authorRecords = [];
                $hasPrimary = false;

                foreach ($authors as $index => $authorId) {
                    if (!is_numeric($authorId)) {
                        continue;
                    }

                    $authorId = (int) $authorId;
                    $isPrimary = ($index === 0 && !$hasPrimary); // 第一个作者设为主要作者

                    // 检查作者是否存在于wa_users表中
                    $authorExists = Db::table('wa_users')->where('id', $authorId)->exists();

                    if ($authorExists) {
                        $authorRecords[] = [
                            'post_id' => $post_id,
                            'author_id' => $authorId,
                            'is_primary' => $isPrimary ? 1 : 0,
                            'created_at' => utc_now_string('Y-m-d H:i:s'),
                            'updated_at' => utc_now_string('Y-m-d H:i:s'),
                        ];

                        if ($isPrimary) {
                            $hasPrimary = true;
                        }
                    }
                }

                // 如果没有设置主要作者，将第一个有效作者设为主要作者
                if (!$hasPrimary && !empty($authorRecords)) {
                    $authorRecords[0]['is_primary'] = 1;
                }

                if (!empty($authorRecords)) {
                    Db::table('post_author')->insert($authorRecords);
                }
            } else {
                // 如果没有选择作者，检查当前管理员ID是否存在于wa_users表中
                $adminUserExists = Db::table('wa_users')->where('id', $adminId)->exists();

                if ($adminUserExists) {
                    // 如果管理员在wa_users表中存在，将其作为作者
                    Db::table('post_author')->insert([
                        'post_id' => $post_id,
                        'author_id' => $adminId,
                        'is_primary' => 1,
                        'created_at' => utc_now_string('Y-m-d H:i:s'),
                        'updated_at' => utc_now_string('Y-m-d H:i:s'),
                    ]);
                } else {
                    // 如果管理员在wa_users表中不存在，查找或创建一个默认用户
                    $defaultAuthorId = $this->getOrCreateDefaultAuthor($adminId);

                    if ($defaultAuthorId) {
                        Db::table('post_author')->insert([
                            'post_id' => $post_id,
                            'author_id' => $defaultAuthorId,
                            'is_primary' => 1,
                            'created_at' => date('Y-m-d H:i:s'),
                            'updated_at' => date('Y-m-d H:i:s'),
                        ]);
                    }
                }
            }

            // 处理分类关联
            Log::info('EditorController::save - 分类数据', ['categories' => $categories]);

            // 过滤null值
            if (is_array($categories)) {
                $categories = array_filter($categories, function ($cat) {
                    return $cat !== null && $cat !== '';
                });
            }

            if (!empty($categories) && is_array($categories)) {
                // 删除现有的分类关联
                Db::table('post_category')->where('post_id', $post_id)->delete();

                // 添加新的分类关联
                $categoryRecords = [];
                foreach ($categories as $category) {
                    // 支持直接传递ID或者对象
                    if (is_numeric($category)) {
                        $categoryRecords[] = [
                            'post_id' => $post_id,
                            'category_id' => (int) $category,
                        ];
                    } elseif (is_array($category)) {
                        // 如果是对象，可能包含id和name
                        if (!empty($category['id'])) {
                            // 现有分类
                            $categoryRecords[] = [
                                'post_id' => $post_id,
                                'category_id' => (int) $category['id'],
                            ];
                        } elseif (!empty($category['name'])) {
                            // 新分类，先查找是否已存在
                            $existingCategory = Db::table('categories')
                                ->where('name', $category['name'])
                                ->first();

                            if ($existingCategory) {
                                // 已存在，直接使用
                                $categoryRecords[] = [
                                    'post_id' => $post_id,
                                    'category_id' => $existingCategory->id,
                                ];
                            } else {
                                // 不存在，创建新分类
                                // 使用SlugTranslateService生成slug
                                if (!empty($category['slug'])) {
                                    $slug = $category['slug'];
                                } else {
                                    $slugService = new SlugTranslateService();
                                    $slug = $slugService->translate($category['name']) ?? $this->generateSlug($category['name']);
                                }

                                $newCategoryId = Db::table('categories')->insertGetId([
                                    'name' => $category['name'],
                                    'slug' => $slug,
                                    'description' => $category['description'] ?? '',
                                    'sort_order' => $category['sort_order'] ?? 0,
                                    'created_at' => utc_now_string('Y-m-d H:i:s'),
                                    'updated_at' => utc_now_string('Y-m-d H:i:s'),
                                ]);

                                $categoryRecords[] = [
                                    'post_id' => $post_id,
                                    'category_id' => $newCategoryId,
                                ];

                                Log::info('创建新分类: ' . $category['name'] . ' (ID: ' . $newCategoryId . ')');
                            }
                        }
                    }
                }

                if (!empty($categoryRecords)) {
                    Db::table('post_category')->insert($categoryRecords);
                }
            }

            // 处理标签关联
            // 过滤null值
            if (is_array($tags)) {
                $tags = array_filter($tags, function ($tag) {
                    return $tag !== null && $tag !== '';
                });
            }

            if (!empty($tags) && is_array($tags)) {
                // 删除现有的标签关联
                Db::table('post_tag')->where('post_id', $post_id)->delete();

                // 处理标签（可能是现有标签或新标签）
                $tagRecords = [];
                foreach ($tags as $tag) {
                    if (is_array($tag)) {
                        $tagId = $tag['id'] ?? null;
                        $tagName = $tag['name'] ?? '';

                        if ($tagId) {
                            // 使用现有标签
                            $tagRecords[] = [
                                'post_id' => $post_id,
                                'tag_id' => (int) $tagId,
                            ];
                        } elseif ($tagName) {
                            // 创建新标签
                            $existingTag = Db::table('tags')->where('name', $tagName)->first();
                            if ($existingTag) {
                                $tagRecords[] = [
                                    'post_id' => $post_id,
                                    'tag_id' => $existingTag->id,
                                ];
                            } else {
                                // 创建新标签，使用SlugTranslateService生成slug
                                $slugService = new SlugTranslateService();
                                $slug = $slugService->translate($tagName) ?? $this->generateSlug($tagName);

                                $newTagId = Db::table('tags')->insertGetId([
                                    'name' => $tagName,
                                    'slug' => $slug,
                                    'created_at' => utc_now_string('Y-m-d H:i:s'),
                                    'updated_at' => utc_now_string('Y-m-d H:i:s'),
                                ]);
                                $tagRecords[] = [
                                    'post_id' => $post_id,
                                    'tag_id' => $newTagId,
                                ];

                                Log::info('创建新标签: ' . $tagName . ' (ID: ' . $newTagId . ')');
                            }
                        }
                    }
                }

                if (!empty($tagRecords)) {
                    Db::table('post_tag')->insert($tagRecords);
                }
            }

            // 如果文章状态为已发布，清除相关缓存
            if ($status === 'published') {
                try {
                    Log::info("[EditorController] Post {$post_id} published, clearing caches...");
                    CacheService::clearPublishCache($post_id);
                } catch (Exception $e) {
                    Log::warning('[EditorController] Failed to clear cache: ' . $e->getMessage());
                }
            }

            // 返回成功响应
            return json([
                'code' => 0,
                'msg' => '保存成功',
                'data' => ['id' => $post_id],
            ]);
        } catch (Exception $e) {
            // 捕获异常并返回错误信息
            return json(['code' => 1, 'msg' => '保存失败：' . $e->getMessage()]);
        }
    }

    /**
     * 获取作者列表
     *
     * @param Request $request
     *
     * @return Response
     * @throws Exception
     */
    public function getAuthors(Request $request): Response
    {
        // 获取当前管理员信息
        $adminInfo = $request->session()->get('admin', []);
        $adminId = $adminInfo['id'] ?? 0;
        $role = $adminInfo['role'] ?? '';

        if ($adminId <= 0) {
            return json(['code' => 1, 'msg' => '未登录或权限不足']);
        }

        try {
            $search = $request->get('search', '');
            $page = $request->get('page', 1);
            $limit = $request->get('limit', 10);

            $query = Author::query();

            // 如果是普通用户，只能查看自己
            if ($role !== 'administrator') {
                $query->where('id', $adminId);
            }

            // 搜索条件 - 支持同时搜索管理员和普通用户
            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('username', 'like', "%{$search}%")
                        ->orWhere('nickname', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            }

            // 获取分页结果
            $authors = $query->orderBy('id', 'desc')
                ->paginate($limit, ['*'], 'page', $page);

            $authorList = [];
            foreach ($authors->items() as $author) {
                $authorList[] = [
                    'id' => $author->id,
                    'username' => $author->username,
                    'nickname' => $author->nickname,
                    'email' => $author->email,
                    'avatar' => $author->avatar,
                ];
            }

            return json([
                'code' => 0,
                'msg' => '获取成功',
                'data' => [
                    'list' => $authorList,
                    'total' => $authors->total(),
                    'current_page' => $authors->currentPage(),
                    'per_page' => $authors->perPage(),
                ],
            ]);

        } catch (Exception $e) {
            return json(['code' => 1, 'msg' => '获取失败：' . $e->getMessage()]);
        }
    }

    /**
     * 上传图片
     *
     * @param Request $request
     *
     * @return Response
     */
    public function uploadImage(Request $request): Response
    {
        try {
            // 获取上传的文件
            $file = $request->file('image');
            if (!$file) {
                return json(['success' => 0, 'message' => '请选择要上传的图片']);
            }

            // 检查文件是否为图片
            if (!$file->isValid() || !in_array(strtolower($file->getUploadExtension()), ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'])) {
                return json(['success' => 0, 'message' => '请上传有效的图片文件']);
            }

            // 使用媒体库服务上传媒体
            $mediaService = new MediaLibraryService();
            $result = $mediaService->upload($file);

            // 根据结果返回Vditor需要的格式
            if ($result['code'] === 0) {
                // 上传成功，返回Vditor需要的格式
                return json([
                    'success' => 1,
                    'file' => [
                        'url' => $result['data']->url,
                    ],
                ]);
            } else {
                // 上传失败
                return json([
                    'success' => 0,
                    'message' => $result['msg'],
                ]);
            }
        } catch (Exception $e) {
            // 捕获异常并返回错误信息
            return json([
                'success' => 0,
                'message' => '上传失败：' . $e->getMessage(),
            ]);
        }
    }

    /**
     * 打开媒体选择器
     *
     * @param Request $request
     *
     * @return Response
     */
    public function mediaSelector(Request $request): Response
    {
        // 获取请求参数
        $target = $request->get('target', 'iframe'); // 通信目标类型（window或iframe）
        $origin = $request->get('origin', ''); // 来源窗口URL
        $multiple = $request->get('multiple', 'false'); // 是否允许多选

        // 传递参数到视图
        return view('media/media_selector', [
            'target' => $target,
            'origin' => $origin,
            'multiple' => $multiple,
        ]);
    }

    /**
     * 处理媒体选择
     *
     * @param Request $request
     *
     * @return Response
     */
    public function selectMedia(Request $request): Response
    {
        // 获取选中的媒体ID
        $mediaId = $request->post('media_id', 0);

        if ($mediaId <= 0) {
            return json(['code' => 1, 'msg' => '请选择有效的媒体文件']);
        }

        try {
            // 直接使用Media模型获取媒体信息
            $media = Media::find($mediaId);

            if (!$media) {
                return json(['code' => 1, 'msg' => '媒体文件不存在']);
            }

            // 构建完整的媒体URL
            $baseUrl = rtrim($request->path(), '/');
            $fullUrl = $baseUrl . '/uploads/' . $media->file_path;

            // 返回媒体信息，用于在编辑器中插入
            return json([
                'code' => 0,
                'msg' => '获取成功',
                'data' => [
                    'id' => $media->id,
                    'url' => $fullUrl,
                    'file_path' => $media->file_path,
                    'alt_text' => $media->alt_text,
                    'original_name' => $media->original_name,
                    'mime_type' => $media->mime_type,
                ],
            ]);
        } catch (Exception $e) {
            return json(['code' => 1, 'msg' => '获取失败：' . $e->getMessage()]);
        }
    }

    /**
     * 获取或创建默认作者
     *
     * @param int $adminId 管理员ID
     *
     * @return int|null 返回用户ID，如果创建失败返回null
     */
    private function getOrCreateDefaultAuthor(int $adminId): ?int
    {
        try {
            // 首先尝试获取管理员信息
            $admin = Db::table('wa_admins')->where('id', $adminId)->first();

            if (!$admin) {
                return null;
            }

            // 尝试查找是否已有对应的用户记录
            $existingUser = Db::table('wa_users')
                ->where('username', $admin->username)
                ->orWhere('email', $admin->email)
                ->first();

            if ($existingUser) {
                return $existingUser->id;
            }

            // 创建新的用户记录
            $userId = Db::table('wa_users')->insertGetId([
                'username' => $admin->username,
                'nickname' => $admin->nickname,
                'password' => password_hash('default_password_' . time(), PASSWORD_DEFAULT),
                'email' => $admin->email ?? '',
                'avatar' => $admin->avatar ?? '',
                'created_at' => utc_now_string('Y-m-d H:i:s'),
                'updated_at' => utc_now_string('Y-m-d H:i:s'),
                'join_time' => utc_now_string('Y-m-d H:i:s'),
                'status' => 0, // 启用状态
            ]);

            return $userId;
        } catch (Exception $e) {
            Log::error('创建默认作者失败: ' . $e->getMessage());

            return null;
        }
    }

    public function post(Request $request, ?int $id = null): Response
    {
        // 验证ID
        if (empty($id) || !is_numeric($id) || $id < 1 || $id > 2147483646/* int最大值-1 */) {
            return json([
                'code' => 400,
                'message' => trans('Missing input parameter :parameter', ['parameter' => 'id']), // 缺少输入参数 id
            ]);
        }

        // 查询文章及作者信息、分类和标签
        $post = Post::with([
            'authors' => function ($query) {
                $query->select('wa_users.id', 'username', 'nickname', 'email', 'avatar');
            },
            'categories' => function ($query) {
                $query->select('categories.id', 'name', 'slug');
            },
            'tags' => function ($query) {
                $query->select('tags.id', 'name', 'slug');
            },
        ])->find($id);

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
                'seo_title' => $post->seo_title ?? '',
                'seo_description' => $post->seo_description ?? '',
                'seo_keywords' => $post->seo_keywords ?? '',
                'authors' => $post->authors ? $post->authors->toArray() : [],
                'categories' => $post->categories ? $post->categories->toArray() : [],
                'tags' => $post->tags ? $post->tags->toArray() : [],
            ],
        ]);
    }

    /**
     * 生成 slug
     *
     * @param string $text
     *
     * @return string
     */
    private function generateSlug(string $text): string
    {
        // 移除特殊字符，只保留字母、数字、中文和连字符
        $slug = preg_replace('/[^\w\x{4e00}-\x{9fa5}-]+/u', '-', $text);
        // 移除首尾的连字符
        $slug = trim($slug, '-');
        // 转换为小写
        $slug = strtolower($slug);
        // 如果 slug 为空，使用时间戳
        if (empty($slug)) {
            $slug = 'tag-' . time();
        }

        return $slug;
    }
}
