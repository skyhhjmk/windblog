<?php

namespace Tests\Unit\Services;

use app\service\URLHelper;
use PHPUnit\Framework\TestCase;

class URLHelperTest extends TestCase
{
    public function testGeneratePostUrlWithAndWithoutHtmlSuffix(): void
    {
        $this->assertSame('/post/hello-world.html', URLHelper::generatePostUrl('hello-world'));
        $this->assertSame('/post/hello-world', URLHelper::generatePostUrl('hello-world', false));
    }

    public function testGeneratePostUrlEncodesSlug(): void
    {
        $slug = '测试 文章';
        $url = URLHelper::generatePostUrl($slug);

        $this->assertStringStartsWith('/post/', $url);
        $this->assertStringContainsString('.html', $url);
        // 应当进行 URL 编码，不包含空格原文
        $this->assertStringNotContainsString(' ', $url);
    }

    public function testGeneratePageUrl(): void
    {
        $this->assertSame('/page/about.html', URLHelper::generatePageUrl('about'));
        $this->assertSame('/page/about', URLHelper::generatePageUrl('about', false));
    }

    public function testGenerateCategoryUrlUsesRawUrlEncodeAndAvoidsDoubleEncoding(): void
    {
        $url = URLHelper::generateCategoryUrl('分类 名称');
        // rawurlencode 使用 %20 而不是 +
        $this->assertStringContainsString('%20', $url);
        $this->assertStringNotContainsString('+', $url);

        // 已经编码的 slug 不应再次编码
        $encodedSlug = 'hello%20world';
        $url2 = URLHelper::generateCategoryUrl($encodedSlug);
        $this->assertStringContainsString('/category/' . $encodedSlug, $url2);
    }

    public function testGenerateTagUrlUsesRawUrlEncode(): void
    {
        $url = URLHelper::generateTagUrl('标签 名称');

        $this->assertStringStartsWith('/tag/', $url);
        $this->assertStringContainsString('%20', $url);
        $this->assertStringContainsString('.html', $url);
    }

    public function testGenerateSearchUrlWithAndWithoutHtmlSuffix(): void
    {
        $url = URLHelper::generateSearchUrl('php unit test');
        $this->assertSame('/search?q=php+unit+test', $url);

        $urlWithSuffix = URLHelper::generateSearchUrl('php unit test', true);
        $this->assertSame('/search?q=php+unit+test.html', $urlWithSuffix);
    }

    public function testRemoveHtmlSuffix(): void
    {
        $this->assertSame('/post/hello', URLHelper::removeHtmlSuffix('/post/hello.html'));
        $this->assertSame('/post/hello', URLHelper::removeHtmlSuffix('/post/hello'));
    }

    public function testHasHtmlSuffix(): void
    {
        $this->assertTrue(URLHelper::hasHtmlSuffix('/post/hello.html'));
        $this->assertFalse(URLHelper::hasHtmlSuffix('/post/hello'));
    }

    public function testEnsureHtmlSuffix(): void
    {
        $this->assertSame('/post/hello.html', URLHelper::ensureHtmlSuffix('/post/hello'));
        $this->assertSame('/post/hello.html', URLHelper::ensureHtmlSuffix('/post/hello.html'));
    }
}
