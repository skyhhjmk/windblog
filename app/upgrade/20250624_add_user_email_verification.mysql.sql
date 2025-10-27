-- MySQL 数据库升级脚本
-- 版本: 添加用户邮箱验证和OAuth字段
-- 日期: 2025-06-24
-- 说明: 为 wa_users 表添加邮箱验证、激活令牌和OAuth相关字段

-- 检查并添加 email_verified_at 字段
SET @dbname = DATABASE ();
SET @tablename = 'wa_users';
SET @columnname = 'email_verified_at';
SET @preparedStatement = (SELECT IF (
    (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
    (table_name = @tablename)
    AND (table_schema = @dbname)
    AND (column_name = @columnname)
    ) > 0,
    "SELECT 'Column email_verified_at already exists.' AS msg;",
    "ALTER TABLE wa_users ADD COLUMN email_verified_at datetime DEFAULT NULL COMMENT '邮箱验证时间' AFTER token;"
    ));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 检查并添加 activation_token 字段
SET @columnname = 'activation_token';
SET @preparedStatement = (SELECT IF (
    (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
    (table_name = @tablename)
    AND (table_schema = @dbname)
    AND (column_name = @columnname)
    ) > 0,
    "SELECT 'Column activation_token already exists.' AS msg;",
    "ALTER TABLE wa_users ADD COLUMN activation_token varchar(64) DEFAULT NULL COMMENT '激活令牌' AFTER email_verified_at;"
    ));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 检查并添加 activation_token_expires_at 字段
SET @columnname = 'activation_token_expires_at';
SET @preparedStatement = (SELECT IF (
    (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
    (table_name = @tablename)
    AND (table_schema = @dbname)
    AND (column_name = @columnname)
    ) > 0,
    "SELECT 'Column activation_token_expires_at already exists.' AS msg;",
    "ALTER TABLE wa_users ADD COLUMN activation_token_expires_at datetime DEFAULT NULL COMMENT '激活令牌过期时间' AFTER activation_token;"
    ));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 检查并添加 oauth_provider 字段
SET @columnname = 'oauth_provider';
SET @preparedStatement = (SELECT IF (
    (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
    (table_name = @tablename)
    AND (table_schema = @dbname)
    AND (column_name = @columnname)
    ) > 0,
    "SELECT 'Column oauth_provider already exists.' AS msg;",
    "ALTER TABLE wa_users ADD COLUMN oauth_provider varchar(50) DEFAULT NULL COMMENT 'OAuth提供商(预留)' AFTER activation_token_expires_at;"
    ));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 检查并添加 oauth_id 字段
SET @columnname = 'oauth_id';
SET @preparedStatement = (SELECT IF (
    (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
    (table_name = @tablename)
    AND (table_schema = @dbname)
    AND (column_name = @columnname)
    ) > 0,
    "SELECT 'Column oauth_id already exists.' AS msg;",
    "ALTER TABLE wa_users ADD COLUMN oauth_id varchar(255) DEFAULT NULL COMMENT 'OAuth用户ID(预留)' AFTER oauth_provider;"
    ));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 检查并添加索引
SET @indexname = 'idx_activation_token';
SET @preparedStatement = (SELECT IF (
    (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE
    (table_name = @tablename)
    AND (table_schema = @dbname)
    AND (index_name = @indexname)
    ) > 0,
    "SELECT 'Index idx_activation_token already exists.' AS msg;",
    "ALTER TABLE wa_users ADD INDEX idx_activation_token (activation_token);"
    ));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 检查并添加组合索引
SET @indexname = 'idx_oauth';
SET @preparedStatement = (SELECT IF (
    (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE
    (table_name = @tablename)
    AND (table_schema = @dbname)
    AND (index_name = @indexname)
    ) > 0,
    "SELECT 'Index idx_oauth already exists.' AS msg;",
    "ALTER TABLE wa_users ADD INDEX idx_oauth (oauth_provider, oauth_id);"
    ));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 完成提示
SELECT 'User email verification and OAuth fields upgrade completed successfully!' AS result;
