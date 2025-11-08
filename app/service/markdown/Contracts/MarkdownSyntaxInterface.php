<?php

namespace app\service\markdown\Contracts;

use League\CommonMark\Environment\Environment;

/**
 * 自定义 Markdown 语法扩展接口（用于后期扩展）
 *
 * 实现该接口即可将自定义语法/渲染器注册到 CommonMark 环境中。
 */
interface MarkdownSyntaxInterface
{
    /**
     * 返回扩展名称（用于日志与调试，可选但推荐唯一）。
     */
    public function name(): string;

    /**
     * 将扩展注册到 CommonMark 环境。
     */
    public function register(Environment $environment): void;
}
