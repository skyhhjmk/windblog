-- 为评论表添加AI审核相关字段 (MySQL)
-- 执行时间：2025-10-31

ALTER TABLE ` comments `
    ADD COLUMN ` ai_moderation_result ` VARCHAR (20) NULL
COMMENT
'AI审核结果：approved/rejected/spam/pending' AFTER `status`,
ADD COLUMN `ai_moderation_reason` TEXT NULL COMMENT
'AI审核原因' AFTER `ai_moderation_result`,
ADD COLUMN `ai_moderation_confidence` DECIMAL(3,2) NULL COMMENT
'AI审核置信度(0-1)' AFTER `ai_moderation_reason`,
ADD COLUMN `ai_moderation_categories` JSON NULL COMMENT
'AI检测到的问题类别' AFTER `ai_moderation_confidence`;

-- 添加索引以优化查询
CREATE INDEX ` idx_ai_moderation_result ` ON ` comments ` (` ai_moderation_result `);

-- 添加系统配置
INSERT INTO ` settings ` (` key `, ` value `, ` group `, ` created_at `, ` updated_at `)
VALUES ('comment_ai_moderation_enabled', CAST('false' AS JSON), 'ai', NOW(), NOW()),
       ('comment_ai_moderation_model', CAST('""' AS JSON), 'ai', NOW(), NOW()),
       ('comment_ai_moderation_failure_strategy', CAST('"approve"' AS JSON), 'ai', NOW(), NOW()) ON DUPLICATE KEY
UPDATE ` value ` =
VALUES (` value `), ` updated_at ` = NOW();
