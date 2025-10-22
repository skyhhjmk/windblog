<?php

namespace plugin\admin\app\controller;

use app\model\Author;
use app\model\Media;
use app\model\Post;
use app\service\MediaLibraryService;
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
     * @param Request $request
     * @param int $id 可选的文章ID，用于编辑已有文章
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
     * @param Request $request
     * @return Response
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
            'updated_at' => date('Y-m-d H:i:s'),
        ];

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
            $data['password'] = $password;
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
                $post->update($data);

                // 删除现有的作者关联
                Db::table('post_author')->where('post_id', $post_id)->delete();
            } else {
                // 创建新文章，并设置 author_id
                $data['created_at'] = date('Y-m-d H:i:s');
                $data['author_id'] = $adminId;
                $post = Post::create($data);
                $post_id = $post->id;
            }

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
                            'created_at' => date('Y-m-d H:i:s'),
                            'updated_at' => date('Y-m-d H:i:s'),
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
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
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
     * @param Request $request
     * @return Response
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
     * @param Request $request
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
     * @param Request $request
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
     * @param Request $request
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
     * @param int $adminId 管理员ID
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
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
                'join_time' => date('Y-m-d H:i:s'),
                'status' => 0, // 启用状态
            ]);

            return $userId;
        } catch (Exception $e) {
            Log::error('创建默认作者失败: ' . $e->getMessage());

            return null;
        }
    }
}
