-- 添加密码重置字段到用户表
ALTER TABLE ` wa_users `
    ADD COLUMN ` password_reset_token ` VARCHAR (255) DEFAULT NULL
COMMENT
'密码重置令牌',
ADD COLUMN `password_reset_expire` DATETIME DEFAULT NULL COMMENT
'密码重置令牌过期时间';

-- 添加索引以提高查询性能
CREATE INDEX ` idx_password_reset_token ` ON ` wa_users `(` password_reset_token `);
