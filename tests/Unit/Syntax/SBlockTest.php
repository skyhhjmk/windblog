<?php

namespace Tests\Unit\Syntax;

use app\service\markdown\MarkdownService;
use PHPUnit\Framework\TestCase;

class SBlockTest extends TestCase
{
    protected MarkdownService $markdown;

    public function testInfoBlock()
    {
        $markdown = "::info\nThis is an info block\n::end";
        $html = $this->markdown->render($markdown, ['wrap' => false]);

        $this->assertStringContainsString('<div class="s-block s-info"', $html);
        $this->assertStringContainsString('This is an info block', $html);
    }

    public function testGridBlock()
    {
        $markdown = "::grid 3\n- Item 1\n- Item 2\n::end";
        $html = $this->markdown->render($markdown, ['wrap' => false]);

        $this->assertStringContainsString('<div class="s-block s-grid"', $html);
        $this->assertStringContainsString('style="--cols: 3"', $html);
        $this->assertStringContainsString('<li>Item 1</li>', $html);
    }

    public function testCardBlockWithParams()
    {
        $markdown = "::card title=\"Card Title\"\nCard content\n::end";
        $html = $this->markdown->render($markdown, ['wrap' => false]);

        $this->assertStringContainsString('<div class="s-block s-card"', $html);
        $this->assertStringContainsString('data-title="Card Title"', $html);
        $this->assertStringContainsString('Card content', $html);
    }

    public function testNestedBlocks()
    {
        $markdown = "::card\n::info\nNested info\n::end\n::end";
        $html = $this->markdown->render($markdown, ['wrap' => false]);

        $this->assertStringContainsString('<div class="s-block s-card"', $html);
        $this->assertStringContainsString('<div class="s-block s-info"', $html);
        $this->assertStringContainsString('Nested info', $html);
    }

    protected function setUp(): void
    {
        $this->markdown = new MarkdownService();
    }
}
