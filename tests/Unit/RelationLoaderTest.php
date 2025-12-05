<?php

namespace Tests\Unit;

use app\util\RelationLoader;
use Illuminate\Database\Eloquent\Collection;
use Mockery;
use PHPUnit\Framework\TestCase;

class RelationLoaderTest extends TestCase
{
    public function testLoadPostRelations()
    {
        $posts = Mockery::mock(Collection::class);
        $posts->shouldReceive('isEmpty')->andReturn(false);
        $posts->shouldReceive('load')
            ->with(['authors', 'primaryAuthor', 'categories', 'tags', 'featuredImage'])
            ->once()
            ->andReturn($posts);

        RelationLoader::loadPostRelations($posts);

        $this->assertTrue(true);
    }

    public function testLoadCommentRelations()
    {
        $comments = Mockery::mock(Collection::class);
        $comments->shouldReceive('isEmpty')->andReturn(false);
        $comments->shouldReceive('load')
            ->with(['post', 'user', 'parent'])
            ->once()
            ->andReturn($comments);

        RelationLoader::loadCommentRelations($comments);

        $this->assertTrue(true);
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }
}
