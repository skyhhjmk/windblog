<?php

return [
    // 容器的默认 class，可配合自定义 CSS 使用
    'css_class' => 'markdown-body',

    // 需要注入到页面的内联 CSS（可为空字符串或 null）
    'inject_css' => '',

    // CommonMark 环境配置项（会与内置默认项合并）
    'options' => [
        'html_input' => 'allow', // allow|strip|escape
        'allow_unsafe_links' => false,
        'max_nesting_level' => 20,
        'renderer' => [
            'soft_break' => "\n",
        ],
        'commonmark' => [
            'enable_em' => true,
            'enable_strong' => true,
            'use_asterisk' => true,
            'use_underscore' => true,
            'unordered_list_markers' => ['-', '+', '*'],
        ],
    ],

    // 自定义语法扩展（类名列表），类需实现
    // app\service\markdown\Contracts\MarkdownSyntaxInterface
    'extensions' => [
        // 例如: app\service\markdown\extensions\YourCustomExtension::class,
    ],
];
