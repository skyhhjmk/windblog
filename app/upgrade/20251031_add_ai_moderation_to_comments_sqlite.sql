-- 为评论表添加AI审核相关字段 (SQLite)
-- 执行时间：2025-10-31

ALTER TABLE comments
    ADD COLUMN ai_moderation_result VARCHAR(20) NULL;
ALTER TABLE comments
    ADD COLUMN ai_moderation_reason TEXT NULL;
ALTER TABLE comments
    ADD COLUMN ai_moderation_confidence DECIMAL(3, 2) NULL;
ALTER TABLE comments
    ADD COLUMN ai_moderation_categories TEXT NULL;

-- 添加索引以优化查询
CREATE INDEX idx_ai_moderation_result ON comments (ai_moderation_result);

-- 添加系统配置
INSERT OR
REPLACE INTO settings (key, value, "group", created_at, updated_at)
VALUES ('comment_ai_moderation_enabled', '0', 'ai', datetime('now'), datetime('now')),
       ('comment_ai_moderation_model', '', 'ai', datetime('now'), datetime('now')),
       ('comment_ai_moderation_failure_strategy', 'approve', 'ai', datetime('now'), datetime('now'));
