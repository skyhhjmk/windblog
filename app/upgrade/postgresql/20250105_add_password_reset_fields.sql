-- 添加密码重置字段到用户表
ALTER TABLE wa_users
    ADD COLUMN password_reset_token  VARCHAR(255) DEFAULT NULL,
    ADD COLUMN password_reset_expire TIMESTAMP    DEFAULT NULL;

-- 添加注释
COMMENT ON COLUMN wa_users.password_reset_token IS '密码重置令牌';
COMMENT ON COLUMN wa_users.password_reset_expire IS '密码重置令牌过期时间';

-- 添加索引以提高查询性能
CREATE INDEX idx_password_reset_token ON wa_users (password_reset_token);
