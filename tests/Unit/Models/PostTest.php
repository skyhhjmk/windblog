<?php

namespace Tests\Unit\Models;

use app\model\Post;
use PHPUnit\Framework\TestCase;

class PostTest extends TestCase
{
    /**
     * 测试软删除功能
     */
    public function testSoftDelete()
    {
        // 创建一个测试文章
        $post = new Post();
        $post->title = 'Test Post for Soft Delete ' . time() . ' ' . rand(1000, 9999);
        $post->slug = 'test-post-soft-delete-' . time() . '-' . rand(1000, 9999);
        $post->content = 'This is a test post for soft delete functionality.';
        $post->status = 'published';
        $post->save();

        // 确保文章创建成功
        $this->assertNotNull($post->id);
        $this->assertNull($post->deleted_at);

        // 执行软删除
        $result = $post->softDelete();

        // 检查软删除结果
        $this->assertTrue($result);

        // 重新加载文章检查deleted_at字段
        $updatedPost = Post::withTrashed()->find($post->id);
        $this->assertNotNull($updatedPost->deleted_at);
        $this->assertNotEmpty($updatedPost->deleted_at);
    }

    /**
     * 测试恢复软删除的文章
     */
    public function testRestore()
    {
        // 创建一个测试文章
        $post = new Post();
        $post->title = 'Test Post for Restore ' . time() . ' ' . rand(1000, 9999);
        $post->slug = 'test-post-restore-' . time() . '-' . rand(1000, 9999);
        $post->content = 'This is a test post for restore functionality.';
        $post->status = 'published';
        $post->save();

        // 先执行软删除
        $post->softDelete();

        // 确认文章已被软删除
        $deletedPost = Post::withTrashed()->find($post->id);
        $this->assertNotNull($deletedPost->deleted_at);

        // 执行恢复操作
        $result = $deletedPost->restore();

        // 检查恢复结果
        $this->assertTrue($result);

        // 重新加载文章检查deleted_at字段
        $restoredPost = Post::find($post->id);
        $this->assertNull($restoredPost->deleted_at);
    }

    /**
     * 测试强制删除功能
     */
    public function testForceDelete()
    {
        // 创建一个测试文章
        $post = new Post();
        $post->title = 'Test Post for Force Delete ' . time() . ' ' . rand(1000, 9999);
        $post->slug = 'test-post-force-delete-' . time() . '-' . rand(1000, 9999);
        $post->content = 'This is a test post for force delete functionality.';
        $post->status = 'published';
        $post->save();

        // 确保文章创建成功
        $this->assertNotNull($post->id);

        // 执行强制删除
        $result = $post->softDelete(true); // true表示强制删除

        // 检查删除结果
        $this->assertTrue($result);

        // 检查文章是否真的被删除
        $deletedPost = Post::withTrashed()->find($post->id);
        $this->assertNull($deletedPost);
    }
}
