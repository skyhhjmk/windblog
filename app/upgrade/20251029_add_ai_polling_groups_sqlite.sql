-- AI轮询组表
CREATE TABLE IF NOT EXISTS ai_polling_groups
(
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(500)          DEFAULT NULL,
    strategy    VARCHAR(20)  NOT NULL DEFAULT 'polling' CHECK (strategy IN ('polling', 'failover')),
    enabled     INTEGER      NOT NULL DEFAULT 1,
    created_at  DATETIME              DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME              DEFAULT CURRENT_TIMESTAMP
);

-- AI轮询组提供方关系表
CREATE TABLE IF NOT EXISTS ai_polling_group_providers
(
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    group_id    INTEGER     NOT NULL,
    provider_id VARCHAR(50) NOT NULL,
    weight      INTEGER     NOT NULL DEFAULT 1,
    enabled     INTEGER     NOT NULL DEFAULT 1,
    created_at  DATETIME             DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME             DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (group_id, provider_id),
    FOREIGN KEY (group_id) REFERENCES ai_polling_groups (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_group_id ON ai_polling_group_providers (group_id);
CREATE INDEX IF NOT EXISTS idx_provider_id ON ai_polling_group_providers (provider_id);
