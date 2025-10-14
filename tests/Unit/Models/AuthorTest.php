<?php

namespace Tests\Unit\Models;

use app\model\Author;
use PHPUnit\Framework\TestCase;

class AuthorTest extends TestCase
{
    /**
     * 测试作者软删除功能
     */
    public function testSoftDelete()
    {
        // 创建一个测试作者
        $author = new Author();
        $author->username = 'testuser_' . time() . '_' . rand(1000, 9999);
        $author->nickname = 'Test User';
        $author->password = 'password123';
        $author->email = 'test_' . time() . '_' . rand(1000, 9999) . '@example.com';
        $author->save();

        // 确保作者创建成功
        $this->assertNotNull($author->id);
        $this->assertNull($author->deleted_at);

        // 执行软删除
        $result = $author->softDelete();

        // 检查软删除结果
        $this->assertTrue($result);

        // 重新加载作者检查deleted_at字段
        $updatedAuthor = Author::withTrashed()->find($author->id);
        $this->assertNotNull($updatedAuthor->deleted_at);
        $this->assertNotEmpty($updatedAuthor->deleted_at);
    }

    /**
     * 测试恢复软删除的作者
     */
    public function testRestore()
    {
        // 创建一个测试作者
        $author = new Author();
        $author->username = 'testuser_restore_' . time() . '_' . rand(1000, 9999);
        $author->nickname = 'Test User Restore';
        $author->password = 'password123';
        $author->email = 'test_restore_' . time() . '_' . rand(1000, 9999) . '@example.com';
        $author->save();

        // 先执行软删除
        $author->softDelete();

        // 确认作者已被软删除
        $deletedAuthor = Author::withTrashed()->find($author->id);
        $this->assertNotNull($deletedAuthor->deleted_at);

        // 执行恢复操作
        $result = $deletedAuthor->restore();

        // 检查恢复结果
        $this->assertTrue($result);

        // 重新加载作者检查deleted_at字段
        $restoredAuthor = Author::find($author->id);
        $this->assertNull($restoredAuthor->deleted_at);
    }
}
