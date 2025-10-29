-- AI 提供方表（类似邮件多服务器设计）
CREATE TABLE IF NOT EXISTS ai_providers
(
    id         TEXT PRIMARY KEY NOT NULL,
    name       TEXT             NOT NULL,
    template   TEXT,
    type       TEXT             NOT NULL DEFAULT 'openai',
    config     TEXT,
    weight     INTEGER          NOT NULL DEFAULT 1,
    enabled    INTEGER          NOT NULL DEFAULT 1,
    created_at TEXT                      DEFAULT (datetime('now')),
    updated_at TEXT                      DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_ai_providers_enabled ON ai_providers (enabled);
CREATE INDEX IF NOT EXISTS idx_ai_providers_template ON ai_providers (template);

-- 为现有的 provider_id 创建对应的 ai_providers 记录
INSERT OR IGNORE INTO ai_providers (id, name, template, type, config, weight, enabled)
SELECT DISTINCT provider_id,
                CASE
                    WHEN provider_id = 'local.echo' THEN '本地占位提供者'
                    WHEN provider_id = 'openai' THEN 'OpenAI'
                    ELSE provider_id
                    END,
                provider_id,
                CASE
                    WHEN provider_id = 'local.echo' THEN 'local'
                    ELSE 'openai'
                    END,
                '{}',
                1,
                1
FROM ai_polling_group_providers
WHERE provider_id IS NOT NULL;
