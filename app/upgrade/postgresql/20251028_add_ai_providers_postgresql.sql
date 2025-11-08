-- AI 提供方表（类似邮件多服务器设计）
CREATE TABLE IF NOT EXISTS ai_providers
(
    id         varchar(64)  NOT NULL PRIMARY KEY,
    name       varchar(255) NOT NULL,
    template   varchar(64),
    type       varchar(64)  NOT NULL DEFAULT 'openai',
    config     text,
    weight     integer      NOT NULL DEFAULT 1,
    enabled    boolean      NOT NULL DEFAULT true,
    created_at timestamp             DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp             DEFAULT CURRENT_TIMESTAMP
);

COMMENT ON TABLE ai_providers IS 'AI提供方配置表';
COMMENT ON COLUMN ai_providers.id IS '提供方唯一标识（用户自定义或自动生成）';
COMMENT ON COLUMN ai_providers.name IS '提供方名称';
COMMENT ON COLUMN ai_providers.template IS '模板类型（openai/claude/azure/gemini/custom等）';
COMMENT ON COLUMN ai_providers.type IS '提供方类型';
COMMENT ON COLUMN ai_providers.config IS '配置JSON（包含api_key、base_url、model等）';
COMMENT ON COLUMN ai_providers.weight IS '权重（用于加权选择）';
COMMENT ON COLUMN ai_providers.enabled IS '是否启用';

CREATE INDEX IF NOT EXISTS idx_ai_providers_enabled ON ai_providers (enabled);
CREATE INDEX IF NOT EXISTS idx_ai_providers_template ON ai_providers (template);

-- 为现有的 provider_id 创建对应的 ai_providers 记录
INSERT INTO ai_providers (id, name, template, type, config, weight, enabled)
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
                true
FROM ai_polling_group_providers
WHERE provider_id IS NOT NULL
ON CONFLICT (id) DO NOTHING;
