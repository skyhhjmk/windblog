-- MySQL数据库初始化脚本

-- 创建用户表
CREATE TABLE IF NOT EXISTS `wa_users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `username` varchar(32) NOT NULL COMMENT '用户名',
  `nickname` varchar(40) NOT NULL COMMENT '昵称',
  `password` varchar(255) NOT NULL COMMENT '密码',
  `sex` enum('0','1') NOT NULL DEFAULT '1' COMMENT '性别',
  `avatar` varchar(255) DEFAULT NULL COMMENT '头像',
  `email` varchar(128) DEFAULT NULL COMMENT '邮箱',
  `mobile` varchar(16) DEFAULT NULL COMMENT '手机',
  `level` tinyint(4) NOT NULL DEFAULT '0' COMMENT '等级',
  `birthday` date DEFAULT NULL COMMENT '生日',
  `money` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '余额(元)',
  `score` int(11) NOT NULL DEFAULT '0' COMMENT '积分',
  `last_time` datetime DEFAULT NULL COMMENT '登录时间',
  `last_ip` varchar(50) DEFAULT NULL COMMENT '登录ip',
  `join_time` datetime DEFAULT NULL COMMENT '注册时间',
  `join_ip` varchar(50) DEFAULT NULL COMMENT '注册ip',
  `token` varchar(50) DEFAULT NULL COMMENT 'token',
    ` email_verified_at ` datetime DEFAULT NULL COMMENT '邮箱验证时间',
    ` activation_token ` varchar
(
    64
) DEFAULT NULL COMMENT '激活令牌',
    ` activation_token_expires_at ` datetime DEFAULT NULL COMMENT '激活令牌过期时间',
    ` password_reset_token ` varchar
(
    255
) DEFAULT NULL COMMENT '密码重置令牌',
    ` password_reset_expire ` datetime DEFAULT NULL COMMENT '密码重置令牌过期时间',
    ` timezone ` varchar
(
    50
) DEFAULT 'UTC' COMMENT '用户时区',
  `created_at` datetime DEFAULT NULL COMMENT '创建时间',
  `updated_at` datetime DEFAULT NULL COMMENT '更新时间',
  `role` int(11) NOT NULL DEFAULT '1' COMMENT '角色',
  `status` tinyint(4) NOT NULL DEFAULT '0' COMMENT '禁用',
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `join_time` (`join_time`),
  KEY `mobile` (`mobile`),
    KEY ` email `
(
    `
    email
    `
),
    KEY ` idx_activation_token `
(
    `
    activation_token
    `
)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户表';

-- 为用户表添加密码重置索引
CREATE INDEX ` idx_password_reset_token ` ON ` wa_users `(` password_reset_token `);

-- 创建用户OAuth绑定表
CREATE TABLE IF NOT EXISTS ` user_oauth_bindings `
(
    `
    id
    `
    bigint
(
    20
) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
    ` user_id ` int
(
    10
) unsigned NOT NULL COMMENT '用户ID',
    ` provider ` varchar
(
    50
) NOT NULL COMMENT 'OAuth提供商(github, google, wechat等)',
    ` provider_user_id ` varchar
(
    255
) NOT NULL COMMENT 'OAuth提供商的用户ID',
    ` provider_username ` varchar
(
    255
) DEFAULT NULL COMMENT 'OAuth提供商的用户名',
    ` provider_email ` varchar
(
    255
) DEFAULT NULL COMMENT 'OAuth提供商的邮箱',
    ` provider_avatar ` varchar
(
    500
) DEFAULT NULL COMMENT 'OAuth提供商的头像URL',
    ` access_token ` text DEFAULT NULL COMMENT '访问令牌(加密存储)',
    ` refresh_token ` text DEFAULT NULL COMMENT '刷新令牌(加密存储)',
    ` expires_at ` datetime DEFAULT NULL COMMENT '令牌过期时间',
    ` extra_data ` json DEFAULT NULL COMMENT '额外数据(JSON格式)',
    ` created_at ` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT '绑定时间',
    ` updated_at ` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY
(
    `
    id
    `
),
    UNIQUE KEY ` unique_provider_user `
(
    `
    provider
    `,
    `
    provider_user_id
    `
),
    KEY ` idx_user_id `
(
    `
    user_id
    `
),
    KEY ` idx_provider `
(
    `
    provider
    `
),
    CONSTRAINT ` user_oauth_bindings_user_id_foreign ` FOREIGN KEY
(
    `
    user_id
    `
) REFERENCES ` wa_users `
(
    `
    id
    `
)
                                                            ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE =utf8mb4_unicode_ci COMMENT ='用户OAuth绑定表';

-- 创建分类表
CREATE TABLE IF NOT EXISTS `categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `categories_parent_id_foreign` (`parent_id`),
  CONSTRAINT `categories_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='分类表';

-- 创建文章表
CREATE TABLE IF NOT EXISTS `posts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `content_type` varchar(10) NOT NULL DEFAULT 'markdown',
  `content` longtext NOT NULL,
  `excerpt` text DEFAULT NULL,
    ` ai_summary ` longtext DEFAULT NULL COMMENT 'AI 摘要',
    ` seo_title ` varchar(255) DEFAULT NULL COMMENT '自定义 SEO 标题，如果为空则使用文章标题',
    ` seo_description ` text DEFAULT NULL COMMENT '自定义 SEO 描述，如果为空则使用摘要',
    ` seo_keywords ` varchar(500) DEFAULT NULL COMMENT '自定义 SEO 关键词，逗号分隔',
  `status` varchar(15) NOT NULL DEFAULT 'draft',
  `visibility` varchar(20) NOT NULL DEFAULT 'public' COMMENT '可见性: public=公开, private=私密, password=密码保护',
  `password` varchar(255) DEFAULT NULL COMMENT '文章密码（当visibility=password时使用）',
  `featured` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否精选文章',
  `allow_comments` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否允许评论',
  `comment_count` int(11) NOT NULL DEFAULT 0,
  `published_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_featured` (`featured`),
  KEY `idx_visibility` (`visibility`),
  KEY `idx_allow_comments` (`allow_comments`),
  CONSTRAINT `chk_posts_content_type` CHECK (`content_type` IN ('markdown','html','text','visual')),
  CONSTRAINT `chk_posts_status` CHECK (`status` IN ('draft','published','archived')),
  CONSTRAINT `chk_posts_visibility` CHECK (`visibility` IN ('public','private','password'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文章表';

-- 创建文章-分类关联表
CREATE TABLE IF NOT EXISTS `post_category` (
  `post_id` bigint(20) unsigned NOT NULL,
  `category_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`post_id`,`category_id`),
  KEY `post_category_post_id_foreign` (`post_id`),
  KEY `post_category_category_id_foreign` (`category_id`),
  CONSTRAINT `post_category_post_id_foreign` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `post_category_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文章-分类关联表';

-- 创建文章-作者关联表
CREATE TABLE IF NOT EXISTS `post_author` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` bigint(20) unsigned NOT NULL,
  `author_id` int(11) DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `contribution` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `post_id` (`post_id`,`author_id`),
  KEY `post_author_post_id_foreign` (`post_id`),
  KEY `post_author_author_id_foreign` (`author_id`),
  CONSTRAINT `post_author_post_id_foreign` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `post_author_author_id_foreign` FOREIGN KEY (`author_id`) REFERENCES `wa_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文章-作者关联表';

-- 创建友链表
CREATE TABLE IF NOT EXISTS `links` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `url` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `icon` varchar(255) DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `target` varchar(20) DEFAULT '_blank',
  `redirect_type` varchar(10) NOT NULL DEFAULT 'info',
  `show_url` tinyint(1) NOT NULL DEFAULT 1,
  `content` longtext DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `seo_title` varchar(255) DEFAULT NULL,
  `seo_keywords` varchar(255) DEFAULT NULL,
  `seo_description` varchar(255) DEFAULT NULL,
  `custom_fields` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY
(
    `
    id
    `
),
    KEY ` idx_custom_fields_ai_audit_status `
(
    (
    CAST (
    JSON_EXTRACT
(
    `
    custom_fields
    `,
    '$.ai_audit_status'
) AS CHAR
(
    20
)))),
    KEY ` idx_custom_fields_last_audit_time `
(
    (
    CAST (
    JSON_EXTRACT
(
    `
    custom_fields
    `,
    '$.last_audit_time'
) AS CHAR
(
    20
)))),
    KEY ` idx_custom_fields_last_monitor_time `
(
    (
    CAST (
    JSON_EXTRACT
(
    `
    custom_fields
    `,
    '$.last_monitor_time'
) AS CHAR
(
    20
))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='友链表';

-- 创建浮动链接表（FloLink）
CREATE TABLE IF NOT EXISTS `flo_links` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '浮动链接ID',
  `keyword` varchar(255) NOT NULL COMMENT '关键词',
  `url` varchar(500) NOT NULL COMMENT '目标链接地址',
  `title` varchar(255) DEFAULT NULL COMMENT '链接标题(用于悬浮窗显示)',
  `description` text DEFAULT NULL COMMENT '链接描述(用于悬浮窗显示)',
  `image` varchar(500) DEFAULT NULL COMMENT '图片URL(用于悬浮窗显示)',
  `priority` int(11) DEFAULT 100 COMMENT '优先级(数字越小优先级越高)',
  `match_mode` enum('first', 'all') DEFAULT 'first' COMMENT '匹配模式: first=仅替换首次出现, all=替换所有',
  `case_sensitive` tinyint(1) DEFAULT 0 COMMENT '是否区分大小写',
  `replace_existing` tinyint(1) DEFAULT 1 COMMENT '是否替换已有链接(智能替换aff等)',
  `target` varchar(20) DEFAULT '_blank' COMMENT '打开方式',
  `rel` varchar(100) DEFAULT 'noopener noreferrer' COMMENT 'rel属性',
  `css_class` varchar(100) DEFAULT 'flo-link' COMMENT 'CSS类名',
  `enable_hover` tinyint(1) DEFAULT 1 COMMENT '是否启用悬浮窗',
  `hover_delay` int(11) DEFAULT 200 COMMENT '悬浮窗延迟显示时间(毫秒)',
  `status` tinyint(1) DEFAULT 1 COMMENT '状态: 1=启用, 0=禁用',
  `sort_order` int(11) DEFAULT 999 COMMENT '排序权重',
  `custom_fields` json DEFAULT NULL COMMENT '自定义字段(JSON格式)',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `deleted_at` timestamp NULL DEFAULT NULL COMMENT '软删除时间',
  PRIMARY KEY (`id`),
  KEY `idx_keyword` (`keyword`),
  KEY `idx_status` (`status`),
  KEY `idx_priority` (`priority`),
  KEY `idx_sort_order` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='FloLink浮动链接表';

-- 创建页面表
CREATE TABLE IF NOT EXISTS `pages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `content` longtext NOT NULL,
  `status` varchar(15) NOT NULL DEFAULT 'draft',
  `template` varchar(50) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='页面表';

-- 创建网站设置表
CREATE TABLE IF NOT EXISTS `settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) NOT NULL,
  `value` json DEFAULT NULL,
  `group` varchar(50) DEFAULT 'general',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='网站设置表';

-- 创建媒体附件表
CREATE TABLE IF NOT EXISTS `media` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `filename` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `file_path` varchar(512) NOT NULL,
  `thumb_path` varchar(500) DEFAULT NULL,
  `file_size` int(11) NOT NULL DEFAULT 0,
  `mime_type` varchar(100) NOT NULL,
  `alt_text` varchar(255) DEFAULT NULL,
  `caption` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `author_id` int(11) DEFAULT NULL,
  `author_type` varchar(10) DEFAULT 'user',
    ` custom_fields ` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='媒体附件表';

-- 创建导入任务表
CREATE TABLE IF NOT EXISTS `import_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `type` varchar(50) NOT NULL,
  `file_path` varchar(512) NOT NULL,
  `status` varchar(15) NOT NULL DEFAULT 'pending',
  `options` text DEFAULT NULL,
  `progress` int(11) NOT NULL DEFAULT 0,
  `message` text DEFAULT NULL,
  `author_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `chk_import_jobs_status` CHECK (`status` IN ('pending','processing','completed','failed')),
  CONSTRAINT `import_jobs_author_id_foreign` FOREIGN KEY (`author_id`) REFERENCES `wa_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='导入任务表';

-- 创建评论表
CREATE TABLE IF NOT EXISTS `comments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` bigint(20) unsigned NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  `guest_name` varchar(255) DEFAULT NULL,
  `guest_email` varchar(255) DEFAULT NULL,
  `content` text NOT NULL,
  `quoted_data` text DEFAULT NULL COMMENT '引用数据(JSON格式,包含被引用评论的ID、作者、内容等信息)',
  `status` varchar(10) NOT NULL DEFAULT 'pending',
    ` ai_moderation_result ` varchar
(
    20
) DEFAULT NULL COMMENT 'AI审核结果：approved/rejected/spam/pending',
    ` ai_moderation_reason ` text DEFAULT NULL COMMENT 'AI审核原因',
    ` ai_moderation_confidence ` decimal
(
    3,
    2
) DEFAULT NULL COMMENT 'AI审核置信度(0-1)',
    ` ai_moderation_categories ` json DEFAULT NULL COMMENT 'AI检测到的问题类别',
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `chk_comments_status` CHECK (`status` IN ('pending','approved','spam','trash')),
  CONSTRAINT `comments_post_id_foreign` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `comments_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `wa_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `comments_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `comments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='评论表';

-- 创建标签表
CREATE TABLE IF NOT EXISTS `tags` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='标签表';

-- 创建文章-标签关联表
CREATE TABLE IF NOT EXISTS `post_tag` (
  `post_id` bigint(20) unsigned NOT NULL,
  `tag_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`post_id`,`tag_id`),
  KEY `post_tag_post_id_foreign` (`post_id`),
  KEY `post_tag_tag_id_foreign` (`tag_id`),
  CONSTRAINT `post_tag_post_id_foreign` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `post_tag_tag_id_foreign` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文章-标签关联表';

-- 创建管理员角色关联表
CREATE TABLE IF NOT EXISTS `wa_admin_roles` (
  `role_id` int(11) NOT NULL COMMENT '角色id',
  `admin_id` int(11) NOT NULL COMMENT '管理员id',
  UNIQUE KEY `role_admin_id` (`role_id`,`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理员角色关联表';

-- 创建管理员表
CREATE TABLE IF NOT EXISTS `wa_admins` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `username` varchar(32) NOT NULL COMMENT '用户名',
  `nickname` varchar(40) NOT NULL COMMENT '昵称',
  `password` varchar(255) NOT NULL COMMENT '密码',
  `avatar` varchar(255) DEFAULT '/app/admin/avatar.png' COMMENT '头像',
  `email` varchar(100) DEFAULT NULL COMMENT '邮箱',
  `mobile` varchar(16) DEFAULT NULL COMMENT '手机',
  `created_at` datetime DEFAULT NULL COMMENT '创建时间',
  `updated_at` datetime DEFAULT NULL COMMENT '更新时间',
  `login_at` datetime DEFAULT NULL COMMENT '登录时间',
  `status` tinyint(4) DEFAULT NULL COMMENT '禁用',
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理员表';

-- 创建选项表
CREATE TABLE IF NOT EXISTS `wa_options` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(128) NOT NULL COMMENT '键',
  `value` longtext NOT NULL COMMENT '值',
  `created_at` datetime NOT NULL DEFAULT '2022-08-15 00:00:00' COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT '2022-08-15 00:00:00' COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='选项表';

-- 创建管理员角色表
CREATE TABLE IF NOT EXISTS `wa_roles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name` varchar(80) NOT NULL COMMENT '角色组',
  `rules` text COMMENT '权限',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  `pid` int(10) unsigned DEFAULT NULL COMMENT '父级',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理员角色';

-- 创建权限规则表
CREATE TABLE IF NOT EXISTS `wa_rules` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `title` varchar(255) NOT NULL COMMENT '标题',
  `icon` varchar(255) DEFAULT NULL COMMENT '图标',
  `key` varchar(255) NOT NULL COMMENT '标识',
  `pid` int(10) unsigned DEFAULT 0 COMMENT '上级菜单',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  `href` varchar(255) DEFAULT NULL COMMENT 'url',
  `type` int(11) NOT NULL DEFAULT 1 COMMENT '类型',
  `weight` int(11) DEFAULT 0 COMMENT '排序',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='权限规则表';

-- 创建附件表
CREATE TABLE IF NOT EXISTS `wa_uploads` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name` varchar(128) NOT NULL COMMENT '名称',
  `url` varchar(255) NOT NULL COMMENT '文件',
  `admin_id` int(11) DEFAULT NULL COMMENT '管理员',
  `file_size` int(11) NOT NULL COMMENT '文件大小',
  `mime_type` varchar(255) NOT NULL COMMENT 'mime类型',
  `image_width` int(11) DEFAULT NULL COMMENT '图片宽度',
  `image_height` int(11) DEFAULT NULL COMMENT '图片高度',
  `ext` varchar(128) NOT NULL COMMENT '扩展名',
  `storage` varchar(255) NOT NULL DEFAULT 'local' COMMENT '存储位置',
  `category` varchar(128) DEFAULT NULL COMMENT '类别',
  `created_at` date DEFAULT NULL COMMENT '上传时间',
  `updated_at` date DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `category` (`category`),
  KEY `admin_id` (`admin_id`),
  KEY `name` (`name`),
  KEY `ext` (`ext`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='附件表';

-- 创建posts_ext表
CREATE TABLE IF NOT EXISTS `post_ext` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` bigint(20) unsigned NOT NULL,
  `key` varchar(255) NOT NULL,
  `value` json NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `posts_ext_post_id_foreign` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文章扩展表';

-- 添加索引
CREATE INDEX `idx_categories_status` ON `categories` (`status`);
CREATE INDEX `idx_categories_deleted_at` ON `categories` (`deleted_at`);

CREATE INDEX `idx_posts_status` ON `posts` (`status`);
CREATE INDEX `idx_posts_featured` ON `posts` (`featured`);
CREATE INDEX `idx_posts_published_at` ON `posts` (`published_at`);
CREATE INDEX `idx_posts_deleted_at` ON `posts` (`deleted_at`);

CREATE INDEX `idx_links_status` ON `links` (`status`);
CREATE INDEX `idx_links_sort_order` ON `links` (`sort_order`);
CREATE INDEX `idx_links_deleted_at` ON `links` (`deleted_at`);

CREATE INDEX `idx_pages_deleted_at` ON `pages` (`deleted_at`);

CREATE INDEX `idx_settings_group` ON `settings` (`group`);
CREATE INDEX `idx_settings_value` ON `settings` ((cast(`value` as char(255) array)));

CREATE INDEX `idx_media_author_id` ON `media` (`author_id`);
CREATE INDEX `idx_media_author_type` ON `media` (`author_type`);
CREATE INDEX `idx_media_filename` ON `media` (`filename`);
CREATE INDEX `idx_media_mime_type` ON `media` (`mime_type`);
CREATE INDEX `idx_media_deleted_at` ON `media` (`deleted_at`);

CREATE INDEX `idx_import_jobs_status` ON `import_jobs` (`status`);
CREATE INDEX `idx_import_jobs_author_id` ON `import_jobs` (`author_id`);

CREATE INDEX `idx_comments_post_id` ON `comments` (`post_id`);
CREATE INDEX `idx_comments_user_id` ON `comments` (`user_id`);
CREATE INDEX `idx_comments_parent_id` ON `comments` (`parent_id`);
CREATE INDEX `idx_comments_status` ON `comments` (`status`);
CREATE INDEX `idx_comments_deleted_at` ON `comments` (`deleted_at`);

CREATE INDEX `idx_tags_deleted_at` ON `tags` (`deleted_at`);

CREATE INDEX `idx_post_ext_id` ON `post_ext` (`id`);
CREATE INDEX `idx_post_ext_key` ON `post_ext` (`key`);

CREATE INDEX `idx_wa_uploads_category` ON `wa_uploads` (`category`);
CREATE INDEX `idx_wa_uploads_admin_id` ON `wa_uploads` (`admin_id`);
CREATE INDEX `idx_wa_uploads_name` ON `wa_uploads` (`name`);
CREATE INDEX `idx_wa_uploads_ext` ON `wa_uploads` (`ext`);

-- 插入预定义表数据
INSERT INTO `wa_options` (`id`, `name`, `value`, `created_at`, `updated_at`) VALUES
(1, 'system_config', '{\"logo\":{\"title\":\"Webman Admin\",\"image\":\"\\/app\\/admin\\/admin\\/images\\/logo.png\"},\"menu\":{\"data\":\"\\/app\\/admin\\/rule\\/get\",\"method\":\"GET\",\"accordion\":true,\"collapse\":false,\"control\":false,\"controlWidth\":500,\"select\":\"0\",\"async\":true},\"tab\":{\"enable\":true,\"keepState\":true,\"preload\":false,\"session\":true,\"max\":\"30\",\"index\":{\"id\":\"0\",\"href\":\"\\/app\\/admin\\/index\\/dashboard\",\"title\":\"\\u4eea\\u8868\\u76d8\"}},\"theme\":{\"defaultColor\":\"2\",\"defaultMenu\":\"light-theme\",\"defaultHeader\":\"light-theme\",\"allowCustom\":true,\"banner\":false},\"colors\":[{\"id\":\"1\",\"color\":\"#36b368\",\"second\":\"#f0f9eb\"},{\"id\":\"2\",\"color\":\"#2d8cf0\",\"second\":\"#ecf5ff\"},{\"id\":\"3\",\"color\":\"#f6ad55\",\"second\":\"#fdf6ec\"},{\"id\":\"4\",\"color\":\"#f56c6c\",\"second\":\"#fef0f0\"},{\"id\":\"5\",\"color\":\"#3963bc\",\"second\":\"#ecf5ff\"}],\"other\":{\"keepLoad\":\"500\",\"autoHead\":false,\"footer\":false},\"header\":{\"message\":false}}', '2022-12-05 14:49:01', '2022-12-08 20:20:28'),
(2, 'table_form_schema_wa_users', '{\"id\":{\"field\":\"id\",\"_field_id\":\"0\",\"comment\":\"主键\",\"control\":\"inputNumber\",\"control_args\":\"\",\"list_show\":true,\"enable_sort\":true,\"searchable\":true,\"search_type\":\"normal\",\"form_show\":false},\"username\":{\"field\":\"username\",\"_field_id\":\"1\",\"comment\":\"用户名\",\"control\":\"input\",\"control_args\":\"\",\"form_show\":true,\"list_show\":true,\"searchable\":true,\"search_type\":\"normal\",\"enable_sort\":false},\"nickname\":{\"field\":\"nickname\",\"_field_id\":\"2\",\"comment\":\"昵称\",\"control\":\"input\",\"control_args\":\"\",\"form_show\":true,\"list_show\":true,\"searchable\":true,\"search_type\":\"normal\",\"enable_sort\":false},\"password\":{\"field\":\"password\",\"_field_id\":\"3\",\"comment\":\"密码\",\"control\":\"input\",\"control_args\":\"\",\"form_show\":true,\"search_type\":\"normal\",\"list_show\":false,\"enable_sort\":false,\"searchable\":false},\"sex\":{\"field\":\"sex\",\"_field_id\":\"4\",\"comment\":\"性别\",\"control\":\"select\",\"control_args\":\"url:\\/app\\/admin\\/dict\\/get\\/sex\",\"form_show\":true,\"list_show\":true,\"searchable\":true,\"search_type\":\"normal\",\"enable_sort\":false},\"avatar\":{\"field\":\"avatar\",\"_field_id\":\"5\",\"comment\":\"头像\",\"control\":\"uploadImage\",\"control_args\":\"url:\\/app\\/admin\\/upload\\/avatar\",\"form_show\":true,\"list_show\":true,\"search_type\":\"normal\",\"enable_sort\":false,\"searchable\":false},\"email\":{\"field\":\"email\",\"_field_id\":\"6\",\"comment\":\"邮箱\",\"control\":\"input\",\"control_args\":\"\",\"form_show\":true,\"list_show\":true,\"searchable\":true,\"search_type\":\"normal\",\"enable_sort\":false},\"mobile\":{\"field\":\"mobile\",\"_field_id\":\"7\",\"comment\":\"手机\",\"control\":\"input\",\"control_args\":\"\",\"form_show\":true,\"list_show\":true,\"searchable\":true,\"search_type\":\"normal\",\"enable_sort\":false},\"level\":{\"field\":\"level\",\"_field_id\":\"8\",\"comment\":\"等级\",\"control\":\"inputNumber\",\"control_args\":\"\",\"form_show\":true,\"searchable\":true,\"search_type\":\"normal\",\"list_show\":false,\"enable_sort\":false},\"birthday\":{\"field\":\"birthday\",\"_field_id\":\"9\",\"comment\":\"生日\",\"control\":\"datePicker\",\"control_args\":\"\",\"form_show\":true,\"searchable\":true,\"search_type\":\"between\",\"list_show\":false,\"enable_sort\":false},\"money\":{\"field\":\"money\",\"_field_id\":\"10\",\"comment\":\"余额(元)\",\"control\":\"inputNumber\",\"control_args\":\"\",\"form_show\":true,\"searchable\":true,\"search_type\":\"normal\",\"list_show\":false,\"enable_sort\":false},\"score\":{\"field\":\"score\",\"_field_id\":\"11\",\"comment\":\"积分\",\"control\":\"inputNumber\",\"control_args\":\"\",\"form_show\":true,\"searchable\":true,\"search_type\":\"normal\",\"list_show\":false,\"enable_sort\":false},\"last_time\":{\"field\":\"last_time\",\"_field_id\":\"12\",\"comment\":\"登录时间\",\"control\":\"dateTimePicker\",\"control_args\":\"\",\"form_show\":true,\"searchable\":true,\"search_type\":\"between\",\"list_show\":false,\"enable_sort\":false},\"last_ip\":{\"field\":\"last_ip\",\"_field_id\":\"13\",\"comment\":\"登录ip\",\"control\":\"input\",\"control_args\":\"\",\"form_show\":true,\"searchable\":true,\"search_type\":\"normal\",\"list_show\":false,\"enable_sort\":false},\"join_time\":{\"field\":\"join_time\",\"_field_id\":\"14\",\"comment\":\"注册时间\",\"control\":\"dateTimePicker\",\"control_args\":\"\",\"form_show\":true,\"searchable\":true,\"search_type\":\"between\",\"list_show\":false,\"enable_sort\":false},\"join_ip\":{\"field\":\"join_ip\",\"_field_id\":\"15\",\"comment\":\"注册ip\",\"control\":\"input\",\"control_args\":\"\",\"form_show\":true,\"searchable\":true,\"search_type\":\"normal\",\"list_show\":false,\"enable_sort\":false},\"token\":{\"field\":\"token\",\"_field_id\":\"16\",\"comment\":\"token\",\"control\":\"input\",\"control_args\":\"\",\"search_type\":\"normal\",\"form_show\":false,\"list_show\":false,\"enable_sort\":false,\"searchable\":false},\"created_at\":{\"field\":\"created_at\",\"_field_id\":\"17\",\"comment\":\"创建时间\",\"control\":\"dateTimePicker\",\"control_args\":\"\",\"form_show\":true,\"search_type\":\"between\",\"list_show\":false,\"enable_sort\":false,\"searchable\":false},\"updated_at\":{\"field\":\"updated_at\",\"_field_id\":\"18\",\"comment\":\"更新时间\",\"control\":\"dateTimePicker\",\"control_args\":\"\",\"search_type\":\"between\",\"form_show\":false,\"list_show\":false,\"enable_sort\":false,\"searchable\":false},\"role\":{\"field\":\"role\",\"_field_id\":\"19\",\"comment\":\"角色\",\"control\":\"inputNumber\",\"control_args\":\"\",\"search_type\":\"normal\",\"form_show\":false,\"list_show\":false,\"enable_sort\":false,\"searchable\":false},\"status\":{\"field\":\"status\",\"_field_id\":\"20\",\"comment\":\"禁用\",\"control\":\"switch\",\"control_args\":\"\",\"form_show\":true,\"list_show\":true,\"search_type\":\"normal\",\"enable_sort\":false,\"searchable\":false}}', '2022-08-15 00:00:00', '2022-12-23 15:28:13'),
(3, 'table_form_schema_wa_roles', '{\"id\":{\"field\":\"id\",\"_field_id\":\"0\",\"comment\":\"主键\",\"control\":\"inputNumber\",\"control_args\":\"\",\"list_show\":true,\"search_type\":\"normal\",\"form_show\":false,\"enable_sort\":false,\"searchable\":false},\"name\":{\"field\":\"name\",\"_field_id\":\"1\",\"comment\":\"角色组\",\"control\":\"input\",\"control_args\":\"\",\"form_show\":true,\"list_show\":true,\"search_type\":\"normal\",\"enable_sort\":false,\"searchable\":false},\"rules\":{\"field\":\"rules\",\"_field_id\":\"2\",\"comment\":\"权限\",\"control\":\"treeSelectMulti\",\"control_args\":\"url:\\/app\\/admin\\/rule\\/get?type=0,1,2\",\"form_show\":true,\"list_show\":true,\"search_type\":\"normal\",\"enable_sort\":false,\"searchable\":false},\"created_at\":{\"field\":\"created_at\",\"_field_id\":\"3\",\"comment\":\"创建时间\",\"control\":\"dateTimePicker\",\"control_args\":\"\",\"search_type\":\"normal\",\"form_show\":false,\"list_show\":false,\"enable_sort\":false,\"searchable\":false},\"updated_at\":{\"field\":\"updated_at\",\"_field_id\":\"4\",\"comment\":\"更新时间\",\"control\":\"dateTimePicker\",\"control_args\":\"\",\"search_type\":\"normal\",\"form_show\":false,\"list_show\":false,\"enable_sort\":false,\"searchable\":false},\"pid\":{\"field\":\"pid\",\"_field_id\":\"5\",\"comment\":\"父级\",\"control\":\"select\",\"control_args\":\"url:\\/app\\/admin\\/role\\/select?format=tree\",\"form_show\":true,\"list_show\":true,\"search_type\":\"normal\",\"enable_sort\":false,\"searchable\":false}}', '2022-08-15 00:00:00', '2022-12-19 14:24:25'),
(4, 'table_form_schema_wa_rules', '{\"id\":{\"field\":\"id\",\"_field_id\":\"0\",\"comment\":\"主键\",\"control\":\"inputNumber\",\"control_args\":\"\",\"search_type\":\"normal\",\"form_show\":false,\"list_show\":false,\"enable_sort\":false,\"searchable\":false},\"title\":{\"field\":\"title\",\"_field_id\":\"1\",\"comment\":\"标题\",\"control\":\"input\",\"control_args\":\"\",\"form_show\":true,\"list_show\":true,\"searchable\":true,\"search_type\":\"normal\",\"enable_sort\":false},\"icon\":{\"field\":\"icon\",\"_field_id\":\"2\",\"comment\":\"图标\",\"control\":\"iconPicker\",\"control_args\":\"\",\"form_show\":true,\"list_show\":true,\"search_type\":\"normal\",\"enable_sort\":false,\"searchable\":false},\"key\":{\"field\":\"key\",\"_field_id\":\"3\",\"comment\":\"标识\",\"control\":\"input\",\"control_args\":\"\",\"form_show\":true,\"list_show\":true,\"searchable\":true,\"search_type\":\"normal\",\"enable_sort\":false},\"pid\":{\"field\":\"pid\",\"_field_id\":\"4\",\"comment\":\"上级菜单\",\"control\":\"treeSelect\",\"control_args\":\"\\/app\\/admin\\/rule\\/select?format=tree&type=0,1\",\"form_show\":true,\"list_show\":true,\"search_type\":\"normal\",\"enable_sort\":false,\"searchable\":false},\"created_at\":{\"field\":\"created_at\",\"_field_id\":\"5\",\"comment\":\"创建时间\",\"control\":\"dateTimePicker\",\"control_args\":\"\",\"search_type\":\"normal\",\"form_show\":false,\"list_show\":false,\"enable_sort\":false,\"searchable\":false},\"updated_at\":{\"field\":\"updated_at\",\"_field_id\":\"6\",\"comment\":\"更新时间\",\"control\":\"dateTimePicker\",\"control_args\":\"\",\"search_type\":\"normal\",\"form_show\":false,\"list_show\":false,\"enable_sort\":false,\"searchable\":false},\"href\":{\"field\":\"href\",\"_field_id\":\"7\",\"comment\":\"url\",\"control\":\"input\",\"control_args\":\"\",\"form_show\":true,\"list_show\":true,\"search_type\":\"normal\",\"enable_sort\":false,\"searchable\":false},\"type\":{\"field\":\"type\",\"_field_id\":\"8\",\"comment\":\"类型\",\"control\":\"select\",\"control_args\":\"data:0:目录,1:菜单,2:权限\",\"form_show\":true,\"list_show\":true,\"searchable\":true,\"search_type\":\"normal\",\"enable_sort\":false},\"weight\":{\"field\":\"weight\",\"_field_id\":\"9\",\"comment\":\"排序\",\"control\":\"inputNumber\",\"control_args\":\"\",\"form_show\":true,\"list_show\":true,\"search_type\":\"normal\",\"enable_sort\":false,\"searchable\":false}}', '2022-08-15 00:00:00', '2022-12-08 11:44:45'),
(5, 'table_form_schema_wa_admins', '{\"id\":{\"field\":\"id\",\"_field_id\":\"0\",\"comment\":\"ID\",\"control\":\"inputNumber\",\"control_args\":\"\",\"list_show\":true,\"enable_sort\":true,\"search_type\":\"between\",\"form_show\":false,\"searchable\":false},\"username\":{\"field\":\"username\",\"_field_id\":\"1\",\"comment\":\"用户名\",\"control\":\"input\",\"control_args\":\"\",\"form_show\":true,\"list_show\":true,\"searchable\":true,\"search_type\":\"normal\",\"enable_sort\":false},\"nickname\":{\"field\":\"nickname\",\"_field_id\":\"2\",\"comment\":\"昵称\",\"control\":\"input\",\"control_args\":\"\",\"form_show\":true,\"list_show\":true,\"searchable\":true,\"search_type\":\"normal\",\"enable_sort\":false},\"password\":{\"field\":\"password\",\"_field_id\":\"3\",\"comment\":\"密码\",\"control\":\"input\",\"control_args\":\"\",\"form_show\":true,\"search_type\":\"normal\",\"list_show\":false,\"enable_sort\":false,\"searchable\":false},\"avatar\":{\"field\":\"avatar\",\"_field_id\":\"4\",\"comment\":\"头像\",\"control\":\"uploadImage\",\"control_args\":\"url:\\/app\\/admin\\/upload\\/avatar\",\"form_show\":true,\"list_show\":true,\"search_type\":\"normal\",\"enable_sort\":false,\"searchable\":false},\"email\":{\"field\":\"email\",\"_field_id\":\"5\",\"comment\":\"邮箱\",\"control\":\"input\",\"control_args\":\"\",\"form_show\":true,\"list_show\":true,\"searchable\":true,\"search_type\":\"normal\",\"enable_sort\":false},\"mobile\":{\"field\":\"mobile\",\"_field_id\":\"6\",\"comment\":\"手机\",\"control\":\"input\",\"control_args\":\"\",\"form_show\":true,\"list_show\":true,\"searchable\":true,\"search_type\":\"normal\",\"enable_sort\":false},\"created_at\":{\"field\":\"created_at\",\"_field_id\":\"7\",\"comment\":\"创建时间\",\"control\":\"dateTimePicker\",\"control_args\":\"\",\"form_show\":true,\"searchable\":true,\"search_type\":\"between\",\"list_show\":false,\"enable_sort\":false},\"updated_at\":{\"field\":\"updated_at\",\"_field_id\":\"8\",\"comment\":\"更新时间\",\"control\":\"dateTimePicker\",\"control_args\":\"\",\"form_show\":true,\"search_type\":\"normal\",\"list_show\":false,\"enable_sort\":false},\"login_at\":{\"field\":\"login_at\",\"_field_id\":\"9\",\"comment\":\"登录时间\",\"control\":\"dateTimePicker\",\"control_args\":\"\",\"form_show\":true,\"list_show\":true,\"search_type\":\"between\",\"enable_sort\":false,\"searchable\":false},\"status\":{\"field\":\"status\",\"_field_id\":\"10\",\"comment\":\"禁用\",\"control\":\"switch\",\"control_args\":\"\",\"form_show\":true,\"list_show\":true,\"search_type\":\"normal\",\"enable_sort\":false,\"searchable\":false}}', '2022-08-15 00:00:00', '2022-12-23 15:36:48'),
(6, 'table_form_schema_wa_options', '{\"id\":{\"field\":\"id\",\"_field_id\":\"0\",\"comment\":\"\",\"control\":\"inputNumber\",\"control_args\":\"\",\"list_show\":true,\"search_type\":\"normal\",\"form_show\":false,\"enable_sort\":false,\"searchable\":false},\"name\":{\"field\":\"name\",\"_field_id\":\"1\",\"comment\":\"键\",\"control\":\"input\",\"control_args\":\"\",\"form_show\":true,\"list_show\":true,\"search_type\":\"normal\",\"enable_sort\":false,\"searchable\":false},\"value\":{\"field\":\"value\",\"_field_id\":\"2\",\"comment\":\"值\",\"control\":\"textArea\",\"control_args\":\"\",\"form_show\":true,\"list_show\":true,\"search_type\":\"normal\",\"enable_sort\":false,\"searchable\":false},\"created_at\":{\"field\":\"created_at\",\"_field_id\":\"3\",\"comment\":\"创建时间\",\"control\":\"dateTimePicker\",\"control_args\":\"\",\"search_type\":\"normal\",\"form_show\":false,\"list_show\":false,\"enable_sort\":false,\"searchable\":false},\"updated_at\":{\"field\":\"updated_at\",\"_field_id\":\"4\",\"comment\":\"更新时间\",\"control\":\"dateTimePicker\",\"control_args\":\"\",\"search_type\":\"normal\",\"form_show\":false,\"list_show\":false,\"enable_sort\":false,\"searchable\":false}}', '2022-08-15 00:00:00', '2022-12-08 11:36:57'),
(7, 'table_form_schema_wa_uploads', '{\"id\":{\"field\":\"id\",\"_field_id\":\"0\",\"comment\":\"主键\",\"control\":\"inputNumber\",\"control_args\":\"\",\"list_show\":true,\"enable_sort\":true,\"search_type\":\"normal\",\"form_show\":false,\"searchable\":false},\"name\":{\"field\":\"name\",\"_field_id\":\"1\",\"comment\":\"名称\",\"control\":\"input\",\"control_args\":\"\",\"list_show\":true,\"searchable\":true,\"search_type\":\"normal\",\"form_show\":false,\"enable_sort\":false},\"url\":{\"field\":\"url\",\"_field_id\":\"2\",\"comment\":\"文件\",\"control\":\"upload\",\"control_args\":\"url:\\/app\\/admin\\/upload\\/file\",\"form_show\":true,\"list_show\":true,\"search_type\":\"normal\",\"enable_sort\":false,\"searchable\":false},\"admin_id\":{\"field\":\"admin_id\",\"_field_id\":\"3\",\"comment\":\"管理员\",\"control\":\"select\",\"control_args\":\"url:\\/app\\/admin\\/admin\\/select?format=select\",\"search_type\":\"normal\",\"form_show\":false,\"list_show\":false,\"enable_sort\":false,\"searchable\":false},\"file_size\":{\"field\":\"file_size\",\"_field_id\":\"4\",\"comment\":\"文件大小\",\"control\":\"inputNumber\",\"control_args\":\"\",\"list_show\":true,\"search_type\":\"between\",\"form_show\":false,\"enable_sort\":false,\"searchable\":false},\"mime_type\":{\"field\":\"mime_type\",\"_field_id\":\"5\",\"comment\":\"mime类型\",\"control\":\"input\",\"control_args\":\"\",\"list_show\":true,\"search_type\":\"normal\",\"form_show\":false,\"enable_sort\":false,\"searchable\":false},\"image_width\":{\"field\":\"image_width\",\"_field_id\":\"6\",\"comment\":\"图片宽度\",\"control\":\"inputNumber\",\"control_args\":\"\",\"list_show\":true,\"search_type\":\"normal\",\"form_show\":false,\"enable_sort\":false,\"searchable\":false},\"image_height\":{\"field\":\"image_height\",\"_field_id\":\"7\",\"comment\":\"图片高度\",\"control\":\"inputNumber\",\"control_args\":\"\",\"list_show\":true,\"search_type\":\"normal\",\"form_show\":false,\"enable_sort\":false,\"searchable\":false},\"ext\":{\"field\":\"ext\",\"_field_id\":\"8\",\"comment\":\"扩展名\",\"control\":\"input\",\"control_args\":\"\",\"list_show\":true,\"searchable\":true,\"search_type\":\"normal\",\"form_show\":false,\"enable_sort\":false},\"storage\":{\"field\":\"storage\",\"_field_id\":\"9\",\"comment\":\"存储位置\",\"control\":\"input\",\"control_args\":\"\",\"search_type\":\"normal\",\"form_show\":false,\"list_show\":false,\"enable_sort\":false,\"searchable\":false},\"created_at\":{\"field\":\"created_at\",\"_field_id\":\"10\",\"comment\":\"上传时间\",\"control\":\"dateTimePicker\",\"control_args\":\"\",\"searchable\":true,\"search_type\":\"between\",\"form_show\":false,\"list_show\":false,\"enable_sort\":false},\"category\":{\"field\":\"category\",\"_field_id\":\"11\",\"comment\":\"类别\",\"control\":\"select\",\"control_args\":\"url:\\/app\\/admin\\/dict\\/get\\/upload\",\"form_show\":true,\"list_show\":true,\"searchable\":true,\"search_type\":\"normal\",\"enable_sort\":false},\"updated_at\":{\"field\":\"updated_at\",\"_field_id\":\"12\",\"comment\":\"更新时间\",\"control\":\"dateTimePicker\",\"control_args\":\"\",\"form_show\":true,\"list_show\":true,\"search_type\":\"normal\",\"enable_sort\":false,\"searchable\":false}}', '2022-08-15 00:00:00', '2022-12-08 11:47:45'),
(8, 'dict_upload', '[{\"value\":\"1\",\"name\":\"分类1\"},{\"value\":\"2\",\"name\":\"分类2\"},{\"value\":\"3\",\"name\":\"分类3\"}]', '2022-12-04 16:24:13', '2022-12-04 16:24:13'),
(9, 'dict_sex', '[{\"value\":\"0\",\"name\":\"女\"},{\"value\":\"1\",\"name\":\"男\"}]', '2022-12-04 15:04:40', '2022-12-04 15:04:40'),
(10, 'dict_status', '[{\"value\":\"0\",\"name\":\"正常\"},{\"value\":\"1\",\"name\":\"禁用\"}]', '2022-12-04 15:05:09', '2022-12-04 15:05:09'),
(11, 'table_form_schema_wa_admin_roles', '{\"id\":{\"field\":\"id\",\"_field_id\":\"0\",\"comment\":\"主键\",\"control\":\"inputNumber\",\"control_args\":\"\",\"list_show\":true,\"enable_sort\":true,\"searchable\":true,\"search_type\":\"normal\",\"form_show\":false},\"role_id\":{\"field\":\"role_id\",\"_field_id\":\"1\",\"comment\":\"角色id\",\"control\":\"inputNumber\",\"control_args\":\"\",\"form_show\":true,\"list_show\":true,\"search_type\":\"normal\",\"enable_sort\":false,\"searchable\":false},\"admin_id\":{\"field\":\"admin_id\",\"_field_id\":\"2\",\"comment\":\"管理员id\",\"control\":\"inputNumber\",\"control_args\":\"\",\"form_show\":true,\"list_show\":true,\"search_type\":\"normal\",\"enable_sort\":false,\"searchable\":false}}', '2022-08-15 00:00:00', '2022-12-20 19:42:51'),
(12, 'dict_dict_name', '[{\"value\":\"dict_name\",\"name\":\"字典名称\"},{\"value\":\"status\",\"name\":\"启禁用状态\"},{\"value\":\"sex\",\"name\":\"性别\"},{\"value\":\"upload\",\"name\":\"附件分类\"}]', '2022-08-15 00:00:00', '2022-12-20 19:42:51');

INSERT INTO `wa_roles` VALUES (1,'超级管理员','*','2022-08-13 16:15:01','2022-12-23 12:05:07',NULL);

INSERT INTO `links` VALUES (default, '雨云',
        'https://www.rainyun.com/github_?s=blog-sys-ads',
        '超高性价比云服务商，使用优惠码github注册并绑定微信即可获得5折优惠',
        'https://www.rainyun.com/favicon.ico',
        null,
        '1',
        true,
        '_blank',
        'direct',
        false,
        '# 超高性价比云服务商，使用优惠码github注册并绑定微信即可获得5折优惠',
        'admin@biliwind.com',
        null,
        '雨云',
        '雨云,云服务器,服务器,性价比',
        '超高性价比云服务商，使用优惠码github注册并绑定微信即可获得5折优惠',
        null, '2025-9-26 11:00:00', '2022-12-23 12:05:07',
        null);

-- 创建AI提供方表
CREATE TABLE IF NOT EXISTS ` ai_providers `
(
    `
    id
    `
    varchar
(
    64
) NOT NULL COMMENT '提供方唯一标识（用户自定义或自动生成）',
    ` name ` varchar
(
    255
) NOT NULL COMMENT '提供方名称',
    ` template ` varchar
(
    64
) DEFAULT NULL COMMENT '模板类型（openai/claude/azure/gemini/custom等）',
    ` type ` varchar
(
    64
) NOT NULL DEFAULT 'openai' COMMENT '提供方类型',
    ` config ` text COMMENT '配置JSON（包含api_key、base_url、model等）',
    ` weight ` int
(
    11
) NOT NULL DEFAULT 1 COMMENT '权重（用于加权选择）',
    ` enabled ` tinyint
(
    1
) NOT NULL DEFAULT 1 COMMENT '是否启用',
    ` created_at ` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    ` updated_at ` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY
(
    `
    id
    `
),
    KEY ` idx_enabled `
(
    `
    enabled
    `
),
    KEY ` idx_template `
(
    `
    template
    `
)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE =utf8mb4_unicode_ci COMMENT ='AI提供方配置表';

-- 创建AI轮询组表
CREATE TABLE IF NOT EXISTS ` ai_polling_groups `
(
    `
    id
    `
    INT
    UNSIGNED
    NOT
    NULL
    AUTO_INCREMENT
    COMMENT
    '主键ID',
    `
    name
    `
    VARCHAR
(
    100
) NOT NULL COMMENT '轮询组名称',
    ` description ` VARCHAR
(
    500
) DEFAULT NULL COMMENT '轮询组描述',
    ` strategy ` ENUM
(
    'polling',
    'failover'
) NOT NULL DEFAULT 'polling' COMMENT '调度策略：polling=轮询, failover=主备',
    ` enabled ` TINYINT
(
    1
) NOT NULL DEFAULT 1 COMMENT '是否启用',
    ` created_at ` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    ` updated_at ` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY
(
    `
    id
    `
),
    UNIQUE KEY ` uk_name `
(
    `
    name
    `
)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE =utf8mb4_unicode_ci COMMENT ='AI轮询组表';

-- 创建AI轮询组提供方关系表
CREATE TABLE IF NOT EXISTS ` ai_polling_group_providers `
(
    `
    id
    `
    INT
    UNSIGNED
    NOT
    NULL
    AUTO_INCREMENT
    COMMENT
    '主键ID',
    `
    group_id
    `
    INT
    UNSIGNED
    NOT
    NULL
    COMMENT
    '轮询组ID',
    `
    provider_id
    `
    VARCHAR
(
    64
) NOT NULL COMMENT '提供方ID（关联ai_providers.id）',
    ` weight ` INT NOT NULL DEFAULT 1 COMMENT '权重（用于轮询和优先级）',
    ` enabled ` TINYINT
(
    1
) NOT NULL DEFAULT 1 COMMENT '是否启用',
    ` created_at ` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    ` updated_at ` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY
(
    `
    id
    `
),
    KEY ` idx_group_id `
(
    `
    group_id
    `
),
    KEY ` idx_provider_id `
(
    `
    provider_id
    `
),
    UNIQUE KEY ` uk_group_provider `
(
    `
    group_id
    `,
    `
    provider_id
    `
),
    CONSTRAINT ` fk_polling_group_providers_group ` FOREIGN KEY
(
    `
    group_id
    `
) REFERENCES ` ai_polling_groups `
(
    `
    id
    `
)
                                                            ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE =utf8mb4_unicode_ci COMMENT ='AI轮询组提供方关系表';

INSERT INTO `settings` (`key`, `value`, `created_at`, `updated_at`)
VALUES ('table_form_schema_wa_users', '{
         "id": {
           "field": "id",
           "_field_id": "0",
           "comment": "主键",
           "control": "inputNumber",
           "control_args": "",
           "list_show": true,
           "enable_sort": true,
           "searchable": true,
           "search_type": "normal",
           "form_show": false
         },
         "username": {
           "field": "username",
           "_field_id": "1",
           "comment": "用户名",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "enable_sort": false
         },
         "nickname": {
           "field": "nickname",
           "_field_id": "2",
           "comment": "昵称",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "enable_sort": false
         },
         "password": {
           "field": "password",
           "_field_id": "3",
           "comment": "密码",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "search_type": "normal",
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "sex": {
           "field": "sex",
           "_field_id": "4",
           "comment": "性别",
           "control": "select",
           "control_args": "url:/app/admin/dict/get/sex",
           "form_show": true,
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "enable_sort": false
         },
         "avatar": {
           "field": "avatar",
           "_field_id": "5",
           "comment": "头像",
           "control": "uploadImage",
           "control_args": "url:/app/admin/upload/avatar",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         },
         "email": {
           "field": "email",
           "_field_id": "6",
           "comment": "邮箱",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "enable_sort": false
         },
         "mobile": {
           "field": "mobile",
           "_field_id": "7",
           "comment": "手机",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "enable_sort": false
         },
         "level": {
           "field": "level",
           "_field_id": "8",
           "comment": "等级",
           "control": "inputNumber",
           "control_args": "",
           "form_show": true,
           "searchable": true,
           "search_type": "normal",
           "list_show": false,
           "enable_sort": false
         },
         "birthday": {
           "field": "birthday",
           "_field_id": "9",
           "comment": "生日",
           "control": "datePicker",
           "control_args": "",
           "form_show": true,
           "searchable": true,
           "search_type": "between",
           "list_show": false,
           "enable_sort": false
         },
         "money": {
           "field": "money",
           "_field_id": "10",
           "comment": "余额(元)",
           "control": "inputNumber",
           "control_args": "",
           "form_show": true,
           "searchable": true,
           "search_type": "normal",
           "list_show": false,
           "enable_sort": false
         },
         "score": {
           "field": "score",
           "_field_id": "11",
           "comment": "积分",
           "control": "inputNumber",
           "control_args": "",
           "form_show": true,
           "searchable": true,
           "search_type": "normal",
           "list_show": false,
           "enable_sort": false
         },
         "last_time": {
           "field": "last_time",
           "_field_id": "12",
           "comment": "登录时间",
           "control": "dateTimePicker",
           "control_args": "",
           "form_show": true,
           "searchable": true,
           "search_type": "between",
           "list_show": false,
           "enable_sort": false
         },
         "last_ip": {
           "field": "last_ip",
           "_field_id": "13",
           "comment": "登录ip",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "searchable": true,
           "search_type": "normal",
           "list_show": false,
           "enable_sort": false
         },
         "join_time": {
           "field": "join_time",
           "_field_id": "14",
           "comment": "注册时间",
           "control": "dateTimePicker",
           "control_args": "",
           "form_show": true,
           "searchable": true,
           "search_type": "between",
           "list_show": false,
           "enable_sort": false
         },
         "join_ip": {
           "field": "join_ip",
           "_field_id": "15",
           "comment": "注册ip",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "searchable": true,
           "search_type": "normal",
           "list_show": false,
           "enable_sort": false
         },
         "token": {
           "field": "token",
           "_field_id": "16",
           "comment": "token",
           "control": "input",
           "control_args": "",
           "search_type": "normal",
           "form_show": false,
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "created_at": {
           "field": "created_at",
           "_field_id": "17",
           "comment": "创建时间",
           "control": "dateTimePicker",
           "control_args": "",
           "form_show": true,
           "search_type": "between",
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "updated_at": {
           "field": "updated_at",
           "_field_id": "18",
           "comment": "更新时间",
           "control": "dateTimePicker",
           "control_args": "",
           "search_type": "between",
           "form_show": false,
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "role": {
           "field": "role",
           "_field_id": "19",
           "comment": "角色",
           "control": "inputNumber",
           "control_args": "",
           "search_type": "normal",
           "form_show": false,
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "status": {
           "field": "status",
           "_field_id": "20",
           "comment": "禁用",
           "control": "switch",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         }
       }', '2022-08-15 00:00:00', '2022-12-23 15:28:13'),
       ('table_form_schema_wa_roles', '{
         "id": {
           "field": "id",
           "_field_id": "0",
           "comment": "主键",
           "control": "inputNumber",
           "control_args": "",
           "list_show": true,
           "search_type": "normal",
           "form_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "name": {
           "field": "name",
           "_field_id": "1",
           "comment": "角色组",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         },
         "rules": {
           "field": "rules",
           "_field_id": "2",
           "comment": "权限",
           "control": "treeSelectMulti",
           "control_args": "url:/app/admin/rule/get?type=0,1,2",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         },
         "created_at": {
           "field": "created_at",
           "_field_id": "3",
           "comment": "创建时间",
           "control": "dateTimePicker",
           "control_args": "",
           "search_type": "normal",
           "form_show": false,
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "updated_at": {
           "field": "updated_at",
           "_field_id": "4",
           "comment": "更新时间",
           "control": "dateTimePicker",
           "control_args": "",
           "search_type": "normal",
           "form_show": false,
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "pid": {
           "field": "pid",
           "_field_id": "5",
           "comment": "父级",
           "control": "select",
           "control_args": "url:/app/admin/role/select?format=tree",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         }
       }', '2022-08-15 00:00:00', '2022-12-19 14:24:25'),
       ('table_form_schema_wa_rules', '{
         "id": {
           "field": "id",
           "_field_id": "0",
           "comment": "主键",
           "control": "inputNumber",
           "control_args": "",
           "search_type": "normal",
           "form_show": false,
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "title": {
           "field": "title",
           "_field_id": "1",
           "comment": "标题",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "enable_sort": false
         },
         "icon": {
           "field": "icon",
           "_field_id": "2",
           "comment": "图标",
           "control": "iconPicker",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         },
         "key": {
           "field": "key",
           "_field_id": "3",
           "comment": "标识",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "enable_sort": false
         },
         "pid": {
           "field": "pid",
           "_field_id": "4",
           "comment": "上级菜单",
           "control": "treeSelect",
           "control_args": "/app/admin/rule/select?format=tree&type=0,1",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         },
         "created_at": {
           "field": "created_at",
           "_field_id": "5",
           "comment": "创建时间",
           "control": "dateTimePicker",
           "control_args": "",
           "search_type": "normal",
           "form_show": false,
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "updated_at": {
           "field": "updated_at",
           "_field_id": "6",
           "comment": "更新时间",
           "control": "dateTimePicker",
           "control_args": "",
           "search_type": "normal",
           "form_show": false,
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "href": {
           "field": "href",
           "_field_id": "7",
           "comment": "url",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         },
         "type": {
           "field": "type",
           "_field_id": "8",
           "comment": "类型",
           "control": "select",
           "control_args": "data:0:目录,1:菜单,2:权限",
           "form_show": true,
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "enable_sort": false
         },
         "weight": {
           "field": "weight",
           "_field_id": "9",
           "comment": "排序",
           "control": "inputNumber",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         }
       }', '2022-08-15 00:00:00', '2022-12-08 11:44:45'),
       ('table_form_schema_wa_admins', '{
         "id": {
           "field": "id",
           "_field_id": "0",
           "comment": "ID",
           "control": "inputNumber",
           "control_args": "",
           "list_show": true,
           "enable_sort": true,
           "search_type": "between",
           "form_show": false,
           "searchable": false
         },
         "username": {
           "field": "username",
           "_field_id": "1",
           "comment": "用户名",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "enable_sort": false
         },
         "nickname": {
           "field": "nickname",
           "_field_id": "2",
           "comment": "昵称",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "enable_sort": false
         },
         "password": {
           "field": "password",
           "_field_id": "3",
           "comment": "密码",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "search_type": "normal",
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "avatar": {
           "field": "avatar",
           "_field_id": "4",
           "comment": "头像",
           "control": "uploadImage",
           "control_args": "url:/app/admin/upload/avatar",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         },
         "email": {
           "field": "email",
           "_field_id": "5",
           "comment": "邮箱",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "enable_sort": false
         },
         "mobile": {
           "field": "mobile",
           "_field_id": "6",
           "comment": "手机",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "enable_sort": false
         },
         "created_at": {
           "field": "created_at",
           "_field_id": "7",
           "comment": "创建时间",
           "control": "dateTimePicker",
           "control_args": "",
           "form_show": true,
           "searchable": true,
           "search_type": "between",
           "list_show": false,
           "enable_sort": false
         },
         "updated_at": {
           "field": "updated_at",
           "_field_id": "8",
           "comment": "更新时间",
           "control": "dateTimePicker",
           "control_args": "",
           "form_show": true,
           "search_type": "normal",
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "login_at": {
           "field": "login_at",
           "_field_id": "9",
           "comment": "登录时间",
           "control": "dateTimePicker",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "search_type": "between",
           "enable_sort": false,
           "searchable": false
         },
         "status": {
           "field": "status",
           "_field_id": "10",
           "comment": "禁用",
           "control": "switch",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         }
       }', '2022-08-15 00:00:00', '2022-12-23 15:36:48'),
       ('table_form_schema_wa_options', '{
         "id": {
           "field": "id",
           "_field_id": "0",
           "comment": "",
           "control": "inputNumber",
           "control_args": "",
           "list_show": true,
           "search_type": "normal",
           "form_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "name": {
           "field": "name",
           "_field_id": "1",
           "comment": "键",
           "control": "input",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         },
         "value": {
           "field": "value",
           "_field_id": "2",
           "comment": "值",
           "control": "textArea",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         },
         "created_at": {
           "field": "created_at",
           "_field_id": "3",
           "comment": "创建时间",
           "control": "dateTimePicker",
           "control_args": "",
           "search_type": "normal",
           "form_show": false,
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "updated_at": {
           "field": "updated_at",
           "_field_id": "4",
           "comment": "更新时间",
           "control": "dateTimePicker",
           "control_args": "",
           "search_type": "normal",
           "form_show": false,
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         }
       }', '2022-08-15 00:00:00', '2022-12-08 11:36:57'),
       ('table_form_schema_wa_uploads', '{
         "id": {
           "field": "id",
           "_field_id": "0",
           "comment": "主键",
           "control": "inputNumber",
           "control_args": "",
           "list_show": true,
           "enable_sort": true,
           "search_type": "normal",
           "form_show": false,
           "searchable": false
         },
         "name": {
           "field": "name",
           "_field_id": "1",
           "comment": "名称",
           "control": "input",
           "control_args": "",
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "form_show": false,
           "enable_sort": false
         },
         "url": {
           "field": "url",
           "_field_id": "2",
           "comment": "文件",
           "control": "upload",
           "control_args": "url:/app/admin/upload/file",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         },
         "admin_id": {
           "field": "admin_id",
           "_field_id": "3",
           "comment": "管理员",
           "control": "select",
           "control_args": "url:/app/admin/admin/select?format=select",
           "search_type": "normal",
           "form_show": false,
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "file_size": {
           "field": "file_size",
           "_field_id": "4",
           "comment": "文件大小",
           "control": "inputNumber",
           "control_args": "",
           "list_show": true,
           "search_type": "between",
           "form_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "mime_type": {
           "field": "mime_type",
           "_field_id": "5",
           "comment": "mime类型",
           "control": "input",
           "control_args": "",
           "list_show": true,
           "search_type": "normal",
           "form_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "image_width": {
           "field": "image_width",
           "_field_id": "6",
           "comment": "图片宽度",
           "control": "inputNumber",
           "control_args": "",
           "list_show": true,
           "search_type": "normal",
           "form_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "image_height": {
           "field": "image_height",
           "_field_id": "7",
           "comment": "图片高度",
           "control": "inputNumber",
           "control_args": "",
           "list_show": true,
           "search_type": "normal",
           "form_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "ext": {
           "field": "ext",
           "_field_id": "8",
           "comment": "扩展名",
           "control": "input",
           "control_args": "",
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "form_show": false,
           "enable_sort": false
         },
         "storage": {
           "field": "storage",
           "_field_id": "9",
           "comment": "存储位置",
           "control": "input",
           "control_args": "",
           "search_type": "normal",
           "form_show": false,
           "list_show": false,
           "enable_sort": false,
           "searchable": false
         },
         "created_at": {
           "field": "created_at",
           "_field_id": "10",
           "comment": "上传时间",
           "control": "dateTimePicker",
           "control_args": "",
           "searchable": true,
           "search_type": "between",
           "form_show": false,
           "list_show": false,
           "enable_sort": false
         },
         "category": {
           "field": "category",
           "_field_id": "11",
           "comment": "类别",
           "control": "select",
           "control_args": "url:/app/admin/dict/get/upload",
           "form_show": true,
           "list_show": true,
           "searchable": true,
           "search_type": "normal",
           "enable_sort": false
         },
         "updated_at": {
           "field": "updated_at",
           "_field_id": "12",
           "comment": "更新时间",
           "control": "dateTimePicker",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         }
       }', '2022-08-15 00:00:00', '2022-12-08 11:47:45'),
       ('dict_upload', '[
         {
           "value": "1",
           "name": "分类1"
         },
         {
           "value": "2",
           "name": "分类2"
         },
         {
           "value": "3",
           "name": "分类3"
         }
       ]', '2022-12-04 16:24:13', '2022-12-04 16:24:13'),
       ('dict_sex', '[
         {
           "value": "0",
           "name": "女"
         },
         {
           "value": "1",
           "name": "男"
         }
       ]', '2022-12-04 15:04:40', '2022-12-04 15:04:40'),
       ('dict_status', '[
         {
           "value": "0",
           "name": "正常"
         },
         {
           "value": "1",
           "name": "禁用"
         }
       ]', '2022-12-04 15:05:09', '2022-12-04 15:05:09'),
       ('table_form_schema_wa_admin_roles', '{
         "id": {
           "field": "id",
           "_field_id": "0",
           "comment": "主键",
           "control": "inputNumber",
           "control_args": "",
           "list_show": true,
           "enable_sort": true,
           "searchable": true,
           "search_type": "normal",
           "form_show": false
         },
         "role_id": {
           "field": "role_id",
           "_field_id": "1",
           "comment": "角色id",
           "control": "inputNumber",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         },
         "admin_id": {
           "field": "admin_id",
           "_field_id": "2",
           "comment": "管理员id",
           "control": "inputNumber",
           "control_args": "",
           "form_show": true,
           "list_show": true,
           "search_type": "normal",
           "enable_sort": false,
           "searchable": false
         }
       }', '2022-08-15 00:00:00', '2022-12-20 19:42:51'),
       ('dict_dict_name', '[
         {
           "value": "dict_name",
           "name": "字典名称"
         },
         {
           "value": "status",
           "name": "启禁用状态"
         },
         {
           "value": "sex",
           "name": "性别"
         },
         {
           "value": "upload",
           "name": "附件分类"
         }
       ]', '2022-08-15 00:00:00', '2022-12-20 19:42:51');

-- 创建广告表
CREATE TABLE IF NOT EXISTS ` ads `
(
    `
    id
    `
    bigint
(
    20
) unsigned NOT NULL AUTO_INCREMENT,
    ` title ` varchar
(
    255
) NOT NULL COMMENT '标题',
    ` type ` varchar
(
    20
) NOT NULL DEFAULT 'image' COMMENT '类型:image|google|html',
    ` enabled ` tinyint
(
    1
) NOT NULL DEFAULT 1 COMMENT '启用',
    ` image_url ` varchar
(
    512
) DEFAULT NULL COMMENT '图片URL',
    ` link_url ` varchar
(
    512
) DEFAULT NULL COMMENT '跳转链接',
    ` link_target ` varchar
(
    20
) DEFAULT '_blank' COMMENT '打开方式',
    ` html ` longtext DEFAULT NULL COMMENT '自定义HTML',
    ` google_ad_client ` varchar
(
    64
) DEFAULT NULL COMMENT 'Ad Client',
    ` google_ad_slot ` varchar
(
    64
) DEFAULT NULL COMMENT 'Ad Slot',
    ` placements ` json DEFAULT NULL COMMENT '投放设置',
    ` weight ` int
(
    11
) DEFAULT 100 COMMENT '权重',
    ` created_at ` timestamp NULL DEFAULT NULL,
    ` updated_at ` timestamp NULL DEFAULT NULL,
    ` deleted_at ` timestamp NULL DEFAULT NULL,
    PRIMARY KEY
(
    `
    id
    `
)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE =utf8mb4_unicode_ci COMMENT ='广告表';

-- 广告表索引
CREATE INDEX ` idx_ads_enabled ` ON ` ads ` (` enabled `);
CREATE INDEX ` idx_ads_weight ` ON ` ads ` (` weight `);

-- 示例广告（侧边栏）
INSERT INTO ` ads ` (` title `, ` type `, ` enabled `, ` image_url `, ` link_url `, ` link_target `, ` html `, `
                     google_ad_client `, ` google_ad_slot `, ` placements `, ` weight `, ` created_at `, ` updated_at `,
                     ` deleted_at `)
VALUES (
    '雨云-高性价比云服务商', 'image', 1, 'https://www.rainyun.com/favicon.ico', 'https://www.rainyun.com/github_', '_blank', NULL, NULL, NULL, '{"positions":["sidebar"]}', 100, NOW(), NOW(), NULL
    );

-- 广告表管理表单
INSERT INTO ` settings ` (` key `, ` value `, ` created_at `, ` updated_at `)
VALUES ('table_form_schema_ads', '{"id":{"field":"id","comment":"主键","control":"inputNumber","form_show":false,"list_show":true,"enable_sort":true},"title":{"field":"title","comment":"标题","control":"input","form_show":true,"list_show":true,"searchable":true},"type":{"field":"type","comment":"类型","control":"select","control_args":"data:image:自定义图文,google:Google广告,html:自定义HTML","form_show":true,"list_show":true},"enabled":{"field":"enabled","comment":"启用","control":"switch","control_args":"lay-text:启用|禁用","form_show":true,"list_show":true},"image_url":{"field":"image_url","comment":"图片","control":"uploadImage","control_args":"url:/app/admin/media/upload","form_show":true,"list_show":false},"link_url":{"field":"link_url","comment":"链接","control":"input","form_show":true,"list_show":true},"link_target":{"field":"link_target","comment":"打开方式","control":"select","control_args":"data:_blank:新窗口,_self:本窗口","form_show":true,"list_show":true},"google_ad_client":{"field":"google_ad_client","comment":"Ad Client","control":"input","form_show":true,"list_show":false},"google_ad_slot":{"field":"google_ad_slot","comment":"Ad Slot","control":"input","form_show":true,"list_show":false},"html":{"field":"html","comment":"HTML代码","control":"textArea","form_show":true,"list_show":false},"placements":{"field":"placements","comment":"投放设置(JSON)","control":"textArea","form_show":true,"list_show":false},"weight":{"field":"weight","comment":"权重","control":"inputNumber","form_show":true,"list_show":true,"enable_sort":true}}', '2022-08-15 00:00:00', '2022-12-23 15:28:13');
