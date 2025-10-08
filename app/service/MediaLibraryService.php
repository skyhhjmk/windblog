<?php

namespace app\service;

use app\model\Media;
use support\Request;
use Webman\Http\UploadFile;

class MediaLibraryService
{
    /**
     * 上传媒体文件
     *
     * @param UploadFile $file
     * @param array $data
     * @return array
     */
    public function upload(UploadFile $file, array $data = []): array
    {
        try {
            \app\service\PluginService::do_action('media.upload_start', [
                'name' => $file->getUploadName(),
                'mime' => $file->getUploadMimeType(),
                'size' => $file->getSize(),
                'data' => $data
            ]);
            // 检查文件是否有效
            if (!$file->isValid()) {
                return ['code' => 400, 'msg' => '文件无效'];
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
                return ['code' => 400, 'msg' => '不支持的文件类型'];
            }
            
            // 验证文件大小（最大10MB）
            if ($fileSize > 10 * 1024 * 1024) {
                return ['code' => 400, 'msg' => '文件大小不能超过10MB'];
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
                return ['code' => 500, 'msg' => '文件上传失败'];
            }
            
            // 获取当前用户信息
            $admin = admin();
            $authorId = $admin ? $admin['id'] : null;
            $authorType = $admin ? 'admin' : 'user'; // 默认为admin，因为这是后台上传
            
            // 保存到媒体表
            $media = new Media();
            $media->filename = $filename;
            $media->original_name = $originalName;
            $media->file_path = $subDir . '/' . $filename;
            $media->file_size = $fileSize;
            $media->mime_type = $mimeType;
            $media->author_id = $authorId; // 使用当前用户ID，如果未登录则为null
            $media->author_type = $authorType; // 设置作者类型
            $media->alt_text = $data['alt_text'] ?? '';
            $media->caption = $data['caption'] ?? '';
            $media->description = $data['description'] ?? '';
            $media->save();
            
            // 为图片生成缩略图
            if ($media->is_image) {
                $this->generateThumbnail($media);
            }
            
            \app\service\PluginService::do_action('media.upload_done', [
                'id' => $media->id ?? null,
                'path' => $media->file_path ?? null,
                'mime' => $media->mime_type ?? null
            ]);
            return ['code' => 0, 'msg' => '上传成功', 'data' => $media];
        } catch (\Exception $e) {
            return ['code' => 1, 'msg' => '上传失败: ' . $e->getMessage()];
        }
    }
    
    /**
     * 获取媒体列表
     *
     * @param array $params
     * @return array
     */
    public function getList(array $params = []): array
    {
        // 获取参数
        $search = $params['search'] ?? '';
        $page = (int)($params['page'] ?? 1);
        $limit = (int)($params['limit'] ?? 15);
        $order = $params['order'] ?? 'id';
        $sort = $params['sort'] ?? 'desc';
        $mimeType = $params['mime_type'] ?? '';
        
        // 构建查询
        $query = Media::query();
        
        // 搜索条件
        if ($search) {
            $query->where('filename', 'like', "%{$search}%")
                  ->orWhere('original_name', 'like', "%{$search}%");
        }
        
        // MIME类型筛选条件
        if ($mimeType) {
            $query->where('mime_type', 'like', "{$mimeType}%");
        }
        
        // 获取总数
        $total = $query->count();
        
        // 排序和分页
        $list = $query->orderBy($order, $sort)
            ->forPage($page, $limit)
            ->get()
            ->toArray();
        
        return [
            'list' => $list,
            'total' => $total
        ];
    }
    
    /**
     * 更新媒体信息
     *
     * @param int $id
     * @param array $data
     * @return array
     */
    public function update(int $id, array $data): array
    {
        $media = Media::find($id);
        if (!$media) {
            return ['code' => 1, 'msg' => '文件不存在'];
        }

        try {
            // 更新媒体信息
            $media->alt_text = $data['alt_text'] ?? $media->alt_text;
            $media->caption = $data['caption'] ?? $media->caption;
            $media->description = $data['description'] ?? $media->description;
            $media->save();

            return ['code' => 0, 'msg' => '更新成功'];
        } catch (\Exception $e) {
            return ['code' => 1, 'msg' => '更新失败: ' . $e->getMessage()];
        }
    }
    
    /**
     * 删除媒体文件
     *
     * @param int $id
     * @return array
     */
    public function delete(int $id): array
    {
        $media = Media::find($id);
        if (!$media) {
            return ['code' => 1, 'msg' => '文件不存在'];
        }

        try {
            \app\service\PluginService::do_action('media.delete_start', [
                'id' => $id,
                'path' => $media->file_path ?? null
            ]);
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

            \app\service\PluginService::do_action('media.delete_done', [
                'id' => $id,
                'path' => $filePath
            ]);

            return ['code' => 0, 'msg' => '文件删除成功'];
        } catch (\Exception $e) {
            return ['code' => 1, 'msg' => '删除失败: ' . $e->getMessage()];
        }
    }
    
    /**
     * 批量删除媒体文件
     *
     * @param array $ids
     * @return array
     */
    public function batchDelete(array $ids): array
    {
        if (empty($ids)) {
            return ['code' => 1, 'msg' => '参数错误'];
        }
        
        $count = 0;
        
        foreach ($ids as $id) {
            $result = $this->delete($id);
            if ($result['code'] === 0) {
                $count++;
            }
        }
        
        return ['code' => 0, 'msg' => "成功删除 {$count} 个文件"];
    }
    
    /**
     * 重新生成缩略图
     *
     * @param int $id
     * @return array
     */
    public function regenerateThumbnail(int $id): array
    {
        $media = Media::find($id);
        if (!$media) {
            return ['code' => 1, 'msg' => '文件不存在'];
        }
        
        // 只能为图片生成缩略图
        if (!$media->is_image) {
            return ['code' => 1, 'msg' => '只能为图片文件生成缩略图'];
        }
        
        try {
            // 删除旧的缩略图（如果存在）
            if ($media->thumb_path) {
                $thumbPath = public_path('uploads/' . $media->thumb_path);
                if (file_exists($thumbPath)) {
                    unlink($thumbPath);
                }
            }
            
            // 生成新的缩略图
            $this->generateThumbnail($media);
            
            return ['code' => 0, 'msg' => '缩略图生成成功'];
        } catch (\Exception $e) {
            return ['code' => 1, 'msg' => '缩略图生成失败: ' . $e->getMessage()];
        }
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
        
        // 检查GD扩展是否已加载
        if (extension_loaded('gd')) {
            // 使用GD生成缩略图
            $this->generateThumbnailWithGD($media);
        } 
        // 检查Imagick扩展是否已加载
        elseif (extension_loaded('imagick')) {
            // 使用Imagick生成缩略图
            $this->generateThumbnailWithImagick($media);
        }
        // TODO：如果两个扩展都没有加载，则提示用户缺少扩展
    }
    
    /**
     * 使用GD库生成缩略图
     *
     * @param Media $media
     * @return void
     */
    private function generateThumbnailWithGD(Media $media)
    {
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
            
            // 获取原始图片信息
            list($width, $height, $type) = getimagesize($originalPath);
            
            // 根据图片类型创建图像资源
            switch ($type) {
                case IMAGETYPE_JPEG:
                    $srcImage = imagecreatefromjpeg($originalPath);
                    break;
                case IMAGETYPE_PNG:
                    $srcImage = imagecreatefrompng($originalPath);
                    break;
                case IMAGETYPE_GIF:
                    $srcImage = imagecreatefromgif($originalPath);
                    break;
                case IMAGETYPE_WEBP:
                    $srcImage = imagecreatefromwebp($originalPath);
                    break;
                default:
                    return; // 不支持的图片类型
            }
            
            if (!$srcImage) {
                return;
            }
            
            // 创建缩略图资源
            $thumbImage = imagecreatetruecolor(200, 200);
            
            // 处理透明背景（针对PNG和GIF）
            if ($type == IMAGETYPE_PNG || $type == IMAGETYPE_GIF) {
                imagealphablending($thumbImage, false);
                imagesavealpha($thumbImage, true);
                $transparent = imagecolorallocatealpha($thumbImage, 255, 255, 255, 127);
                imagefilledrectangle($thumbImage, 0, 0, 200, 200, $transparent);
            }
            
            // 计算缩略图裁剪区域
            $srcRatio = $width / $height;
            $thumbRatio = 200 / 200;
            
            if ($srcRatio > $thumbRatio) {
                // 原图较宽，以高度为准进行裁剪
                $srcWidth = $height * $thumbRatio;
                $srcHeight = $height;
                $srcX = ($width - $srcWidth) / 2;
                $srcY = 0;
            } else {
                // 原图较高，以宽度为准进行裁剪
                $srcWidth = $width;
                $srcHeight = $width / $thumbRatio;
                $srcX = 0;
                $srcY = ($height - $srcHeight) / 2;
            }
            
            // 生成缩略图
            imagecopyresampled(
                $thumbImage, $srcImage,
                0, 0, $srcX, $srcY,
                200, 200, $srcWidth, $srcHeight
            );
            
            // 保存缩略图
            switch ($type) {
                case IMAGETYPE_JPEG:
                    imagejpeg($thumbImage, $thumbPath, 90);
                    break;
                case IMAGETYPE_PNG:
                    imagepng($thumbImage, $thumbPath);
                    break;
                case IMAGETYPE_GIF:
                    imagegif($thumbImage, $thumbPath);
                    break;
                case IMAGETYPE_WEBP:
                    imagewebp($thumbImage, $thumbPath);
                    break;
            }
            
            // 释放图像资源
            imagedestroy($srcImage);
            imagedestroy($thumbImage);
            
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
    
    /**
     * 使用Imagick库生成缩略图
     *
     * @param Media $media
     * @return void
     */
    private function generateThumbnailWithImagick(Media $media)
    {
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
            
            // 使用Imagick创建缩略图
            $imagick = new \Imagick($originalPath);
            
            // 设置缩略图尺寸
            $imagick->thumbnailImage(200, 200, true); // true表示保持宽高比
            
            // 写入文件
            $imagick->writeImage($thumbPath);
            
            // 清理资源
            $imagick->clear();
            $imagick->destroy();
            
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