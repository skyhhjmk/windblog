<?php

namespace Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use app\model\Post;
use app\model\Author;
use app\model\Category;
use app\model\Link;
use app\model\Media;

class SoftDeleteTest extends TestCase
{
    /**
     * 测试所有模型的软删除功能
     */
    public function testAllModelsSoftDelete()
    {
        // 测试Post模型
        $post = new Post();
        $post->title = 'Test Post ' . time() . ' ' . rand(1000, 9999);
        $post->slug = 'test-post-' . time() . '-' . rand(1000, 9999);
        $post->content = 'This is a test post.';
        $post->status = 'published';
        $post->save();
        
        $this->assertNull($post->deleted_at);
        $result = $post->softDelete();
        $this->assertTrue($result);
        
        $updatedPost = Post::withTrashed()->find($post->id);
        $this->assertNotNull($updatedPost->deleted_at);
        
        // 测试Author模型
        $author = new Author();
        $author->username = 'testuser_' . time() . '_' . rand(1000, 9999);
        $author->nickname = 'Test User';
        $author->password = 'password123';
        $author->email = 'test_' . time() . '_' . rand(1000, 9999) . '@example.com';
        $author->save();
        
        $this->assertNull($author->deleted_at);
        $result = $author->softDelete();
        $this->assertTrue($result);
        
        $updatedAuthor = Author::withTrashed()->find($author->id);
        $this->assertNotNull($updatedAuthor->deleted_at);
        
        // 测试Category模型
        $category = new Category();
        $category->name = 'Test Category ' . time() . ' ' . rand(1000, 9999);
        $category->slug = 'test-category-' . time() . '-' . rand(1000, 9999);
        $category->save();
        
        $this->assertNull($category->deleted_at);
        $result = $category->softDelete();
        $this->assertTrue($result);
        
        $updatedCategory = Category::withTrashed()->find($category->id);
        $this->assertNotNull($updatedCategory->deleted_at);
        
        // 测试Link模型
        $link = new Link();
        $link->name = 'Test Link ' . time() . ' ' . rand(1000, 9999);
        $link->url = 'https://example-' . time() . '-' . rand(1000, 9999) . '.com';
        $link->status = true;
        $link->save();
        
        $this->assertNull($link->deleted_at);
        $result = $link->softDelete();
        $this->assertTrue($result);
        
        $updatedLink = Link::withTrashed()->find($link->id);
        $this->assertNotNull($updatedLink->deleted_at);
        
        // 测试Media模型
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
        
        $this->assertNull($media->deleted_at);
        $result = $media->softDelete();
        $this->assertTrue($result);
        
        $updatedMedia = Media::withTrashed()->find($media->id);
        $this->assertNotNull($updatedMedia->deleted_at);
    }
    
    /**
     * 测试所有模型的恢复功能
     */
    public function testAllModelsRestore()
    {
        // 测试Post模型恢复
        $post = Post::withTrashed()->whereNotNull('deleted_at')->first();
        if ($post) {
            $result = $post->restore();
            $this->assertTrue($result);
            $restoredPost = Post::find($post->id);
            $this->assertNull($restoredPost->deleted_at);
        }
        
        // 测试Author模型恢复
        $author = Author::withTrashed()->whereNotNull('deleted_at')->first();
        if ($author) {
            $result = $author->restore();
            $this->assertTrue($result);
            $restoredAuthor = Author::find($author->id);
            $this->assertNull($restoredAuthor->deleted_at);
        }
        
        // 测试Category模型恢复
        $category = Category::withTrashed()->whereNotNull('deleted_at')->first();
        if ($category) {
            $result = $category->restore();
            $this->assertTrue($result);
            $restoredCategory = Category::find($category->id);
            $this->assertNull($restoredCategory->deleted_at);
        }
        
        // 测试Link模型恢复
        $link = Link::withTrashed()->whereNotNull('deleted_at')->first();
        if ($link) {
            $result = $link->restore();
            $this->assertTrue($result);
            $restoredLink = Link::find($link->id);
            $this->assertNull($restoredLink->deleted_at);
        }
        
        // 测试Media模型恢复
        $media = Media::withTrashed()->whereNotNull('deleted_at')->first();
        if ($media) {
            $result = $media->restore();
            $this->assertTrue($result);
            $restoredMedia = Media::find($media->id);
            $this->assertNull($restoredMedia->deleted_at);
        }
    }
}