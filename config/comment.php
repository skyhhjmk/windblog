<?php

/**
 * 评论系统配置参考
 *
 * 注意：本文件仅作为配置参考，实际配置请在数据库 settings 表中设置
 * 配置通过 blog_config() 函数从数据库读取，可在后台动态修改
 *
 * 配置项说明：
 *
 * 1. 评论长度限制
 *    - comment_min_length: 评论最小长度（字符数），默认 2
 *    - comment_max_length: 评论最大长度（字符数），默认 1000
 *
 * 2. 内容限制
 *    - comment_max_urls: URL 最大数量（防止垃圾评论），默认 3
 *    - comment_max_quote_length: 引用文本最大长度（字符数），默认 200
 *
 * 3. 评论频率限制
 *    - comment_duplicate_window: 重复评论检查时间窗口（秒），默认 300 (5分钟)
 *    - comment_frequency_window: 评论频率限制时间窗口（秒），默认 60 (1分钟)
 *    - comment_max_frequency: 时间窗口内最大评论数，默认 3
 *
 * 4. 审核配置
 *    - comment_moderation: 是否需要审核，默认 true
 *
 * 5. AI 审核配置
 *    - comment_ai_moderation_enabled: 是否启用 AI 审核，默认 false
 *    - comment_ai_moderation_priority: AI 审核优先级，默认 5
 *
 * 示例 SQL 插入语句（PostgreSQL）：
 *
 * INSERT INTO settings (key, value, type, created_at, updated_at) VALUES
 * ('comment_min_length', '2', 'integer', NOW(), NOW()),
 * ('comment_max_length', '1000', 'integer', NOW(), NOW()),
 * ('comment_max_urls', '3', 'integer', NOW(), NOW()),
 * ('comment_max_quote_length', '200', 'integer', NOW(), NOW()),
 * ('comment_duplicate_window', '300', 'integer', NOW(), NOW()),
 * ('comment_frequency_window', '60', 'integer', NOW(), NOW()),
 * ('comment_max_frequency', '3', 'integer', NOW(), NOW()),
 * ('comment_moderation', 'true', 'boolean', NOW(), NOW()),
 * ('comment_ai_moderation_enabled', 'false', 'boolean', NOW(), NOW()),
 * ('comment_ai_moderation_priority', '5', 'integer', NOW(), NOW())
 * ON CONFLICT (key) DO NOTHING;
 *
 * 或通过后台管理界面设置进行配置
 */

return [
    // 默认值仅供参考，实际使用 blog_config() 从数据库读取
    'comment_min_length' => 2,
    'comment_max_length' => 1000,
    'comment_max_urls' => 3,
    'comment_max_quote_length' => 200,
    'comment_duplicate_window' => 300,
    'comment_frequency_window' => 60,
    'comment_max_frequency' => 3,
    'comment_moderation' => true,
    'comment_ai_moderation_enabled' => false,
    'comment_ai_moderation_priority' => 5,
];
