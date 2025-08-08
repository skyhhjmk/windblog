<?php

namespace app\admin\controller;

use app\model\Media;
use app\model\Post;
use support\Log;
use support\Request;
use support\Response;
use Webman\Http\UploadFile;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;

class EditorController
{
    /**
     * 编辑器
     *
     * @param Request $request
     * @param $id
     * @return Response
     */
    public function index(Request $request, $id)
    {
        $content = Post::where('id', $id)->first();
        $content = $content ? $content->content : '';
        return view('admin/editor/easymde', [
            'post_id' => $id,
            'content' => $content,
        ]);
    }

    public function save(Request $request)
    {

    }

    /**
     * 图片上传接口
     *
     * @param Request $request
     * @return \support\Response
     */
    public function uploadImage(Request $request)
    {
        try {
            // 检查是否有上传文件
            $file = $request->file('image');
            if (!$file) {
                return json([
                'success' => 0,
                'message' => '没有上传文件'
            ]);
            }

            // 获取文件信息
            $uploadName = $file->getUploadName();
            $uploadMimeType = $file->getUploadMimeType();
            $uploadExtension = $file->getUploadExtension();
            $fileSize = $file->getSize();

            // 验证文件类型（只允许图片）
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (!in_array($uploadMimeType, $allowedTypes)) {
                return json([
                'success' => 0,
                'message' => '只允许上传 jpeg, png, gif, webp 格式的图片'
            ]);
            }

            // 验证文件大小（最大50MB）
            if ($fileSize > 50 * 1024 * 1024) {
                return json([
                'success' => 0,
                'message' => '图片大小不能超过50MB'
            ]);
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
            $filename = time() . '_' . uniqid() . '.' . $uploadExtension;
            $filePath = $fullUploadDir . '/' . $filename;

            // 移动文件到指定目录
            if (!$file->move($filePath)) {
                return json([
                'success' => 0,
                'message' => '文件上传失败'
            ]);
            }

            // 保存到媒体表
            $media = new Media();
            $media->filename = $filename;
            $media->original_name = $uploadName;
            $media->file_path = $subDir . '/' . $filename;
            $media->file_size = $fileSize;
            $media->mime_type = $uploadMimeType;
            $media->author_id = 1; // 默认作者ID，实际项目中应该从登录用户获取

            // 生成缩略图
            $thumbPath = $this->generateThumbnail($media, $filePath);
            
            // 只有在成功生成缩略图且数据库支持thumb_path字段时才设置该字段
            if ($thumbPath) {
                try {
                    $media->thumb_path = $thumbPath;
                } catch (\Exception $e) {
                    // 如果thumb_path字段不存在，忽略错误
                    Log::warning('thumb_path field may not exist in media table: ' . $e->getMessage());
                }
            }

            $media->save();

            // 返回图片URL
            $imageUrl = config('image.external_url_prefix') . '/uploads/' . $subDir . '/' . $filename;

            return json([
                'success' => 1,
                'file' => [
                    'url' => $imageUrl,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Image upload error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return json([
                'success' => 0,
                'message' => '上传失败: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * 为图片生成缩略图
     *
     * @param Media $media
     * @param string $filePath
     * @return string|null 缩略图路径，如果生成失败则返回null
     */
    private function generateThumbnail(Media $media, string $filePath)
    {
        // 检查是否已安装 Intervention Image 库
        if (!class_exists(ImageManager::class)) {
            Log::warning('Intervention Image library not found');
            return null;
        }

        try {
            if (!file_exists($filePath)) {
                Log::warning('Source file not found for thumbnail generation', ['file' => $filePath]);
                return null;
            }

            // 创建缩略图目录
            $thumbDir = dirname($filePath) . '/thumbs';
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
            $image = $manager->read($filePath);
            $image->cover(200, 200, 'center');
            $image->save($thumbPath);

            return dirname($media->file_path) . '/thumbs/' . $thumbFilename;
        } catch (\Exception $e) {
            // 如果缩略图生成失败，不进行处理
            Log::warning('thumbnail generation failed: ' . $e->getMessage(), ['file' => $filePath]);
            return null;
        }
    }
}