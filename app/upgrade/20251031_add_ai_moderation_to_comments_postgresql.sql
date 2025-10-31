-- 为评论表添加AI审核相关字段 (PostgreSQL)
-- 执行时间：2025-10-31

ALTER TABLE comments
    ADD COLUMN ai_moderation_result     VARCHAR(20)   NULL,
    ADD COLUMN ai_moderation_reason     TEXT          NULL,
    ADD COLUMN ai_moderation_confidence DECIMAL(3, 2) NULL,
    ADD COLUMN ai_moderation_categories JSONB         NULL;

COMMENT ON COLUMN comments.ai_moderation_result IS 'AI审核结果：approved/rejected/spam/pending';
COMMENT ON COLUMN comments.ai_moderation_reason IS 'AI审核原因';
COMMENT ON COLUMN comments.ai_moderation_confidence IS 'AI审核置信度(0-1)';
COMMENT ON COLUMN comments.ai_moderation_categories IS 'AI检测到的问题类别';

-- 添加索引以优化查询
CREATE INDEX idx_ai_moderation_result ON comments (ai_moderation_result);

-- 添加系统配置
INSERT INTO settings (key, value, "group", created_at, updated_at)
VALUES ('comment_ai_moderation_enabled', 'false'::jsonb, 'ai', NOW(), NOW()),
       ('comment_ai_moderation_model', '""'::jsonb, 'ai', NOW(), NOW()),
       ('comment_ai_moderation_failure_strategy', '"approve"'::jsonb, 'ai', NOW(), NOW())
ON CONFLICT (key) DO UPDATE SET value      = EXCLUDED.value,
                                updated_at = NOW();
