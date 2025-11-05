-- PostgreSQL 数据库升级脚本
-- 版本: 添加用户OAuth多平台绑定表
-- 日期: 2025-10-24
-- 说明: 创建 user_oauth_bindings 表支持用户绑定多个OAuth平台

-- 创建OAuth绑定表
CREATE TABLE IF NOT EXISTS user_oauth_bindings
(
    id                BIGSERIAL PRIMARY KEY,
    user_id           INTEGER      NOT NULL,
    provider          VARCHAR(50)  NOT NULL,
    provider_user_id  VARCHAR(255) NOT NULL,
    provider_username VARCHAR(255) DEFAULT NULL,
    provider_email    VARCHAR(255) DEFAULT NULL,
    provider_avatar   VARCHAR(500) DEFAULT NULL,
    access_token      TEXT         DEFAULT NULL,
    refresh_token     TEXT         DEFAULT NULL,
    expires_at        TIMESTAMP    DEFAULT NULL,
    extra_data        JSONB        DEFAULT NULL,
    created_at        TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT unique_provider_user UNIQUE (provider, provider_user_id),
    CONSTRAINT user_oauth_bindings_user_id_foreign FOREIGN KEY (user_id) REFERENCES wa_users (id) ON DELETE CASCADE
);

-- 创建索引
CREATE INDEX IF NOT EXISTS idx_user_id ON user_oauth_bindings (user_id);
CREATE INDEX IF NOT EXISTS idx_provider ON user_oauth_bindings (provider);

-- 添加注释
COMMENT ON TABLE user_oauth_bindings IS '用户OAuth绑定表';
COMMENT ON COLUMN user_oauth_bindings.id IS '主键';
COMMENT ON COLUMN user_oauth_bindings.user_id IS '用户ID';
COMMENT ON COLUMN user_oauth_bindings.provider IS 'OAuth提供商(github, google, wechat等)';
COMMENT ON COLUMN user_oauth_bindings.provider_user_id IS 'OAuth提供商的用户ID';
COMMENT ON COLUMN user_oauth_bindings.provider_username IS 'OAuth提供商的用户名';
COMMENT ON COLUMN user_oauth_bindings.provider_email IS 'OAuth提供商的邮箱';
COMMENT ON COLUMN user_oauth_bindings.provider_avatar IS 'OAuth提供商的头像URL';
COMMENT ON COLUMN user_oauth_bindings.access_token IS '访问令牌(加密存储)';
COMMENT ON COLUMN user_oauth_bindings.refresh_token IS '刷新令牌(加密存储)';
COMMENT ON COLUMN user_oauth_bindings.expires_at IS '令牌过期时间';
COMMENT ON COLUMN user_oauth_bindings.extra_data IS '额外数据(JSON格式)';
COMMENT ON COLUMN user_oauth_bindings.created_at IS '绑定时间';
COMMENT ON COLUMN user_oauth_bindings.updated_at IS '更新时间';

-- 完成提示
SELECT 'User OAuth bindings table created successfully!' AS result;
