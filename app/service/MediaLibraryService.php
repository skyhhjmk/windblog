<?php

namespace app\service;

use app\model\Media;
use Exception;
use Imagick;
use support\Log;
use Webman\Http\UploadFile;

class MediaLibraryService
{
    /**
     * 上传媒体文件
     *
     * @param UploadFile $file
     * @param array $data
     *
     * @return array
     */
    public function upload(UploadFile $file, array $data = []): array
    {
        try {
            PluginService::do_action('media.upload_start', [
                'name' => $file->getUploadName(),
                'mime' => $file->getUploadMimeType(),
                'size' => $file->getSize(),
                'data' => $data,
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

            // 获取配置
            $config = config('media', []);

            // 检查危险文件类型
            if ($this->isDangerousFile($mimeType, $fileExtension)) {
                return ['code' => 400, 'msg' => '禁止上传危险文件类型'];
            }

            // 检查允许的文件类型
            if (!$this->isAllowedFile($mimeType, $fileExtension)) {
                return ['code' => 400, 'msg' => '不支持的文件类型'];
            }

            // 验证文件大小
            $maxSize = $config['max_file_size'] ?? (10 * 1024 * 1024);
            if ($fileSize > $maxSize) {
                return ['code' => 400, 'msg' => '文件大小超过限制'];
            }

            // 创建上传目录
            $uploadDir = public_path('uploads');
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0o755, true);
            }

            // 按年月创建子目录
            $subDir = date('Y/m');
            $fullUploadDir = $uploadDir . '/' . $subDir;
            if (!is_dir($fullUploadDir)) {
                mkdir($fullUploadDir, 0o755, true);
            }

            // 生成唯一文件名
            $filename = time() . '_' . uniqid() . '.' . $file->getUploadExtension();
            $filePath = $fullUploadDir . DIRECTORY_SEPARATOR . $filename;

            // 移动文件到指定目录
            if (!$file->move($filePath)) {
                // 记录错误日志
                Log::error("文件移动失败: {$originalName} 到 {$filePath}");

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

            // 如果是图片且不是webp格式，转换为webp
            if ($this->isImageMimeType($media->mime_type) && $media->mime_type !== 'image/webp') {
                $webpResult = $this->convertToWebp($media);
                if ($webpResult['code'] === 0) {
                    // 更新媒体信息
                    $media->filename = $webpResult['data']['filename'];
                    $media->file_path = $webpResult['data']['file_path'];
                    $media->file_size = $webpResult['data']['file_size'];
                    $media->mime_type = 'image/webp';
                    $media->save();
                }
            }

            // 为图片生成缩略图
            if ($this->isImageMimeType($media->mime_type)) {
                $this->generateThumbnail($media);
            }

            PluginService::do_action('media.upload_done', [
                'id' => $media->id ?? null,
                'path' => $media->file_path ?? null,
                'mime' => $media->mime_type ?? null,
            ]);

            return ['code' => 0, 'msg' => '上传成功', 'data' => $media];
        } catch (Exception $e) {
            return ['code' => 1, 'msg' => '上传失败: ' . $e->getMessage()];
        }
    }

    /**
     * 将图片转换为webp格式
     *
     * @param Media $media
     *
     * @return array
     */
    private function convertToWebp(Media $media): array
    {
        try {
            // 优先使用Imagick扩展
            if (extension_loaded('imagick')) {
                return $this->convertToWebpImagick($media);
            } // 使用GD扩展
            elseif (extension_loaded('gd') && function_exists('imagewebp')) {
                return $this->convertToWebpGD($media);
            } else {
                return ['code' => 1, 'msg' => '服务器不支持webp转换'];
            }
        } catch (Exception $e) {
            return ['code' => 1, 'msg' => '转换失败: ' . $e->getMessage()];
        }
    }

    /**
     * 使用Imagick库将图片转换为webp格式
     *
     * @param Media $media
     *
     * @return array
     */
    private function convertToWebpImagick(Media $media): array
    {
        try {
            $originalPath = public_path('uploads/' . $media->file_path);
            if (!file_exists($originalPath)) {
                return ['code' => 1, 'msg' => '原始文件不存在'];
            }

            // 获取文件名和目录
            $filename = pathinfo($media->file_path, PATHINFO_FILENAME);
            $webpFilename = $filename . '.webp';
            $webpPath = dirname($originalPath) . DIRECTORY_SEPARATOR . $webpFilename;

            // 使用Imagick转换为webp
            $imagickClass = 'Imagick';
            $imagick = new $imagickClass();
            $imagick->readImage($originalPath);
            $imagick->setImageFormat('webp');
            $imagick->setImageCompressionQuality(90); // 设置压缩质量
            $imagick->writeImage($webpPath);
            $imagick->destroy();

            // 检查webp文件是否生成成功
            if (!file_exists($webpPath)) {
                return ['code' => 1, 'msg' => 'webp文件生成失败'];
            }

            // 删除原始文件
            unlink($originalPath);

            // 返回webp文件信息
            return [
                'code' => 0,
                'data' => [
                    'filename' => $webpFilename,
                    'file_path' => dirname($media->file_path) . '/' . $webpFilename,
                    'file_size' => filesize($webpPath),
                ],
            ];
        } catch (Exception $e) {
            return ['code' => 1, 'msg' => '转换失败: ' . $e->getMessage()];
        }
    }

    /**
     * 使用GD库将图片转换为webp格式
     *
     * @param Media $media
     *
     * @return array
     */
    private function convertToWebpGD(Media $media): array
    {
        try {
            $originalPath = public_path('uploads/' . $media->file_path);
            if (!file_exists($originalPath)) {
                return ['code' => 1, 'msg' => '原始文件不存在'];
            }

            // 获取文件名和目录
            $filename = pathinfo($media->file_path, PATHINFO_FILENAME);
            $webpFilename = $filename . '.webp';
            $webpPath = dirname($originalPath) . DIRECTORY_SEPARATOR . $webpFilename;

            // 获取图片信息
            $imageInfo = getimagesize($originalPath);
            $type = $imageInfo[2];

            // 根据图片类型创建图像资源
            switch ($type) {
                case IMAGETYPE_JPEG:
                    $image = imagecreatefromjpeg($originalPath);
                    break;
                case IMAGETYPE_PNG:
                    $image = imagecreatefrompng($originalPath);
                    // 处理PNG透明背景
                    imagealphablending($image, true);
                    imagesavealpha($image, true);
                    break;
                case IMAGETYPE_GIF:
                    $image = imagecreatefromgif($originalPath);
                    break;
                default:
                    return ['code' => 1, 'msg' => '不支持的图片类型'];
            }

            if (!$image) {
                return ['code' => 1, 'msg' => '创建图像资源失败'];
            }

            // 保存为webp格式
            imagewebp($image, $webpPath, 90);
            imagedestroy($image);

            // 检查webp文件是否生成成功
            if (!file_exists($webpPath)) {
                return ['code' => 1, 'msg' => 'webp文件生成失败'];
            }

            // 删除原始文件
            unlink($originalPath);

            // 返回webp文件信息
            return [
                'code' => 0,
                'data' => [
                    'filename' => $webpFilename,
                    'file_path' => dirname($media->file_path) . '/' . $webpFilename,
                    'file_size' => filesize($webpPath),
                ],
            ];
        } catch (Exception $e) {
            return ['code' => 1, 'msg' => '转换失败: ' . $e->getMessage()];
        }
    }

    /**
     * 获取媒体列表
     *
     * @param array $params
     *
     * @return array
     */
    public function getList(array $params = []): array
    {
        // 获取参数
        $search = $params['search'] ?? '';
        $page = (int) ($params['page'] ?? 1);
        $limit = (int) ($params['limit'] ?? 15);
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
            'total' => $total,
        ];
    }

    /**
     * 更新媒体信息
     *
     * @param int $id
     * @param array $data
     *
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
        } catch (Exception $e) {
            return ['code' => 1, 'msg' => '更新失败: ' . $e->getMessage()];
        }
    }

    /**
     * 检查MIME类型是否为图片
     *
     * @param string $mimeType
     *
     * @return bool
     */
    private function isImageMimeType(string $mimeType): bool
    {
        $imageMimeTypes = [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
        ];

        return in_array($mimeType, $imageMimeTypes);
    }

    /**
     * 检查是否为危险文件类型
     *
     * @param string $mimeType
     * @param string $extension
     *
     * @return bool
     */
    private function isDangerousFile(string $mimeType, string $extension): bool
    {
        $config = config('media', []);
        $dangerousTypes = $config['dangerous_types'] ?? [];

        // 检查MIME类型是否在黑名单中
        if (isset($dangerousTypes[$mimeType]) && in_array(strtolower($extension), $dangerousTypes[$mimeType])) {
            return true;
        }

        // 检查扩展名是否在黑名单中
        foreach ($dangerousTypes as $dangerousMime => $dangerousExts) {
            if (in_array(strtolower($extension), $dangerousExts)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 检查是否为允许的文件类型
     *
     * @param string $mimeType
     * @param string $extension
     *
     * @return bool
     */
    private function isAllowedFile(string $mimeType, string $extension): bool
    {
        $config = config('media', []);
        $allowedTypes = $config['allowed_types'] ?? [];

        // 检查MIME类型是否在白名单中
        if (isset($allowedTypes[$mimeType]) && in_array(strtolower($extension), $allowedTypes[$mimeType])) {
            return true;
        }

        // 检查扩展名是否在白名单中
        foreach ($allowedTypes as $allowedMime => $allowedExts) {
            if (in_array(strtolower($extension), $allowedExts)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 获取文件类型分类
     *
     * @param string $mimeType
     *
     * @return string
     */
    private function getFileCategory(string $mimeType): string
    {
        if (strpos($mimeType, 'image/') === 0) {
            return 'image';
        } elseif (strpos($mimeType, 'video/') === 0) {
            return 'video';
        } elseif (strpos($mimeType, 'audio/') === 0) {
            return 'audio';
        } elseif (strpos($mimeType, 'text/') === 0 ||
            strpos($mimeType, 'application/json') === 0 ||
            strpos($mimeType, 'application/xml') === 0) {
            return 'document';
        } else {
            return 'other';
        }
    }

    /**
     * 删除媒体文件
     *
     * @param int $id
     *
     * @return array
     */
    public function delete(int $id): array
    {
        $media = Media::find($id);
        if (!$media) {
            return ['code' => 1, 'msg' => '文件不存在'];
        }

        try {
            PluginService::do_action('media.delete_start', [
                'id' => $id,
                'path' => $media->file_path ?? null,
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
            } catch (Exception $e) {
                // 如果thumb_path字段不存在，忽略错误
            }

            // 如果目录为空，尝试删除目录
            $dir = dirname($filePath);
            if (is_dir($dir) && count(scandir($dir)) == 2) { // 只有.和..
                rmdir($dir);
            }

            // 删除数据库记录
            $media->delete();

            PluginService::do_action('media.delete_done', [
                'id' => $id,
                'path' => $filePath,
            ]);

            return ['code' => 0, 'msg' => '文件删除成功'];
        } catch (Exception $e) {
            return ['code' => 1, 'msg' => '删除失败: ' . $e->getMessage()];
        }
    }

    /**
     * 批量删除媒体文件
     *
     * @param array $ids
     *
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
     *
     * @return array
     */
    public function regenerateThumbnail(int $id): array
    {
        $media = Media::find($id);
        if (!$media) {
            return ['code' => 1, 'msg' => '文件不存在'];
        }

        // 只能为图片生成缩略图
        if (!$this->isImageMimeType($media->mime_type)) {
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
        } catch (Exception $e) {
            return ['code' => 1, 'msg' => '缩略图生成失败: ' . $e->getMessage()];
        }
    }

    /**
     * 为图片生成缩略图
     *
     * @param Media $media
     *
     * @return void
     */
    private function generateThumbnail(Media $media)
    {
        // 只为图片生成缩略图
        if (!$this->isImageMimeType($media->mime_type)) {
            return;
        }

        // 优先使用 imagick
        // 检查Imagick扩展是否已加载
        if (extension_loaded('imagick')) {
            // 使用Imagick生成缩略图
            $this->generateThumbnailWithImagick($media);
        } // 检查GD扩展是否已加载
        elseif (extension_loaded('gd')) {
            // 使用GD生成缩略图
            $this->generateThumbnailWithGD($media);
        }
        // TODO：如果两个扩展都没有加载，则提示用户缺少扩展
    }

    /**
     * 使用GD库生成缩略图
     *
     * @param Media $media
     *
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
                mkdir($thumbDir, 0o755, true);
            }

            // 生成缩略图文件名 - 使用webp格式
            $filename = pathinfo($media->file_path, PATHINFO_FILENAME);
            $thumbFilename = $filename . '_thumb.webp';
            $thumbPath = $thumbDir . '/' . $thumbFilename;

            // 获取原始图片信息
            [$width, $height, $type] = getimagesize($originalPath);

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

            // 处理透明背景（针对PNG、GIF和WebP）
            if ($type == IMAGETYPE_PNG || $type == IMAGETYPE_GIF || $type == IMAGETYPE_WEBP) {
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
                $thumbImage,
                $srcImage,
                0,
                0,
                $srcX,
                $srcY,
                200,
                200,
                $srcWidth,
                $srcHeight
            );

            // 保存为webp格式
            imagewebp($thumbImage, $thumbPath, 90);

            // 释放图像资源
            imagedestroy($srcImage);
            imagedestroy($thumbImage);

            // 更新数据库记录（仅在字段存在时）
            try {
                $media->thumb_path = dirname($media->file_path) . '/thumbs/' . $thumbFilename;
                $media->save();
            } catch (Exception $e) {
                // 如果thumb_path字段不存在，忽略错误
            }
        } catch (Exception $e) {
            // 如果缩略图生成失败，不进行处理
            // 可以添加日志记录
        }
    }

    /**
     * 使用Imagick库生成缩略图
     *
     * @param Media $media
     *
     * @return void
     */
    private function generateThumbnailWithImagick(Media $media)
    {
        try {
            // 检查Imagick扩展是否已加载
            if (!extension_loaded('imagick')) {
                return;
            }

            $originalPath = public_path('uploads/' . $media->file_path);
            if (!file_exists($originalPath)) {
                return;
            }

            // 创建缩略图目录
            $thumbDir = dirname($originalPath) . '/thumbs';
            if (!is_dir($thumbDir)) {
                mkdir($thumbDir, 0o755, true);
            }

            // 生成缩略图文件名 - 使用webp格式
            $filename = pathinfo($media->file_path, PATHINFO_FILENAME);
            $thumbFilename = $filename . '_thumb.webp';
            $thumbPath = $thumbDir . '/' . $thumbFilename;

            // 使用Imagick创建缩略图
            $imagickClass = 'Imagick';
            $imagick = new $imagickClass();
            $imagick->readImage($originalPath);

            // 设置缩略图尺寸
            $imagick->thumbnailImage(200, 200, true); // true表示保持宽高比

            // 设置为webp格式
            $imagick->setImageFormat('webp');
            $imagick->setImageCompressionQuality(90);

            // 写入文件
            $imagick->writeImage($thumbPath);

            // 清理资源
            $imagick->destroy();

            // 更新数据库记录（仅在字段存在时）
            try {
                $media->thumb_path = dirname($media->file_path) . '/thumbs/' . $thumbFilename;
                $media->save();
            } catch (Exception $e) {
                // 如果thumb_path字段不存在，忽略错误
            }
        } catch (Exception $e) {
            // 如果缩略图生成失败，不进行处理
            // 可以添加日志记录
        }
    }

    /**
     * 下载远程文件并保存到媒体库
     *
     * @param string   $url        远程文件URL
     * @param string   $title      文件标题
     * @param int|null $authorId   作者ID
     * @param string   $authorType 作者类型
     *
     * @return array
     */
    public function downloadRemoteFile(string $url, string $title = '', ?int $authorId = null, string $authorType = 'admin'): array
    {
        try {
            PluginService::do_action('media.download_start', [
                'url' => $url,
                'title' => $title,
            ]);

            // 验证URL
            if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
                return ['code' => 400, 'msg' => '无效的URL地址'];
            }

            // 获取文件名和扩展名
            $parsedUrl = parse_url($url);
            $path = $parsedUrl['path'] ?? '';
            $originalName = basename($path);

            if (empty($originalName)) {
                $originalName = 'downloaded_file';
            }

            // 清理文件名
            $filename = preg_replace('/[^a-zA-Z0-9._-]/', '-', $originalName);
            $fileExtension = pathinfo($filename, PATHINFO_EXTENSION);

            // 如果没有扩展名，尝试从Content-Type获取
            if (empty($fileExtension)) {
                $headers = get_headers($url, 1);
                $contentType = $headers['Content-Type'] ?? '';
                if ($contentType) {
                    $mimeToExtension = [
                        'image/jpeg' => 'jpg',
                        'image/png' => 'png',
                        'image/gif' => 'gif',
                        'image/webp' => 'webp',
                        'application/pdf' => 'pdf',
                        'application/msword' => 'doc',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                    ];
                    $fileExtension = $mimeToExtension[$contentType] ?? '';
                }
            }

            // 创建上传目录
            $uploadDir = public_path('uploads');
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0o755, true);
            }

            // 按年月创建子目录
            $subDir = date('Y/m');
            $fullUploadDir = $uploadDir . '/' . $subDir;
            if (!is_dir($fullUploadDir)) {
                mkdir($fullUploadDir, 0o755, true);
            }

            // 生成唯一文件名
            $uniqueFilename = time() . '_' . uniqid() . '.' . ($fileExtension ?: 'bin');
            $filePath = $fullUploadDir . DIRECTORY_SEPARATOR . $uniqueFilename;

            // 下载文件
            $context = stream_context_create([
                'http' => [
                    'timeout' => 30, // 30秒超时
                    'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ]);

            $fileContent = @file_get_contents($url, false, $context);
            if ($fileContent === false) {
                return ['code' => 400, 'msg' => '文件下载失败'];
            }

            // 保存文件
            if (file_put_contents($filePath, $fileContent) === false) {
                return ['code' => 500, 'msg' => '文件保存失败'];
            }

            // 获取文件信息
            $fileSize = filesize($filePath);
            $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';

            // 检查文件类型是否允许
            if (!$this->isAllowedFile($mimeType, $fileExtension)) {
                unlink($filePath); // 删除不允许的文件

                return ['code' => 400, 'msg' => '不支持的文件类型'];
            }

            // 检查文件大小
            $config = config('media', []);
            $maxSize = $config['max_file_size'] ?? (10 * 1024 * 1024);
            if ($fileSize > $maxSize) {
                unlink($filePath); // 删除过大的文件

                return ['code' => 400, 'msg' => '文件大小超过限制'];
            }

            // 保存到媒体表
            $media = new Media();
            $media->filename = $uniqueFilename;
            $media->original_name = $title ?: $originalName;
            $media->file_path = $subDir . '/' . $uniqueFilename;
            $media->file_size = $fileSize;
            $media->mime_type = $mimeType;
            $media->author_id = $authorId;
            $media->author_type = $authorType;
            $media->alt_text = '';
            $media->caption = '';
            $media->description = '';
            $media->save();

            // 如果是图片且不是webp格式，转换为webp
            if ($this->isImageMimeType($media->mime_type) && $media->mime_type !== 'image/webp') {
                $webpResult = $this->convertToWebp($media);
                if ($webpResult['code'] === 0) {
                    // 更新媒体信息
                    $media->filename = $webpResult['data']['filename'];
                    $media->file_path = $webpResult['data']['file_path'];
                    $media->file_size = $webpResult['data']['file_size'];
                    $media->mime_type = 'image/webp';
                    $media->save();
                }
            }

            // 为图片生成缩略图
            if ($this->isImageMimeType($media->mime_type)) {
                $this->generateThumbnail($media);
            }

            PluginService::do_action('media.download_done', [
                'id' => $media->id ?? null,
                'url' => $url,
                'path' => $media->file_path ?? null,
            ]);

            return ['code' => 0, 'msg' => '文件下载成功', 'data' => $media];
        } catch (Exception $e) {
            return ['code' => 1, 'msg' => '下载失败: ' . $e->getMessage()];
        }
    }

    /**
     * 将图片转换为 favicon.ico 格式
     *
     * @param string $sourceImagePath 源图片路径
     * @param string $outputPath 输出路径
     *
     * @return bool 是否成功
     */
    public function generateFavicon(string $sourceImagePath, string $outputPath): bool
    {
        try {
            // 检查源文件是否存在
            if (!file_exists($sourceImagePath)) {
                return false;
            }

            // 优先使用 Imagick（如果可用）
            if (extension_loaded('imagick')) {
                return $this->generateFaviconWithImagick($sourceImagePath, $outputPath);
            }

            // 否则使用 GD
            return $this->generateFaviconWithGD($sourceImagePath, $outputPath);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * 使用 Imagick 生成 favicon.ico
     *
     * @param string $sourceImagePath 源图片路径
     * @param string $outputPath 输出路径
     *
     * @return bool 是否成功
     */
    private function generateFaviconWithImagick(string $sourceImagePath, string $outputPath): bool
    {
        try {
            // 创建 Imagick 实例
            $imagickClass = 'Imagick';
            $imagick = new $imagickClass();
            $imagick->readImage($sourceImagePath);

            // 设置格式为 ICO
            $imagick->setImageFormat('ico');

            // 设置多尺寸
            $faviconSizes = [16, 32, 48];
            $imagick->setImageCompressionQuality(90);

            // 写入文件
            $result = $imagick->writeImages($outputPath, true);
            $imagick->destroy();

            return $result;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * 使用 GD 生成 favicon.ico
     *
     * @param string $sourceImagePath 源图片路径
     * @param string $outputPath 输出路径
     *
     * @return bool 是否成功
     */
    private function generateFaviconWithGD(string $sourceImagePath, string $outputPath): bool
    {
        try {
            // 获取图片信息
            $imageInfo = getimagesize($sourceImagePath);
            if (!$imageInfo) {
                return false;
            }

            // 根据 MIME 类型创建图像资源
            $srcImage = null;
            switch ($imageInfo[2]) {
                case IMAGETYPE_JPEG:
                    $srcImage = imagecreatefromjpeg($sourceImagePath);
                    break;
                case IMAGETYPE_PNG:
                    $srcImage = imagecreatefrompng($sourceImagePath);
                    break;
                case IMAGETYPE_GIF:
                    $srcImage = imagecreatefromgif($sourceImagePath);
                    break;
                case IMAGETYPE_WEBP:
                    if (function_exists('imagecreatefromwebp')) {
                        $srcImage = imagecreatefromwebp($sourceImagePath);
                    }
                    break;
                default:
                    return false;
            }

            if (!$srcImage) {
                return false;
            }

            // 创建 favicon 图像（通常为 16x16 和 32x32）
            $faviconSizes = [16, 32];
            $faviconImages = [];

            foreach ($faviconSizes as $size) {
                $faviconImage = imagecreatetruecolor($size, $size);

                // 处理透明背景
                if ($imageInfo[2] == IMAGETYPE_PNG || $imageInfo[2] == IMAGETYPE_GIF) {
                    imagealphablending($faviconImage, false);
                    imagesavealpha($faviconImage, true);
                    $transparent = imagecolorallocatealpha($faviconImage, 0, 0, 0, 127);
                    imagefilledrectangle($faviconImage, 0, 0, $size, $size, $transparent);
                }

                // 调整图像大小
                imagecopyresampled(
                    $faviconImage,
                    $srcImage,
                    0,
                    0,
                    0,
                    0,
                    $size,
                    $size,
                    $imageInfo[0],
                    $imageInfo[1]
                );

                $faviconImages[] = $faviconImage;
            }

            // 保存为 ICO 格式
            $result = $this->saveAsIco($faviconImages, $outputPath);

            // 释放资源
            imagedestroy($srcImage);
            foreach ($faviconImages as $faviconImage) {
                imagedestroy($faviconImage);
            }

            return $result;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * 将图像保存为 ICO 格式
     *
     * @param array $images 图像资源数组
     * @param string $filename 保存路径
     *
     * @return bool 是否成功
     */
    private function saveAsIco(array $images, string $filename): bool
    {
        try {
            // 创建 ICO 文件
            $icoData = '';

            // ICONDIR 头部 (6 bytes)
            $icoData .= pack('v', 0);          // 保留字段，必须为0
            $icoData .= pack('v', 1);          // 图标类型，1表示图标
            $icoData .= pack('v', count($images)); // 图标数量

            $imageDataOffset = 6 + count($images) * 16; // ICONDIR + 所有 ICONDIRENTRY 的大小
            $imageDataParts = [];

            // 为每个图像创建 ICONDIRENTRY
            foreach ($images as $index => $image) {
                $width = imagesx($image);
                $height = imagesy($image);

                // BITMAPINFOHEADER (40 bytes)
                $bmpHeader = pack(
                    'V3v2V6',
                    40,                     // biSize
                    $width,                 // biWidth
                    $height * 2,            // biHeight (ICO要求高度翻倍)
                    1,                      // biPlanes
                    32,                     // biBitCount
                    0,                      // biCompression
                    0,                      // biSizeImage
                    0,                      // biXPelsPerMeter
                    0,                      // biYPelsPerMeter
                    0,                      // biClrUsed
                    0                       // biClrImportant
                );

                // 图像数据 (BGRA 格式)
                $imageData = '';
                for ($y = $height - 1; $y >= 0; $y--) {
                    for ($x = 0; $x < $width; $x++) {
                        $rgb = imagecolorat($image, $x, $y);
                        $alpha = ($rgb >> 24) & 0x7F;
                        $r = ($rgb >> 16) & 0xFF;
                        $g = ($rgb >> 8) & 0xFF;
                        $b = $rgb & 0xFF;

                        // ICO 使用 0 表示不透明，255 表示完全透明
                        $alpha = $alpha == 127 ? 255 : (127 - $alpha) * 2;

                        $imageData .= pack('C4', $b, $g, $r, $alpha);
                    }

                    // 行对齐到 4 字节边界
                    $padding = (4 - (strlen($imageData) % 4)) % 4;
                    if ($padding > 0) {
                        $imageData .= str_repeat("\x00", $padding);
                    }
                }

                // XOR 掩码（全为 0）
                $maskSize = ((($width + 31) >> 5) << 2) * $height;
                $maskData = str_repeat("\x00", $maskSize);

                // AND 掩码（全为 0）
                $andMaskSize = ((($width + 31) >> 5) << 2) * $height;
                $andMaskData = str_repeat("\x00", $andMaskSize);

                // 组合图像数据
                $imageDataPart = $bmpHeader . $imageData . $maskData . $andMaskData;
                $imageDataParts[] = $imageDataPart;

                // ICONDIRENTRY 目录项 (16 bytes)
                $icoData .= pack('C', $width ?: 256);   // 宽度 (0表示256)
                $icoData .= pack('C', $height ?: 256);  // 高度 (0表示256)
                $icoData .= pack('C', 0);          // 调色板颜色数
                $icoData .= pack('C', 0);          // 保留字段
                $icoData .= pack('v', 1);          // 颜色平面数
                $icoData .= pack('v', 32);         // 每像素位数
                $icoData .= pack('V', strlen($imageDataPart)); // 图像大小
                $icoData .= pack('V', $imageDataOffset); // 图像数据偏移

                $imageDataOffset += strlen($imageDataPart);
            }

            // 添加所有图像数据
            foreach ($imageDataParts as $imageDataPart) {
                $icoData .= $imageDataPart;
            }

            // 写入文件
            return file_put_contents($filename, $icoData) !== false;
        } catch (Exception $e) {
            return false;
        }
    }
}
