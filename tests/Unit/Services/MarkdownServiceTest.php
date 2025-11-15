<?php

namespace Tests\Unit\Services;

use app\service\markdown\Contracts\MarkdownSyntaxInterface;
use app\service\markdown\MarkdownService;
use League\CommonMark\Environment\Environment;
use PHPUnit\Framework\TestCase;

class MarkdownServiceTest extends TestCase
{
    /**
     * 测试默认渲染行为：
     * - 使用 vditor-reset 容器 class
     * - 保留基础 Markdown 语法（标题、加粗）
     */
    public function testRenderWithDefaultOptions(): void
    {
        $service = new MarkdownService();

        $markdown = "# 标题\n\n**加粗文本**";
        $html = $service->render($markdown);

        // 包裹容器 class
        $this->assertStringContainsString('class="vditor-reset"', $html);

        // 标题和加粗内容应被渲染
        $this->assertStringContainsString('<h1', $html);
        $this->assertStringContainsString('标题', $html);
        $this->assertStringContainsString('<strong>', $html);
        $this->assertStringContainsString('加粗文本', $html);

        // HeadingPermalink 扩展应生效（包含锚点 class）
        $this->assertStringContainsString('vditor-anchor', $html);
    }

    /**
     * 测试关闭容器包裹时，输出不包含外层 div
     */
    public function testRenderWithoutWrapper(): void
    {
        $service = new MarkdownService();

        $markdown = '**bold**';
        $html = $service->render($markdown, [
            'wrap' => false,
        ]);

        // 不应出现默认容器 class
        $this->assertStringNotContainsString('class="vditor-reset"', $html);

        // 仍然应该正常渲染 Markdown 内容
        $this->assertStringContainsString('<strong>', $html);
        $this->assertStringContainsString('bold', $html);
    }

    /**
     * 测试通过构造函数和 render 选项覆盖 css_class 与内联 CSS
     */
    public function testRenderWithCustomCssClassAndInlineCss(): void
    {
        $service = new MarkdownService([
            'css_class' => 'custom-md-class',
            'inject_css' => 'body { background: #fff; }',
        ]);

        $markdown = '内容';
        $html = $service->render($markdown);

        // 使用自定义容器 class
        $this->assertStringContainsString('class="custom-md-class"', $html);

        // 自动注入内联 CSS
        $this->assertStringContainsString('<style>body { background: #fff; }</style>', $html);

        // 内容仍然被渲染
        $this->assertStringContainsString('内容', $html);

        // 再次调用时可以覆盖注入的 CSS
        $htmlOverride = $service->render($markdown, [
            'inject_css' => '.markdown { color: red; }',
        ]);

        $this->assertStringContainsString('<style>.markdown { color: red; }</style>', $htmlOverride);
    }

    /**
     * 测试运行时添加自定义语法扩展
     * 这里不关心真正的 Markdown 解析逻辑，只验证扩展的 register 被调用
     */
    public function testAddSyntaxExtensionRegistersExtension(): void
    {
        $service = new MarkdownService();
        $extension = new class () implements MarkdownSyntaxInterface {
            public bool $registered = false;

            public ?Environment $environment = null;

            public function name(): string
            {
                return 'dummy-extension';
            }

            public function register(Environment $environment): void
            {
                $this->registered = true;
                $this->environment = $environment;
            }
        };

        $service->addSyntaxExtension($extension);

        $this->assertTrue($extension->registered, '扩展的 register 方法应被调用');
        $this->assertInstanceOf(Environment::class, $extension->environment);

        // 调用 render，确保使用的是更新后的 environment/converter，不抛异常即可
        $html = $service->render('测试');
        $this->assertNotEmpty($html);
    }
}
