-- 设置数据库字符集
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 创建 wa_users 表（保持原样不变）
CREATE TABLE IF NOT EXISTS `wa_users`
(
    `id`         int unsigned   NOT NULL AUTO_INCREMENT COMMENT '主键',
    `username`   varchar(32)    NOT NULL COMMENT '用户名',
    `nickname`   varchar(40)    NOT NULL COMMENT '昵称',
    `password`   varchar(255)   NOT NULL COMMENT '密码',
    `sex`        enum ('0','1') NOT NULL DEFAULT '1' COMMENT '性别',
    `avatar`     varchar(255)            DEFAULT NULL COMMENT '头像',
    `email`      varchar(128)            DEFAULT NULL COMMENT '邮箱',
    `mobile`     varchar(16)             DEFAULT NULL COMMENT '手机',
    `level`      tinyint        NOT NULL DEFAULT '0' COMMENT '等级',
    `birthday`   date                    DEFAULT NULL COMMENT '生日',
    `money`      decimal(10, 2) NOT NULL DEFAULT '0.00' COMMENT '余额(元)',
    `score`      int            NOT NULL DEFAULT '0' COMMENT '积分',
    `last_time`  datetime                DEFAULT NULL COMMENT '登录时间',
    `last_ip`    varchar(50)             DEFAULT NULL COMMENT '登录ip',
    `join_time`  datetime                DEFAULT NULL COMMENT '注册时间',
    `join_ip`    varchar(50)             DEFAULT NULL COMMENT '注册ip',
    `token`      varchar(50)             DEFAULT NULL COMMENT 'token',
    `created_at` datetime                DEFAULT NULL COMMENT '创建时间',
    `updated_at` datetime                DEFAULT NULL COMMENT '更新时间',
    `deleted_at` datetime                DEFAULT NULL COMMENT '删除时间',
    `role`       int            NOT NULL DEFAULT '1' COMMENT '角色',
    `status`     tinyint        NOT NULL DEFAULT '0' COMMENT '禁用',
    PRIMARY KEY (`id`),
    UNIQUE KEY `username` (`username`),
    KEY `join_time` (`join_time`),
    KEY `mobile` (`mobile`),
    KEY `email` (`email`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4 COMMENT ='用户表';

-- 创建分类表
CREATE TABLE IF NOT EXISTS `categories`
(
    `id`          bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '分类ID，主键',
    `name`        varchar(255)        NOT NULL COMMENT '分类名称',
    `slug`        varchar(255)        NOT NULL COMMENT '分类别名',
    `description` text                         DEFAULT NULL COMMENT '分类描述',
    `parent_id`   bigint(20) unsigned          DEFAULT NULL COMMENT '父分类ID',
    `sort_order`  int(10) unsigned             DEFAULT 0 COMMENT '排序顺序',
    `created_at`  datetime                     DEFAULT NULL COMMENT '创建时间',
    `updated_at`  datetime                     DEFAULT NULL COMMENT '更新时间',
    `status`      tinyint(1)          NOT NULL DEFAULT 1 COMMENT '状态：1启用，0禁用',
    `deleted_at`  datetime                     DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `slug` (`slug`),
    KEY `parent_id` (`parent_id`),
    KEY `status` (`status`),
    KEY `deleted_at` (`deleted_at`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4 COMMENT ='分类表';

-- 创建文章表
CREATE TABLE IF NOT EXISTS `posts`
(
    `id`            bigint(20) unsigned                         NOT NULL AUTO_INCREMENT COMMENT '文章ID，主键',
    `title`         varchar(255)                                NOT NULL COMMENT '文章标题',
    `slug`          varchar(255)                                NOT NULL COMMENT '文章别名',
    `content_type`  enum ('markdown', 'html', 'text', 'visual') NOT NULL DEFAULT 'markdown' COMMENT '内容类型',
    `content`       longtext                                    NOT NULL COMMENT '文章内容',
    `excerpt`       text                                                 DEFAULT NULL COMMENT '文章摘要',
    `status`        enum ('draft','published','archived')       NOT NULL DEFAULT 'draft' COMMENT '文章状态',
    `featured`      tinyint(1)                                  NOT NULL DEFAULT 0 COMMENT '是否精选',
    `view_count`    int(10) unsigned                            NOT NULL DEFAULT 0 COMMENT '浏览次数',
    `comment_count` int(10) unsigned                            NOT NULL DEFAULT 0 COMMENT '评论数量',
    `published_at`  datetime                                    NULL     DEFAULT NULL COMMENT '发布时间',
    `created_at`    datetime                                    NULL     DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at`    datetime                                    NULL     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    `deleted_at`    datetime                                    NULL     DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `slug` (`slug`),
    KEY `status` (`status`),
    KEY `featured` (`featured`),
    KEY `published_at` (`published_at`),
    KEY `deleted_at` (`deleted_at`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4 COMMENT ='文章表';

-- 创建文章-分类关联表
CREATE TABLE IF NOT EXISTS `post_category`
(
    `post_id`     bigint(20) unsigned NOT NULL COMMENT '文章ID',
    `category_id` bigint(20) unsigned NOT NULL COMMENT '分类ID',
    `created_at`  datetime DEFAULT NULL COMMENT '创建时间',
    `updated_at`  datetime DEFAULT NULL COMMENT '更新时间',
    PRIMARY KEY (`post_id`, `category_id`),
    KEY `category_id` (`category_id`),
    CONSTRAINT `post_category_post_id_foreign` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `post_category_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4 COMMENT ='文章-分类关联表';

-- 创建文章-作者关联表（修改 author_id 为 int unsigned）
CREATE TABLE IF NOT EXISTS `post_author`
(
    `id`           bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
    `post_id`      bigint(20) unsigned NOT NULL COMMENT '文章ID',
    `author_id`    int unsigned        NOT NULL COMMENT '作者ID',
    `is_primary`   tinyint(1)          NOT NULL DEFAULT 0 COMMENT '是否主要作者',
    `contribution` varchar(50)                  DEFAULT NULL COMMENT '贡献类型',
    `created_at`   datetime                     DEFAULT NULL COMMENT '创建时间',
    `updated_at`   datetime                     DEFAULT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `post_author` (`post_id`, `author_id`),
    KEY `author_id` (`author_id`),
    CONSTRAINT `post_author_post_id_foreign` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `post_author_author_id_foreign` FOREIGN KEY (`author_id`) REFERENCES `wa_users` (`id`) ON DELETE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4 COMMENT ='文章-作者关联表';


-- 创建友链表
CREATE TABLE IF NOT EXISTS `links`
(
    `id`          bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '友链ID，主键',
    `name`        varchar(255)        NOT NULL COMMENT '友链名称',
    `url`         varchar(255)        NOT NULL COMMENT '友链URL',
    `description` text                         DEFAULT NULL COMMENT '友链描述',
    `image`       varchar(255)                 DEFAULT NULL COMMENT '友链图片',
    `sort_order`  int(10) unsigned             DEFAULT 0 COMMENT '排序顺序',
    `status`      boolean             NOT NULL DEFAULT true COMMENT '状态：1显示，0隐藏',
    `created_at`  datetime                     DEFAULT NULL COMMENT '创建时间',
    `updated_at`  datetime                     DEFAULT NULL COMMENT '更新时间',
    `deleted_at`  datetime                     DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`),
    KEY `status` (`status`),
    KEY `sort_order` (`sort_order`),
    KEY `deleted_at` (`deleted_at`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4 COMMENT ='友链表';

-- 创建页面表
CREATE TABLE IF NOT EXISTS `pages`
(
    `id`         bigint(20) unsigned        NOT NULL AUTO_INCREMENT COMMENT '页面ID，主键',
    `title`      varchar(255)               NOT NULL COMMENT '页面标题',
    `slug`       varchar(255)               NOT NULL COMMENT '页面别名',
    `content`    longtext                   NOT NULL COMMENT '页面内容',
    `status`     enum ('draft','published') NOT NULL DEFAULT 'draft' COMMENT '页面状态',
    `template`   varchar(50)                         DEFAULT NULL COMMENT '页面模板',
    `sort_order` int(10) unsigned                    DEFAULT 0 COMMENT '排序顺序',
    `created_at` datetime                   NULL     DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` datetime                   NULL     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    `deleted_at` datetime                   NULL     DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `slug` (`slug`),
    KEY `deleted_at` (`deleted_at`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4 COMMENT ='页面表';

-- 创建网站设置表
CREATE TABLE IF NOT EXISTS `settings`
(
    `id`         bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '设置ID，主键',
    `key`        varchar(255)        NOT NULL COMMENT '设置键名',
    `value`      longtext                 DEFAULT NULL COMMENT '设置值',
    `type`       varchar(50)              DEFAULT 'string' COMMENT '值类型',
    `group`      varchar(50)              DEFAULT 'general' COMMENT '设置分组',
    `created_at` timestamp           NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` timestamp           NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `key` (`key`),
    KEY `group` (`group`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4 COMMENT ='网站设置表';

-- 创建媒体附件表（修改 author_id 为 int unsigned）
CREATE TABLE IF NOT EXISTS `media`
(
    `id`            bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '媒体ID，主键',
    `filename`      varchar(255)        NOT NULL COMMENT '文件名',
    `original_name` varchar(255)        NOT NULL COMMENT '原始文件名',
    `file_path`     varchar(512)        NOT NULL COMMENT '文件路径',
    `thumb_path`    varchar(500)                 DEFAULT NULL COMMENT '缩略图路径',
    `file_size`     int(10) unsigned    NOT NULL DEFAULT 0 COMMENT '文件大小',
    `mime_type`     varchar(100)        NOT NULL COMMENT 'MIME类型',
    `alt_text`      varchar(255)                 DEFAULT NULL COMMENT '替代文本',
    `caption`       text                         DEFAULT NULL COMMENT '标题',
    `description`   text                         DEFAULT NULL COMMENT '描述',
    `author_id`     int unsigned                 DEFAULT NULL COMMENT '作者ID',
    `author_type`   enum ('admin','user')        DEFAULT 'user' COMMENT '作者类型',
    `created_at`    timestamp           NULL     DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at`    timestamp           NULL     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    `deleted_at`    timestamp           NULL     DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`),
    KEY `author_id` (`author_id`),
    KEY `author_type` (`author_type`),
    KEY `filename` (`filename`),
    KEY `mime_type` (`mime_type`),
    KEY `deleted_at` (`deleted_at`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4 COMMENT ='媒体附件表';

-- 创建导入任务表（修改 author_id 为 int unsigned）
CREATE TABLE IF NOT EXISTS `import_jobs`
(
    `id`           bigint(20) unsigned                                NOT NULL AUTO_INCREMENT COMMENT '任务ID，主键',
    `name`         varchar(255)                                       NOT NULL COMMENT '任务名称',
    `type`         varchar(50)                                        NOT NULL COMMENT '任务类型',
    `file_path`    varchar(512)                                       NOT NULL COMMENT '文件路径',
    `status`       enum ('pending','processing','completed','failed') NOT NULL DEFAULT 'pending' COMMENT '任务状态',
    `options`      longtext                                                    DEFAULT NULL COMMENT '导入选项',
    `progress`     int(3) unsigned                                    NOT NULL DEFAULT 0 COMMENT '导入进度 0-100',
    `message`      text                                                        DEFAULT NULL COMMENT '状态消息',
    `author_id`    int unsigned                                                DEFAULT NULL COMMENT '默认作者ID',
    `created_at`   timestamp                                          NULL     DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at`   timestamp                                          NULL     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    `completed_at` timestamp                                          NULL     DEFAULT NULL COMMENT '完成时间',
    PRIMARY KEY (`id`),
    KEY `status` (`status`),
    KEY `author_id` (`author_id`),
    CONSTRAINT `import_jobs_author_id_foreign` FOREIGN KEY (`author_id`) REFERENCES `wa_users` (`id`) ON DELETE SET NULL
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4 COMMENT ='导入任务表';

-- 创建评论表（修改 user_id 为 int unsigned）
CREATE TABLE IF NOT EXISTS `comments`
(
    `id`          bigint(20) unsigned                        NOT NULL AUTO_INCREMENT COMMENT '评论ID，主键',
    `post_id`     bigint(20) unsigned                        NOT NULL COMMENT '文章ID',
    `user_id`     int unsigned                                        DEFAULT NULL COMMENT '用户ID',
    `parent_id`   bigint(20) unsigned                                 DEFAULT NULL COMMENT '父评论ID',
    `guest_name`  varchar(255)                                        DEFAULT NULL COMMENT '访客姓名',
    `guest_email` varchar(255)                                        DEFAULT NULL COMMENT '访客邮箱',
    `content`     text                                       NOT NULL COMMENT '评论内容',
    `status`      enum ('pending','approved','spam','trash') NOT NULL DEFAULT 'pending' COMMENT '评论状态',
    `ip_address`  varchar(45)                                         DEFAULT NULL COMMENT 'IP地址',
    `user_agent`  varchar(255)                                        DEFAULT NULL COMMENT '用户代理',
    `created_at`  timestamp                                  NULL     DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at`  timestamp                                  NULL     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    `deleted_at`  timestamp                                  NULL     DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`),
    KEY `post_id` (`post_id`),
    KEY `user_id` (`user_id`),
    KEY `parent_id` (`parent_id`),
    KEY `status` (`status`),
    KEY `deleted_at` (`deleted_at`),
    CONSTRAINT `comments_post_id_foreign` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `comments_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `wa_users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `comments_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `comments` (`id`) ON DELETE SET NULL
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4 COMMENT ='评论表';

-- 创建标签表
CREATE TABLE IF NOT EXISTS `tags`
(
    `id`          bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '标签ID，主键',
    `name`        varchar(255)        NOT NULL COMMENT '标签名称',
    `slug`        varchar(255)        NOT NULL COMMENT '标签别名',
    `description` text                     DEFAULT NULL COMMENT '标签描述',
    `created_at`  timestamp           NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at`  timestamp           NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    `deleted_at`  timestamp           NULL DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `slug` (`slug`),
    KEY `deleted_at` (`deleted_at`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4 COMMENT ='标签表';

-- 创建文章-标签关联表
CREATE TABLE IF NOT EXISTS `post_tag`
(
    `post_id`    bigint(20) unsigned NOT NULL COMMENT '文章ID',
    `tag_id`     bigint(20) unsigned NOT NULL COMMENT '标签ID',
    `created_at` timestamp           NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    PRIMARY KEY (`post_id`, `tag_id`),
    KEY `tag_id` (`tag_id`),
    CONSTRAINT `post_tag_post_id_foreign` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `post_tag_tag_id_foreign` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4 COMMENT ='文章-标签关联表';

-- 添加外键约束
ALTER TABLE `categories`
    ADD CONSTRAINT `categories_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL;

SET FOREIGN_KEY_CHECKS = 1;