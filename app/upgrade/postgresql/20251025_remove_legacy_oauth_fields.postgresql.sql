-- 删除 wa_users 表中的旧OAuth字段
-- 这些字段已被 user_oauth_bindings 表替代

-- 删除旧的OAuth索引（如果存在）
DROP INDEX IF EXISTS idx_oauth;

-- 删除旧的OAuth字段
ALTER TABLE wa_users
    DROP COLUMN IF EXISTS oauth_provider;
ALTER TABLE wa_users
    DROP COLUMN IF EXISTS oauth_id;
