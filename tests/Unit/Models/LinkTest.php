<?php

namespace Tests\Unit\Models;

use app\model\Link;
use PHPUnit\Framework\TestCase;

class LinkTest extends TestCase
{
    /**
     * 测试链接软删除功能
     */
    public function testSoftDelete()
    {
        // 创建一个测试链接
        $link = new Link();
        $link->name = 'Test Link ' . time() . ' ' . rand(1000, 9999);
        $link->url = 'https://example-' . time() . '-' . rand(1000, 9999) . '.com';
        $link->description = 'This is a test link';
        $link->status = true;
        $link->save();

        // 确保链接创建成功
        $this->assertNotNull($link->id);
        $this->assertNull($link->deleted_at);

        // 执行软删除
        $result = $link->softDelete();

        // 检查软删除结果
        $this->assertTrue($result);

        // 重新加载链接检查deleted_at字段
        $updatedLink = Link::withTrashed()->find($link->id);
        $this->assertNotNull($updatedLink->deleted_at);
        $this->assertNotEmpty($updatedLink->deleted_at);
    }

    /**
     * 测试恢复软删除的链接
     */
    public function testRestore()
    {
        // 创建一个测试链接
        $link = new Link();
        $link->name = 'Test Link Restore ' . time() . ' ' . rand(1000, 9999);
        $link->url = 'https://example-restore-' . time() . '-' . rand(1000, 9999) . '.com';
        $link->description = 'This is a test link for restore';
        $link->status = true;
        $link->save();

        // 先执行软删除
        $link->softDelete();

        // 确认链接已被软删除
        $deletedLink = Link::withTrashed()->find($link->id);
        $this->assertNotNull($deletedLink->deleted_at);

        // 执行恢复操作
        $result = $deletedLink->restore();

        // 检查恢复结果
        $this->assertTrue($result);

        // 重新加载链接检查deleted_at字段
        $restoredLink = Link::find($link->id);
        $this->assertNull($restoredLink->deleted_at);
    }
}
