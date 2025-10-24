-- MySQL 数据库升级脚本
-- 版本: 添加用户OAuth多平台绑定表
-- 日期: 2025-10-24
-- 说明: 创建 user_oauth_bindings 表支持用户绑定多个OAuth平台

-- 创建OAuth绑定表
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

-- 完成提示
SELECT 'User OAuth bindings table created successfully!' AS result;
