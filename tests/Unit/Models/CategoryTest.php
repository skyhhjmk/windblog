<?php

namespace Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use app\model\Category;

class CategoryTest extends TestCase
{
    /**
     * 测试分类软删除功能
     */
    public function testSoftDelete()
    {
        // 创建一个测试分类
        $category = new Category();
        $category->name = 'Test Category ' . time() . ' ' . rand(1000, 9999);
        $category->slug = 'test-category-' . time() . '-' . rand(1000, 9999);
        $category->description = 'This is a test category';
        $category->save();
        
        // 确保分类创建成功
        $this->assertNotNull($category->id);
        $this->assertNull($category->deleted_at);
        
        // 执行软删除
        $result = $category->softDelete();
        
        // 检查软删除结果
        $this->assertTrue($result);
        
        // 重新加载分类检查deleted_at字段
        $updatedCategory = Category::withTrashed()->find($category->id);
        $this->assertNotNull($updatedCategory->deleted_at);
        $this->assertNotEmpty($updatedCategory->deleted_at);
    }
    
    /**
     * 测试恢复软删除的分类
     */
    public function testRestore()
    {
        // 创建一个测试分类
        $category = new Category();
        $category->name = 'Test Category Restore ' . time() . ' ' . rand(1000, 9999);
        $category->slug = 'test-category-restore-' . time() . '-' . rand(1000, 9999);
        $category->description = 'This is a test category for restore';
        $category->save();
        
        // 先执行软删除
        $category->softDelete();
        
        // 确认分类已被软删除
        $deletedCategory = Category::withTrashed()->find($category->id);
        $this->assertNotNull($deletedCategory->deleted_at);
        
        // 执行恢复操作
        $result = $deletedCategory->restore();
        
        // 检查恢复结果
        $this->assertTrue($result);
        
        // 重新加载分类检查deleted_at字段
        $restoredCategory = Category::find($category->id);
        $this->assertNull($restoredCategory->deleted_at);
    }
}