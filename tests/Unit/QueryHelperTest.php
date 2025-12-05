<?php

namespace Tests\Unit;

use app\util\QueryHelper;
use Illuminate\Database\Eloquent\Builder;
use Mockery;
use PHPUnit\Framework\TestCase;

class QueryHelperTest extends TestCase
{
    public function testLikeInsensitive()
    {
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('whereRaw')
            ->with('LOWER(title) LIKE ?', ['%test%'])
            ->once()
            ->andReturnSelf();

        QueryHelper::likeInsensitive($query, 'title', 'test');

        $this->assertTrue(true); // Assertion handled by Mockery expectations
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }
}
