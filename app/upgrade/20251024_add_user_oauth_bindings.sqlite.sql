-- SQLite 数据库升级脚本
-- 版本: 添加用户OAuth多平台绑定表
-- 日期: 2025-10-24
-- 说明: 创建 user_oauth_bindings 表支持用户绑定多个OAuth平台

-- 创建OAuth绑定表
CREATE TABLE IF NOT EXISTS user_oauth_bindings
(
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id           INTEGER      NOT NULL,
    provider          VARCHAR(50)  NOT NULL,
    provider_user_id  VARCHAR(255) NOT NULL,
    provider_username VARCHAR(255) DEFAULT NULL,
    provider_email    VARCHAR(255) DEFAULT NULL,
    provider_avatar   VARCHAR(500) DEFAULT NULL,
    access_token      TEXT         DEFAULT NULL,
    refresh_token     TEXT         DEFAULT NULL,
    expires_at        DATETIME     DEFAULT NULL,
    extra_data        TEXT         DEFAULT NULL,
    created_at        DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES wa_users (id) ON DELETE CASCADE
);

-- 创建唯一索引
CREATE UNIQUE INDEX IF NOT EXISTS unique_provider_user ON user_oauth_bindings (provider, provider_user_id);

-- 创建其他索引
CREATE INDEX IF NOT EXISTS idx_user_id ON user_oauth_bindings (user_id);
CREATE INDEX IF NOT EXISTS idx_provider ON user_oauth_bindings (provider);

-- 完成提示
SELECT 'User OAuth bindings table created successfully!' AS result;
