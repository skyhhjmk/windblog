<?php

namespace plugin\admin\app\controller;

use app\service\MediaLibraryService;
use support\Request;
use support\Response;
use app\model\Post;
use support\view\Raw;

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
            'multiple' => $multiple
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
            $media = \app\model\Media::find($mediaId);
            
            if (!$media) {
                return json(['code' => 1, 'msg' => '媒体文件不存在']);
            }
            
            // 构建完整的媒体URL
            $baseUrl = rtrim(request()->root(), '/');
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
                    'mime_type' => $media->mime_type
                ]
            ]);
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '获取失败：' . $e->getMessage()]);
        }
    }
}