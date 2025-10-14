<?php

/**
 * 媒体库配置文件
 *
 * 配置允许上传的文件类型和危险文件类型
 */

return [
    /**
     * 允许上传的文件类型白名单
     * 格式：['mime_type' => ['ext1', 'ext2', ...]]
     */
    'allowed_types' => [
        // 图片类型
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/gif' => ['gif'],
        'image/webp' => ['webp'],
        'image/svg+xml' => ['svg'],
        'image/bmp' => ['bmp'],
        'image/tiff' => ['tif', 'tiff'],

        // 视频类型
        'video/mp4' => ['mp4'],
        'video/mpeg' => ['mpeg', 'mpg'],
        'video/quicktime' => ['mov'],
        'video/x-msvideo' => ['avi'],
        'video/x-flv' => ['flv'],
        'video/webm' => ['webm'],

        // 音频类型
        'audio/mpeg' => ['mp3'],
        'audio/wav' => ['wav'],
        'audio/ogg' => ['ogg'],
        'audio/x-m4a' => ['m4a'],
        'audio/flac' => ['flac'],

        // 文档类型
        'application/pdf' => ['pdf'],
        'application/msword' => ['doc'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'application/vnd.ms-excel' => ['xls'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
        'application/vnd.ms-powerpoint' => ['ppt'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx'],

        // 文本类型
        'text/plain' => ['txt', 'md', 'log', 'ini', 'conf'],
        'text/csv' => ['csv'],
        'text/html' => ['html', 'htm'],
        'text/css' => ['css'],
        'text/javascript' => ['js'],
        'application/json' => ['json'],
        'application/xml' => ['xml'],

        // 压缩文件
        'application/zip' => ['zip'],
        'application/x-rar-compressed' => ['rar'],
        'application/x-7z-compressed' => ['7z'],
        'application/x-tar' => ['tar'],
        'application/gzip' => ['gz'],
    ],

    /**
     * 危险文件类型黑名单
     * 这些文件类型将被完全禁止上传
     */
    'dangerous_types' => [
        // 可执行文件
        'application/x-php' => ['php', 'php3', 'php4', 'php5', 'php7', 'phtml'],
        'application/x-httpd-php' => ['php', 'php3', 'php4', 'php5', 'php7', 'phtml'],
        'application/x-perl' => ['pl', 'pm', 'cgi'],
        'application/x-python' => ['py', 'pyc', 'pyo'],
        'application/x-ruby' => ['rb'],
        'application/x-shellscript' => ['sh', 'bash', 'zsh', 'csh', 'ksh'],
        'application/x-executable' => ['exe', 'dll', 'so', 'dylib'],

        // 配置文件
        'application/x-config' => ['conf', 'config', 'cfg'],

        // 数据库文件
        'application/x-sql' => ['sql'],
        'application/x-database' => ['db', 'sqlite', 'mdb'],
    ],

    /**
     * 文件大小限制（字节）
     */
    'max_file_size' => 50 * 1024 * 1024, // 50MB

    /**
     * 图片处理配置
     */
    'image' => [
        // 是否自动转换为webp格式
        'convert_to_webp' => true,

        // 缩略图尺寸
        'thumbnail_size' => [200, 200],

        // 图片质量
        'quality' => 90,
    ],

    /**
     * 文本文件预览配置
     */
    'text_preview' => [
        // 最大预览文件大小（字节）
        'max_preview_size' => 2 * 1024 * 1024, // 2MB

        // 支持编辑的文件类型
        'editable_types' => [
            'text/plain',
            'text/html',
            'text/css',
            'text/javascript',
            'application/json',
            'application/xml',
        ],

        // Monaco Editor 配置
        'monaco_editor' => [
            'enabled' => true,
            'theme' => 'vs-dark',
            'font_size' => 14,
            'word_wrap' => true,
        ],
    ],

    /**
     * 存储配置
     */
    'storage' => [
        // 上传目录
        'upload_path' => public_path('uploads'),

        // 缩略图目录
        'thumbnail_path' => public_path('uploads/thumbs'),

        // 文件命名策略
        'naming_strategy' => 'date', // date, random, original
    ],
];
