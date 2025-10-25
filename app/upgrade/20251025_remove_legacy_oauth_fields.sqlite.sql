-- 删除 wa_users 表中的旧OAuth字段
-- 这些字段已被 user_oauth_bindings 表替代
--
-- 注意：SQLite不支持DROP COLUMN，需要重建表

-- 1. 创建新表（不包含oauth_provider和oauth_id字段）
CREATE TABLE IF NOT EXISTS wa_users_new
(
    id                          INTEGER PRIMARY KEY AUTOINCREMENT,
    username                    TEXT NOT NULL UNIQUE,
    nickname                    TEXT NOT NULL,
    password                    TEXT NOT NULL,
    sex                         TEXT    DEFAULT '1' CHECK (sex IN ('0', '1')),
    avatar                      TEXT,
    email                       TEXT,
    mobile                      TEXT,
    level                       INTEGER DEFAULT 0,
    birthday                    TEXT,
    money                       REAL    DEFAULT 0.00,
    score                       INTEGER DEFAULT 0,
    last_time                   TEXT,
    last_ip                     TEXT,
    join_time                   TEXT,
    join_ip                     TEXT,
    token                       TEXT,
    email_verified_at           TEXT,
    activation_token            TEXT,
    activation_token_expires_at TEXT,
    created_at                  TEXT,
    updated_at                  TEXT,
    role                        INTEGER DEFAULT 1,
    status                      INTEGER DEFAULT 0
);

-- 2. 复制数据（不包含oauth_provider和oauth_id）
INSERT INTO wa_users_new (id, username, nickname, password, sex, avatar, email, mobile, level,
                          birthday, money, score, last_time, last_ip, join_time, join_ip, token,
                          email_verified_at, activation_token, activation_token_expires_at,
                          created_at, updated_at, role, status)
SELECT id,
       username,
       nickname,
       password,
       sex,
       avatar,
       email,
       mobile,
       level,
       birthday,
       money,
       score,
       last_time,
       last_ip,
       join_time,
       join_ip,
       token,
       email_verified_at,
       activation_token,
       activation_token_expires_at,
       created_at,
       updated_at,
       role,
       status
FROM wa_users;

-- 3. 删除旧表
DROP TABLE wa_users;

-- 4. 重命名新表
ALTER TABLE wa_users_new
    RENAME TO wa_users;

-- 5. 重建索引
CREATE INDEX IF NOT EXISTS idx_join_time ON wa_users (join_time);
CREATE INDEX IF NOT EXISTS idx_mobile ON wa_users (mobile);
CREATE INDEX IF NOT EXISTS idx_email ON wa_users (email);
CREATE INDEX IF NOT EXISTS idx_activation_token ON wa_users (activation_token);
