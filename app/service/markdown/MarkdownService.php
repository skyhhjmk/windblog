<?php

namespace app\service\markdown;

use app\service\markdown\Contracts\MarkdownSyntaxInterface;
use app\service\markdown\Extension\SBlock\SBlockExtension;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Exception\CommonMarkException;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\DefaultAttributes\DefaultAttributesExtension;
use League\CommonMark\Extension\DescriptionList\DescriptionListExtension;
use League\CommonMark\Extension\Footnote\FootnoteExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\TaskList\TaskListExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Markdown 渲染服务
 * - 支持基本语法渲染为 HTML
 * - 尽可能贴近 Vditor（Lute）的渲染风格：启用 GFM/脚注/任务列表/表格等，并输出锚点
 * - 可配置容器 class 与内联样式
 * - 提供语法接口用于后期扩展（MarkdownSyntaxInterface）
 */
class MarkdownService
{
    /** @var Environment */
    protected Environment $environment;

    /** @var MarkdownConverter */
    protected MarkdownConverter $converter;

    /** @var array 默认配置 */
    protected array $options = [
        // 安全与渲染参数（默认贴近 Vditor：不输出原始 HTML，禁用不安全链接）
        'html_input' => 'strip',
        'allow_unsafe_links' => false,
        'max_nesting_level' => 20,
        'renderer' => [
            'soft_break' => "\n",
        ],
        // HeadingPermalink 配置，生成与 Vditor 兼容的锚点结构
        'heading_permalink' => [
            'html_class' => 'vditor-anchor',
            'id_prefix' => 'vditorAnchor-',
            'apply_id_to_heading' => true,
            'insert' => 'before',
            'symbol' => '',
            'min_heading_level' => 1,
            'max_heading_level' => 6,
        ],
        'commonmark' => [
            'enable_em' => true,
            'enable_strong' => true,
            'use_asterisk' => true,
            'use_underscore' => true,
            'unordered_list_markers' => ['-', '+', '*'],
        ],
    ];

    /** 自定义容器 class（用于挂自定义样式） */
    protected string $cssClass = 'vditor-reset';

    /** 需要内联注入的 CSS 内容（可为空） */
    protected ?string $inlineCss = null;

    /**
     * @param array|null $config 支持传入 config('markdown') 的覆盖项
     */
    public function __construct(?array $config = null)
    {
        // 合并来自 config/markdown.php 的配置
        $mdConfig = config('markdown', []);
        if (is_array($mdConfig) && $mdConfig) {
            $this->options = array_replace_recursive($this->options, $mdConfig['options'] ?? []);
            $this->cssClass = (string) ($mdConfig['css_class'] ?? $this->cssClass);
            $this->inlineCss = $mdConfig['inject_css'] ?? null;
        }
        if (is_array($config) && $config) {
            $this->options = array_replace_recursive($this->options, $config['options'] ?? []);
            $this->cssClass = (string) ($config['css_class'] ?? $this->cssClass);
            $this->inlineCss = $config['inject_css'] ?? $this->inlineCss;
        }

        $this->environment = new Environment($this->options);

        // 注册基础扩展
        $this->environment->addExtension(new CommonMarkCoreExtension());
        $this->environment->addExtension(new AutolinkExtension());
        $this->environment->addExtension(new StrikethroughExtension());
        $this->environment->addExtension(new TableExtension());
        $this->environment->addExtension(new TaskListExtension());
        // 贴近 Vditor 的扩展
        $this->environment->addExtension(new FootnoteExtension());
        $this->environment->addExtension(new HeadingPermalinkExtension());
        $this->environment->addExtension(new DefaultAttributesExtension());
        $this->environment->addExtension(new DescriptionListExtension());
        $this->environment->addExtension(new SBlockExtension());

        // 通过配置自动注册自定义扩展（可选）
        $this->registerConfiguredSyntaxExtensions();

        $this->converter = new MarkdownConverter($this->environment);
    }

    /**
     * 读取配置并自动注册扩展（类需实现 MarkdownSyntaxInterface）
     */
    protected function registerConfiguredSyntaxExtensions(): void
    {
        $mdConfig = config('markdown', []);
        $exts = $mdConfig['extensions'] ?? [];
        if (!is_array($exts)) {
            return;
        }
        foreach ($exts as $extClass) {
            if (is_string($extClass) && class_exists($extClass)) {
                $instance = new $extClass();
                if ($instance instanceof MarkdownSyntaxInterface) {
                    $instance->register($this->environment);
                }
            }
        }
    }

    /**
     * 运行时添加自定义语法扩展
     */
    public function addSyntaxExtension(MarkdownSyntaxInterface $extension): void
    {
        $extension->register($this->environment);
        // 变更环境后，保持 converter 引用最新环境
        $this->converter = new MarkdownConverter($this->environment);
    }

    /**
     * 渲染 Markdown 为 HTML
     *
     * 支持的 $options:
     * - wrap: 是否包裹容器，默认 true
     * - css_class: 自定义容器 class
     * - inject_css: 需要内联注入的 CSS 字符串
     *
     * @throws CommonMarkException
     */
    public function render(string $markdown, array $options = []): string
    {
        $wrap = $options['wrap'] ?? true;
        $cssClass = (string) ($options['css_class'] ?? $this->cssClass);
        $injectCss = array_key_exists('inject_css', $options) ? $options['inject_css'] : $this->inlineCss;

        $html = (string) $this->converter->convert($markdown);

        // 包裹容器
        if ($wrap) {
            $html = '<div class="' . htmlspecialchars($cssClass, ENT_QUOTES, 'UTF-8') . '">' . $html . '</div>';
        }

        // 注入内联 CSS（如有）
        if (is_string($injectCss) && $injectCss !== '') {
            $style = '<style>' . $injectCss . '</style>';
            $html = $style . $html;
        }

        return $html;
    }
}
