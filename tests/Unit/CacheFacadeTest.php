<?php

namespace Tests\Unit;

use app\util\CacheFacade;
use PHPUnit\Framework\TestCase;

class CacheFacadeTest extends TestCase
{
    public function testGetAndSet()
    {
        $key = 'test_key';
        $value = 'test_value';

        CacheFacade::set($key, $value);
        $this->assertEquals($value, CacheFacade::get($key));
    }

    public function testTagging()
    {
        if (getenv('CACHE_DRIVER') !== 'redis') {
            $this->markTestSkipped('Redis driver not enabled');
        }

        $key1 = 'key1';
        $key2 = 'key2';
        $tag = 'test_tag';

        CacheFacade::set($key1, 'value1', null, [$tag]);
        CacheFacade::set($key2, 'value2', null, [$tag]);

        $this->assertEquals('value1', CacheFacade::get($key1));
        $this->assertEquals('value2', CacheFacade::get($key2));

        CacheFacade::invalidateTags([$tag]);

        $this->assertEmpty(CacheFacade::get($key1));
        $this->assertEmpty(CacheFacade::get($key2));
    }
}
