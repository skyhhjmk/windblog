-- AI轮询组表
CREATE TABLE IF NOT EXISTS ai_polling_groups
(
    id          SERIAL PRIMARY KEY,
    name        VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(500)          DEFAULT NULL,
    strategy    VARCHAR(20)  NOT NULL DEFAULT 'polling' CHECK (strategy IN ('polling', 'failover')),
    enabled     BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at  TIMESTAMP             DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP             DEFAULT CURRENT_TIMESTAMP
);

COMMENT ON TABLE ai_polling_groups IS 'AI轮询组表';
COMMENT ON COLUMN ai_polling_groups.id IS '主键ID';
COMMENT ON COLUMN ai_polling_groups.name IS '轮询组名称';
COMMENT ON COLUMN ai_polling_groups.description IS '轮询组描述';
COMMENT ON COLUMN ai_polling_groups.strategy IS '调度策略：polling=轮询, failover=主备';
COMMENT ON COLUMN ai_polling_groups.enabled IS '是否启用';
COMMENT ON COLUMN ai_polling_groups.created_at IS '创建时间';
COMMENT ON COLUMN ai_polling_groups.updated_at IS '更新时间';

-- AI轮询组提供方关系表
CREATE TABLE IF NOT EXISTS ai_polling_group_providers
(
    id          SERIAL PRIMARY KEY,
    group_id    INTEGER     NOT NULL REFERENCES ai_polling_groups (id) ON DELETE CASCADE,
    provider_id VARCHAR(50) NOT NULL,
    weight      INTEGER     NOT NULL DEFAULT 1,
    enabled     BOOLEAN     NOT NULL DEFAULT TRUE,
    created_at  TIMESTAMP            DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP            DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (group_id, provider_id)
);

CREATE INDEX IF NOT EXISTS idx_group_id ON ai_polling_group_providers (group_id);
CREATE INDEX IF NOT EXISTS idx_provider_id ON ai_polling_group_providers (provider_id);

COMMENT ON TABLE ai_polling_group_providers IS 'AI轮询组提供方关系表';
COMMENT ON COLUMN ai_polling_group_providers.id IS '主键ID';
COMMENT ON COLUMN ai_polling_group_providers.group_id IS '轮询组ID';
COMMENT ON COLUMN ai_polling_group_providers.provider_id IS '提供方ID';
COMMENT ON COLUMN ai_polling_group_providers.weight IS '权重（用于轮询和优先级）';
COMMENT ON COLUMN ai_polling_group_providers.enabled IS '是否启用';
COMMENT ON COLUMN ai_polling_group_providers.created_at IS '创建时间';
COMMENT ON COLUMN ai_polling_group_providers.updated_at IS '更新时间';
