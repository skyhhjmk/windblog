-- 创建用户表
CREATE TABLE IF NOT EXISTS `users`
(
    `id`         bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '用户ID，主键',
    `username`   varchar(255)        NOT NULL COMMENT '用户名',
    `email`      varchar(255)        NOT NULL COMMENT '邮箱地址',
    `password`   varchar(255)        NOT NULL COMMENT '密码',
    `created_at` timestamp           NULL DEFAULT NULL COMMENT '创建时间',
    `updated_at` timestamp           NULL DEFAULT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `email` (`email`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4 COMMENT ='用户表';

-- 创建分类表
CREATE TABLE IF NOT EXISTS `categories`
(
    `id`          bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '分类ID，主键',
    `name`        varchar(255)        NOT NULL COMMENT '分类名称',
    `slug`        varchar(255)        NOT NULL COMMENT '分类别名',
    `description` text                     DEFAULT NULL COMMENT '分类描述',
    `parent_id`   bigint(20) unsigned      DEFAULT NULL COMMENT '父分类ID',
    `created_at`  timestamp           NULL DEFAULT NULL COMMENT '创建时间',
    `updated_at`  timestamp           NULL DEFAULT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `slug` (`slug`),
    KEY `parent_id` (`parent_id`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4 COMMENT ='分类表';

-- 创建文章表
CREATE TABLE IF NOT EXISTS posts
(
    `id`          bigint(20) unsigned                   NOT NULL AUTO_INCREMENT COMMENT '文章ID，主键',
    `title`       varchar(255)                          NOT NULL COMMENT '文章标题',
    `slug`        varchar(255)                          NOT NULL COMMENT '文章别名',
    `content_type` enum ('markdown', 'html', 'text', 'visual') NOT NULL DEFAULT 'markdown' COMMENT '内容类型',
    `content`     text                                  NOT NULL COMMENT '文章内容',
    `excerpt`     text                                           DEFAULT NULL COMMENT '文章摘要',
    `status`      enum ('draft','published','archived') NOT NULL DEFAULT 'draft' COMMENT '文章状态',
    `category_id` bigint(20) unsigned                            DEFAULT NULL COMMENT '分类ID',
    `author_id`   bigint(20) unsigned                            DEFAULT NULL COMMENT '作者ID',
    `view_count`  int(10) unsigned                      NOT NULL DEFAULT 0 COMMENT '浏览次数',
    `created_at`  timestamp                             NULL     DEFAULT NULL COMMENT '创建时间',
    `updated_at`  timestamp                             NULL     DEFAULT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `slug` (`slug`),
    KEY `category_id` (`category_id`),
    KEY `author_id` (`author_id`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4 COMMENT ='文章表';

-- 创建友链表
CREATE TABLE IF NOT EXISTS `links`
(
    `id`          bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '友链ID，主键',
    `name`        varchar(255)        NOT NULL COMMENT '友链名称',
    `url`         varchar(255)        NOT NULL COMMENT '友链URL',
    `description` text                     DEFAULT NULL COMMENT '友链描述',
    `created_at`  timestamp           NULL DEFAULT NULL COMMENT '创建时间',
    `updated_at`  timestamp           NULL DEFAULT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4 COMMENT ='友链表';

-- 创建页面表
CREATE TABLE IF NOT EXISTS `pages`
(
    `id`         bigint(20) unsigned        NOT NULL AUTO_INCREMENT COMMENT '页面ID，主键',
    `title`      varchar(255)               NOT NULL COMMENT '页面标题',
    `slug`       varchar(255)               NOT NULL COMMENT '页面别名',
    `content`    text                       NOT NULL COMMENT '页面内容',
    `status`     enum ('draft','published') NOT NULL DEFAULT 'draft' COMMENT '页面状态',
    `created_at` timestamp                  NULL     DEFAULT NULL COMMENT '创建时间',
    `updated_at` timestamp                  NULL     DEFAULT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `slug` (`slug`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4 COMMENT ='页面表';

-- 创建网站设置表
CREATE TABLE IF NOT EXISTS `settings`
(
    `id`         bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '设置ID，主键',
    `key`        varchar(255)        NOT NULL COMMENT '设置键名',
    `value`      text                     DEFAULT NULL COMMENT '设置值',
    `created_at` timestamp           NULL DEFAULT NULL COMMENT '创建时间',
    `updated_at` timestamp           NULL DEFAULT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `key` (`key`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4 COMMENT ='网站设置表';

-- 创建媒体附件表
CREATE TABLE IF NOT EXISTS `media`
(
    `id`         bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '媒体ID，主键',
    `filename`   varchar(255)        NOT NULL COMMENT '文件名',
    `original_name` varchar(255)     NOT NULL COMMENT '原始文件名',
    `file_path`  varchar(512)        NOT NULL COMMENT '文件路径',
    `thumb_path` varchar(500) DEFAULT NULL COMMENT '缩略图路径',
    `file_size`  int(10) unsigned    NOT NULL DEFAULT 0 COMMENT '文件大小',
    `mime_type`  varchar(100)        NOT NULL COMMENT 'MIME类型',
    `alt_text`   varchar(255)        DEFAULT NULL COMMENT '替代文本',
    `caption`    text                DEFAULT NULL COMMENT '标题',
    `description` text               DEFAULT NULL COMMENT '描述',
    `author_id`  bigint(20) unsigned DEFAULT NULL COMMENT '作者ID',
    `created_at` timestamp           NULL DEFAULT NULL COMMENT '创建时间',
    `updated_at` timestamp           NULL DEFAULT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`),
    KEY `author_id` (`author_id`),
    KEY `filename` (`filename`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4 COMMENT ='媒体附件表';

-- 创建导入任务表
CREATE TABLE IF NOT EXISTS `import_jobs`
(
    `id`          bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '任务ID，主键',
    `name`        varchar(255)        NOT NULL COMMENT '任务名称',
    `type`        varchar(50)         NOT NULL COMMENT '任务类型', -- wordpress_xml, etc.
    `file_path`   varchar(512)        NOT NULL COMMENT '文件路径',
    `status`      enum ('pending','processing','completed','failed') NOT NULL DEFAULT 'pending' COMMENT '任务状态',
    `options`     text                DEFAULT NULL COMMENT '导入选项', -- JSON格式的导入选项
    `progress`    int(3) unsigned     NOT NULL DEFAULT 0 COMMENT '导入进度 0-100', -- 导入进度 0-100
    `message`     text                DEFAULT NULL COMMENT '状态消息', -- 状态消息
    `author_id`   bigint(20) unsigned DEFAULT NULL COMMENT '默认作者ID', -- 默认作者ID
    `created_at`  timestamp           NULL DEFAULT NULL COMMENT '创建时间',
    `updated_at`  timestamp           NULL DEFAULT NULL COMMENT '更新时间',
    `completed_at` timestamp          NULL DEFAULT NULL COMMENT '完成时间',
    PRIMARY KEY (`id`),
    KEY `status` (`status`),
    KEY `author_id` (`author_id`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4 COMMENT ='导入任务表';

-- 添加外键约束
ALTER TABLE `categories`
    ADD CONSTRAINT `categories_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL;

ALTER TABLE posts
    ADD CONSTRAINT `articles_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
    ADD CONSTRAINT `articles_author_id_foreign` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;