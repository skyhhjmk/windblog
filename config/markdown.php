<?php

return [
    // 容器的默认 class，采用 Vditor 的重置样式以统一前后台风格
    'css_class' => 'vditor-reset',

    // 需要注入到页面的内联 CSS（可为空字符串或 null）
    'inject_css' => '',

    // CommonMark 环境配置项（会与内置默认项合并）
    'options' => [
        // 贴近 Vditor 的默认行为：不输出原始 HTML、禁用不安全链接
        'html_input' => 'strip', // allow|strip|escape
        'allow_unsafe_links' => false,
        'max_nesting_level' => 20,
        'renderer' => [
            'soft_break' => "\n",
        ],
        // 生成标题锚点，类名与 ID 前缀对齐 Vditor
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
    ],

    // 自定义语法扩展（类名列表），类需实现
    // app\service\markdown\Contracts\MarkdownSyntaxInterface
    'extensions' => [
        // 例如: app\service\markdown\extensions\YourCustomExtension::class,
    ],
];
