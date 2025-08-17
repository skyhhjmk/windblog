<?php

namespace app\admin\controller;

use app\model\Media;
use support\Request;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Webman\Http\UploadFile;

class MediaController
{
    /**
     * 显示媒体库
     *
     * @param Request $request
     * @return \support\Response
     */
    public function index(Request $request)
    {
        $page = $request->get('page', 1);
        $limit = 20;
        
        $query = Media::orderByDesc('created_at');
        
        // 按文件名搜索
        if ($search = $request->get('search')) {
            $query->where('filename', 'like', "%{$search}%")
                  ->orWhere('original_name', 'like', "%{$search}%");
        }
        
        $media = $query->paginate($limit, ['*'], 'page', $page);
        
        return view('admin/media/index', [
            'media' => $media,
            'search' => $search
        ]);
    }

    /**
     * 显示上传页面
     *
     * @param Request $request
     * @return \support\Response
     */
    public function upload(Request $request)
    {
        return view('admin/media/upload');
    }

    /**
     * 处理上传请求
     *
     * @param Request $request
     * @return \support\Response
     */
    public function doUpload(Request $request)
    {
        try {
            // 检查是否有上传文件
            $file = $request->file('file');
            if (!$file) {
                return json(['code' => 400, 'msg' => '没有上传文件']);
            }
            
            // 在移动文件前获取所有必要信息，避免stat failed错误
            $originalName = $file->getUploadName();
            $fileSize = $file->getSize();
            $mimeType = $file->getUploadMimeType();
            $fileExtension = $file->getUploadExtension();
            
            // 验证文件类型
            $allowedTypes = [
                'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
                'video/mp4', 'video/avi', 'video/mpeg',
                'audio/mpeg', 'audio/wav',
                'application/pdf',
                'text/plain'
            ];
            
            if (!in_array($mimeType, $allowedTypes)) {
                return json(['code' => 400, 'msg' => '不支持的文件类型']);
            }
            
            // 验证文件大小（最大10MB）
            if ($fileSize > 10 * 1024 * 1024) {
                return json(['code' => 400, 'msg' => '文件大小不能超过10MB']);
            }
            
            // 创建上传目录
            $uploadDir = public_path('uploads');
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            // 按年月创建子目录
            $subDir = date('Y/m');
            $fullUploadDir = $uploadDir . '/' . $subDir;
            if (!is_dir($fullUploadDir)) {
                mkdir($fullUploadDir, 0755, true);
            }
            
            // 生成唯一文件名
            $filename = time() . '_' . uniqid() . '.' . $file->getUploadExtension();
            $filePath = $fullUploadDir . DIRECTORY_SEPARATOR . $filename;

            // 移动文件到指定目录
            if (!$file->move($filePath)) {
                // 记录错误日志
                \support\Log::error("文件移动失败: {$originalName} 到 {$filePath}");
                return json(['code' => 500, 'msg' => '文件上传失败']);
            }
            
            // 保存到媒体表
            $media = new Media();
            $media->filename = $filename;
            $media->original_name = $originalName;
            $media->file_path = $subDir . '/' . $filename;
            $media->file_size = $fileSize;
            $media->mime_type = $mimeType;
            $media->author_id = 1; // 默认作者ID，实际项目中应该从登录用户获取
            $media->alt_text = $request->post('alt_text', '');
            $media->caption = $request->post('caption', '');
            $media->description = $request->post('description', '');
            $media->save();
            
            // 为图片生成缩略图
            if ($media->is_image) {
                $this->generateThumbnail($media);
            }
            
            return json(['code' => 200, 'msg' => '上传成功']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '上传失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 显示编辑页面
     *
     * @param Request $request
     * @param int $id
     * @return \support\Response
     */
    public function edit(Request $request, int $id)
    {
        $media = Media::find($id);
        if (!$media) {
            return json(['code' => 404, 'msg' => '文件不存在']);
        }

        return view('admin/media/edit', [
            'media' => $media
        ]);
    }

    /**
     * 更新媒体信息
     *
     * @param Request $request
     * @param int $id
     * @return \support\Response
     */
    public function update(Request $request, int $id)
    {
        $media = Media::find($id);
        if (!$media) {
            return json(['code' => 404, 'msg' => '文件不存在']);
        }

        try {
            // 更新媒体信息
            $media->alt_text = $request->post('alt_text', $media->alt_text);
            $media->caption = $request->post('caption', $media->caption);
            $media->description = $request->post('description', $media->description);
            $media->save();

            return json(['code' => 200, 'msg' => '更新成功']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '更新失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 删除媒体文件
     *
     * @param Request $request
     * @param int $id
     * @return \support\Response
     */
    public function delete(Request $request, int $id)
    {
        $user = session('username');

        // 检查是否为 system 用户
        if ($user && $user->username === 'system') {
            return json(['code' => 403, 'msg' => 'system 用户无权执行此操作']);
        }

        $media = Media::find($id);
        if (!$media) {
            return json(['code' => 404, 'msg' => '文件不存在']);
        }

        // 删除物理文件
        $filePath = public_path('uploads/' . $media->file_path);
        if (file_exists($filePath)) {
            unlink($filePath);
        }
        
        // 删除缩略图文件（如果存在）
        try {
            // 检查模型是否有thumb_path属性
            if (isset($media->thumb_path) && $media->thumb_path) {
                $thumbPath = public_path('uploads/' . $media->thumb_path);
                if (file_exists($thumbPath)) {
                    unlink($thumbPath);
                }
            }
        } catch (\Exception $e) {
            // 如果thumb_path字段不存在，忽略错误
        }

        // 如果目录为空，尝试删除目录
        $dir = dirname($filePath);
        if (is_dir($dir) && count(scandir($dir)) == 2) { // 只有.和..
            rmdir($dir);
        }

        // 删除数据库记录
        $media->delete();

        return json(['code' => 200, 'msg' => '文件删除成功']);
    }
    
    /**
     * 为图片生成缩略图
     *
     * @param Media $media
     * @return void
     */
    private function generateThumbnail(Media $media)
    {
        // 只为图片生成缩略图
        if (!$media->is_image) {
            return;
        }
        
        // 检查是否已安装 Intervention Image 库
        if (!class_exists(ImageManager::class)) {
            return;
        }
        
        try {
            $originalPath = public_path('uploads/' . $media->file_path);
            if (!file_exists($originalPath)) {
                return;
            }
            
            // 创建缩略图目录
            $thumbDir = dirname($originalPath) . '/thumbs';
            if (!is_dir($thumbDir)) {
                mkdir($thumbDir, 0755, true);
            }
            
            // 生成缩略图文件名
            $filename = pathinfo($media->file_path, PATHINFO_FILENAME);
            $extension = pathinfo($media->file_path, PATHINFO_EXTENSION);
            $thumbFilename = $filename . '_thumb.' . $extension;
            $thumbPath = $thumbDir . '/' . $thumbFilename;
            
            // 生成缩略图 (使用 Intervention Image v3 的正确方式)
            $manager = new ImageManager(new GdDriver());
            $image = $manager->read($originalPath);
            $image->cover(200, 200, 'center');
            $image->save($thumbPath);
            
            // 更新数据库记录（仅在字段存在时）
            try {
                $media->thumb_path = dirname($media->file_path) . '/thumbs/' . $thumbFilename;
                $media->save();
            } catch (\Exception $e) {
                // 如果thumb_path字段不存在，忽略错误
            }
        } catch (\Exception $e) {
            // 如果缩略图生成失败，不进行处理
            // 可以添加日志记录
        }
    }
}