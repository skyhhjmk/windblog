<?php

namespace plugin\admin\app\controller;

use app\service\MediaLibraryService;
use support\Request;
use support\Response;
use app\model\Post;

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
            'id' => $id
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
        
        // 验证输入
        if (empty($title)) {
            return json(['code' => 1, 'msg' => '请输入文章标题']);
        }
        
        if (empty($content)) {
            return json(['code' => 1, 'msg' => '请输入文章内容']);
        }
        
        // 获取当前管理员ID（假设存储在 session 中）
        $adminId = $request->session()->get('admin_id', 0);
        if ($adminId <= 0) {
            return json(['code' => 1, 'msg' => '管理员未登录或权限不足']);
        }

        // 准备数据
        $data = [
            'title' => $title,
            'content' => $content,
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        try {
            if ($post_id > 0) {
                // 更新已有文章
                $post = Post::find($post_id);
                if (!$post) {
                    return json(['code' => 1, 'msg' => '文章不存在']);
                }
                $post->update($data);
            } else {
                // 创建新文章，并设置 author_id
                $data['created_at'] = date('Y-m-d H:i:s');
                $data['author_id'] = $adminId;
                $post = Post::create($data);
                $post_id = $post->id;
            }
            
            // 返回成功响应
            return json([
                'code' => 0,
                'msg' => '保存成功',
                'data' => ['id' => $post_id]
            ]);
        } catch (\Exception $e) {
            // 捕获异常并返回错误信息
            return json(['code' => 1, 'msg' => '保存失败：' . $e->getMessage()]);
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
            
            // 使用媒体库服务上传文件
            $mediaService = new MediaLibraryService();
            $result = $mediaService->upload($file);
            
            // 根据结果返回Vditor需要的格式
            if ($result['code'] === 0) {
                // 上传成功，返回Vditor需要的格式
                return json([
                    'success' => 1, 
                    'file' => [
                        'url' => $result['data']->url
                    ]
                ]);
            } else {
                // 上传失败
                return json([
                    'success' => 0, 
                    'message' => $result['msg']
                ]);
            }
        } catch (\Exception $e) {
            // 捕获异常并返回错误信息
            return json([
                'success' => 0, 
                'message' => '上传失败：' . $e->getMessage()
            ]);
        }
    }
}