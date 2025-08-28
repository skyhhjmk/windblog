<?php

namespace app\admin\controller;

use app\model\Media;
use app\model\Post;
use support\Log;
use support\Request;
use support\Response;
use Webman\Http\UploadFile;

class EditorController
{
    /**
     * 编辑器
     *
     * @param Request $request
     * @param $id
     * @return Response
     */
    public function index(Request $request, $id, $editor = 'vditor')
    {
        if (!in_array($editor, ['vditor', 'easymde'])){
            $editor = 'vditor';
        }
        $post = Post::where('id', $id)->first();
        $content = $post ? $post->content : '';
        $title = $post ? $post->title : '未命名文章';
        return view('admin/editor/vditor', [
            'post_id' => $id,
            'content' => $content ?: '',
            'title' => $title,
        ]);
    }

    public function save(Request $request)
    {
        $postId = $request->post('post_id');
        $content = $request->post('content');
        
        $post = Post::where('id', $postId)->first();
        if (!$post) {
            return json(['code' => 404, 'msg' => '文章不存在']);
        }
        
        $post->content = $content;
        $post->save();
        
        return json(['code' => 200, 'msg' => '保存成功']);
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

            // 验证文件类型（扩展支持的图片格式）
            $allowedTypes = [
                'image/jpeg', 'image/png', 'image/gif', 'image/webp',
                'image/bmp', 'image/svg+xml', 'image/tiff', 'image/x-icon',
                'image/vnd.microsoft.icon', 'image/x-tga', 'image/x-portable-pixmap'
            ];
            
            $allowedExtensions = [
                'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'tif', 
                'tiff', 'ico', 'ppm', 'tga'
            ];
            
            if (!in_array($uploadMimeType, $allowedTypes) && !in_array(strtolower($uploadExtension), $allowedExtensions)) {
                return json([
                    'success' => 0,
                    'message' => '只允许上传以下格式的图片: jpg, jpeg, png, gif, webp, bmp, svg, tif, tiff, ico, ppm, tga'
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
            $imageUrl = '/uploads/' . $subDir . '/' . $filename;

            // 同时兼容Vditor和EasyMDE
            return json([
                'success' => 1,
                'code' => 200,
                'message' => '上传成功',
                'data' => [
                    'url' => $imageUrl,
                ],
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
        // 检查GD扩展是否可用
        if (!extension_loaded('gd')) {
            Log::warning('GD extension not found');
            return null;
        }

        // 只对支持的图片格式生成缩略图
        $supportedFormats = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $extension = strtolower(pathinfo($media->file_path, PATHINFO_EXTENSION));
        
        if (!in_array($extension, $supportedFormats)) {
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
            $thumbFilename = $filename . '_thumb.' . $extension;
            $thumbPath = $thumbDir . '/' . $thumbFilename;

            // 使用 GD 函数生成缩略图
            $thumb = $this->createThumbnailGD($filePath, 200, 200);
            if ($thumb === false) {
                return null;
            }

            // 根据图片类型保存缩略图
            switch (strtolower($extension)) {
                case 'jpg':
                case 'jpeg':
                    imagejpeg($thumb, $thumbPath, 80);
                    break;
                case 'png':
                    imagepng($thumb, $thumbPath, 6);
                    break;
                case 'gif':
                    imagegif($thumb, $thumbPath);
                    break;
                case 'webp':
                    imagewebp($thumb, $thumbPath, 80);
                    break;
                default:
                    imagedestroy($thumb);
                    return null;
            }

            // 释放内存
            imagedestroy($thumb);

            return dirname($media->file_path) . '/thumbs/' . $thumbFilename;
        } catch (\Exception $e) {
            // 如果缩略图生成失败，不进行处理
            Log::warning('thumbnail generation failed: ' . $e->getMessage(), ['file' => $filePath]);
            return null;
        }
    }

    /**
     * 使用 GD 创建缩略图
     *
     * @param string $srcPath 源图片路径
     * @param int $thumbWidth 缩略图宽度
     * @param int $thumbHeight 缩略图高度
     * @return resource|false 返回缩略图资源或false
     */
    private function createThumbnailGD($srcPath, $thumbWidth, $thumbHeight)
    {
        // 获取源图片信息
        $info = getimagesize($srcPath);
        if ($info === false) {
            return false;
        }

        $srcWidth = $info[0];
        $srcHeight = $info[1];
        $mimeType = $info['mime'];

        // 创建源图片资源
        $src = null;
        switch ($mimeType) {
            case 'image/jpeg':
                $src = imagecreatefromjpeg($srcPath);
                break;
            case 'image/png':
                $src = imagecreatefrompng($srcPath);
                break;
            case 'image/gif':
                $src = imagecreatefromgif($srcPath);
                break;
            case 'image/webp':
                $src = imagecreatefromwebp($srcPath);
                break;
            default:
                return false;
        }

        if ($src === false) {
            return false;
        }

        // 计算缩略图尺寸（保持比例）
        $ratio = min($srcWidth, $srcHeight) / $thumbWidth;
        $newWidth = (int)($srcWidth / $ratio);
        $newHeight = (int)($srcHeight / $ratio);

        // 创建缩略图资源
        $thumb = imagecreatetruecolor($newWidth, $newHeight);
        if ($thumb === false) {
            imagedestroy($src);
            return false;
        }

        // 保持 PNG 和 GIF 透明度
        if ($mimeType === 'image/png' || $mimeType === 'image/gif') {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
            $transparent = imagecolorallocatealpha($thumb, 255, 255, 255, 127);
            imagefilledrectangle($thumb, 0, 0, $newWidth, $newHeight, $transparent);
        }

        // 缩放图片
        if (!imagecopyresampled($thumb, $src, 0, 0, 0, 0, $newWidth, $newHeight, $srcWidth, $srcHeight)) {
            imagedestroy($src);
            imagedestroy($thumb);
            return false;
        }

        // 释放源图片资源
        imagedestroy($src);

        return $thumb;
    }
}