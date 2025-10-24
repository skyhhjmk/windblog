-- SQLite 数据库升级脚本
-- 版本: 添加用户邮箱验证和OAuth字段
-- 日期: 2025-06-24
-- 说明: 为 wa_users 表添加邮箱验证、激活令牌和OAuth相关字段
-- 注意: SQLite 不支持 ADD COLUMN IF NOT EXISTS，所以需要使用另一种方式

-- 备份原表
CREATE TABLE IF NOT EXISTS wa_users_backup AS
SELECT *
FROM wa_users;

-- 检查是否已经升级（通过检查字段是否存在）
-- 如果 email_verified_at 字段存在，则跳过升级
SELECT CASE
           WHEN EXISTS (SELECT 1
                        FROM pragma_table_info('wa_users')
                        WHERE name = 'email_verified_at')
               THEN 'SKIP_UPGRADE'
           ELSE 'NEED_UPGRADE'
           END AS upgrade_status;

-- 如果需要升级，创建新表并迁移数据
-- 注意：下面的命令需要手动判断是否执行
-- 如果上面的查询返回 'SKIP_UPGRADE'，则不需要执行以下命令

-- 重命名旧表（如果需要升级）
-- ALTER TABLE wa_users RENAME TO wa_users_old;

-- 创建新表结构（如果需要升级）
-- CREATE TABLE wa_users (
--   id INTEGER PRIMARY KEY AUTOINCREMENT,
--   username TEXT NOT NULL UNIQUE,
--   nickname TEXT NOT NULL,
--   password TEXT NOT NULL,
--   sex TEXT NOT NULL DEFAULT '1',
--   avatar TEXT DEFAULT NULL,
--   email TEXT DEFAULT NULL,
--   mobile TEXT DEFAULT NULL,
--   level INTEGER NOT NULL DEFAULT 0,
--   birthday DATE DEFAULT NULL,
--   money REAL NOT NULL DEFAULT 0.00,
--   score INTEGER NOT NULL DEFAULT 0,
--   last_time DATETIME DEFAULT NULL,
--   last_ip TEXT DEFAULT NULL,
--   join_time DATETIME DEFAULT NULL,
--   join_ip TEXT DEFAULT NULL,
--   token TEXT DEFAULT NULL,
--   email_verified_at DATETIME DEFAULT NULL,
--   activation_token TEXT DEFAULT NULL,
--   activation_token_expires_at DATETIME DEFAULT NULL,
--   oauth_provider TEXT DEFAULT NULL,
--   oauth_id TEXT DEFAULT NULL,
--   created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
--   updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
--   role INTEGER NOT NULL DEFAULT 1,
--   status INTEGER NOT NULL DEFAULT 0
-- );

-- 迁移数据（如果需要升级）
-- INSERT INTO wa_users (
--     id, username, nickname, password, sex, avatar, email, mobile,
--     level, birthday, money, score, last_time, last_ip, join_time,
--     join_ip, token, created_at, updated_at, role, status
-- )
-- SELECT
--     id, username, nickname, password, sex, avatar, email, mobile,
--     level, birthday, money, score, last_time, last_ip, join_time,
--     join_ip, token, created_at, updated_at, role, status
-- FROM wa_users_old;

-- 删除旧表（如果需要升级）
-- DROP TABLE wa_users_old;

-- 创建索引（如果需要升级）
-- CREATE INDEX IF NOT EXISTS idx_wa_users_email ON wa_users(email);
-- CREATE INDEX IF NOT EXISTS idx_wa_users_activation_token ON wa_users(activation_token);
-- CREATE INDEX IF NOT EXISTS idx_wa_users_oauth ON wa_users(oauth_provider, oauth_id);

-- 说明
SELECT '=====================================================================' AS message
UNION ALL
SELECT 'SQLite 数据库升级说明：' AS message
UNION ALL
SELECT '由于 SQLite 不支持直接添加列（如果不存在），' AS message
UNION ALL
SELECT '请先检查 upgrade_status 的值：' AS message
UNION ALL
SELECT '  - 如果是 SKIP_UPGRADE，表示已经升级过，无需操作' AS message
UNION ALL
SELECT '  - 如果是 NEED_UPGRADE，请取消注释上面的 SQL 语句并执行' AS message
UNION ALL
SELECT '=====================================================================' AS message;

-- 或者使用这个简单的方法（直接尝试添加列，如果失败则忽略）
-- 注意：这种方法会在字段已存在时报错，但不会影响数据

-- ALTER TABLE wa_users ADD COLUMN email_verified_at DATETIME DEFAULT NULL;
-- ALTER TABLE wa_users ADD COLUMN activation_token TEXT DEFAULT NULL;
-- ALTER TABLE wa_users ADD COLUMN activation_token_expires_at DATETIME DEFAULT NULL;
-- ALTER TABLE wa_users ADD COLUMN oauth_provider TEXT DEFAULT NULL;
-- ALTER TABLE wa_users ADD COLUMN oauth_id TEXT DEFAULT NULL;

-- CREATE INDEX IF NOT EXISTS idx_wa_users_email ON wa_users(email);
-- CREATE INDEX IF NOT EXISTS idx_wa_users_activation_token ON wa_users(activation_token);
-- CREATE INDEX IF NOT EXISTS idx_wa_users_oauth ON wa_users(oauth_provider, oauth_id);
