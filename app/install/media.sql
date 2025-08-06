-- 媒体文件表
CREATE TABLE `media` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `filename` varchar(255) NOT NULL COMMENT '文件名',
  `original_name` varchar(255) NOT NULL COMMENT '原始文件名',
  `file_path` varchar(500) NOT NULL COMMENT '文件路径',
  `thumb_path` varchar(500) DEFAULT NULL COMMENT '缩略图路径',
  `file_size` int(11) NOT NULL DEFAULT '0' COMMENT '文件大小(字节)',
  `mime_type` varchar(100) NOT NULL COMMENT 'MIME类型',
  `alt_text` varchar(255) DEFAULT NULL COMMENT '替代文本',
  `caption` varchar(500) DEFAULT NULL COMMENT '说明文字',
  `description` text COMMENT '描述',
  `author_id` int(11) NOT NULL DEFAULT '0' COMMENT '作者ID',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `author_id` (`author_id`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='媒体文件表';

-- 创建导入任务表
CREATE TABLE IF NOT EXISTS `import_jobs`
(
    `id`          bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    `name`        varchar(255)        NOT NULL,
    `type`        varchar(50)         NOT NULL, -- wordpress_xml, etc.
    `file_path`   varchar(512)        NOT NULL,
    `status`      enum ('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
    `options`     text                DEFAULT NULL, -- JSON格式的导入选项
    `progress`    int(3) unsigned     NOT NULL DEFAULT 0, -- 导入进度 0-100
    `message`     text                DEFAULT NULL, -- 状态消息
    `author_id`   bigint(20) unsigned DEFAULT NULL, -- 默认作者ID
    `created_at`  timestamp           NULL DEFAULT NULL,
    `updated_at`  timestamp           NULL DEFAULT NULL,
    `completed_at` timestamp          NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `status` (`status`),
    KEY `author_id` (`author_id`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4;