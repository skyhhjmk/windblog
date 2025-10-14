<?php

namespace Tests\Unit\Models;

use app\model\Media;
use PHPUnit\Framework\TestCase;

class MediaTest extends TestCase
{
    /**
     * 测试媒体软删除功能
     */
    public function testSoftDelete()
    {
        // 创建一个测试媒体
        $media = new Media();
        $filename = 'test_' . time() . '_' . rand(1000, 9999) . '.jpg';
        $media->filename = $filename;
        $media->original_name = 'test-original_' . time() . '_' . rand(1000, 9999) . '.jpg';
        $media->file_path = 'images/' . $filename;
        $media->file_size = 1024;
        $media->mime_type = 'image/jpeg';
        $media->author_id = 1;
        $media->author_type = 'admin';
        $media->save();

        // 确保媒体创建成功
        $this->assertNotNull($media->id);
        $this->assertNull($media->deleted_at);

        // 执行软删除
        $result = $media->softDelete();

        // 检查软删除结果
        $this->assertTrue($result);

        // 重新加载媒体检查deleted_at字段
        $updatedMedia = Media::withTrashed()->find($media->id);
        $this->assertNotNull($updatedMedia->deleted_at);
        $this->assertNotEmpty($updatedMedia->deleted_at);
    }

    /**
     * 测试恢复软删除的媒体
     */
    public function testRestore()
    {
        // 创建一个测试媒体
        $media = new Media();
        $filename = 'test-restore_' . time() . '_' . rand(1000, 9999) . '.jpg';
        $media->filename = $filename;
        $media->original_name = 'test-original-restore_' . time() . '_' . rand(1000, 9999) . '.jpg';
        $media->file_path = 'images/' . $filename;
        $media->file_size = 1024;
        $media->mime_type = 'image/jpeg';
        $media->author_id = 1;
        $media->author_type = 'admin';
        $media->save();

        // 先执行软删除
        $media->softDelete();

        // 确认媒体已被软删除
        $deletedMedia = Media::withTrashed()->find($media->id);
        $this->assertNotNull($deletedMedia->deleted_at);

        // 执行恢复操作
        $result = $deletedMedia->restore();

        // 检查恢复结果
        $this->assertTrue($result);

        // 重新加载媒体检查deleted_at字段
        $restoredMedia = Media::find($media->id);
        $this->assertNull($restoredMedia->deleted_at);
    }
}
