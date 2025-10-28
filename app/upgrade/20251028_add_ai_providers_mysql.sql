-- AI 提供方表（类似邮件多服务器设计）
CREATE TABLE IF NOT EXISTS ` ai_providers `
(
    `
    id
    `
    varchar
(
    64
) NOT NULL COMMENT '提供方唯一标识（用户自定义或自动生成）',
    ` name ` varchar
(
    255
) NOT NULL COMMENT '提供方名称',
    ` template ` varchar
(
    64
) DEFAULT NULL COMMENT '模板类型（openai/claude/azure/gemini/custom等）',
    ` type ` varchar
(
    64
) NOT NULL DEFAULT 'openai' COMMENT '提供方类型',
    ` config ` text COMMENT '配置JSON（包含api_key、base_url、model等）',
    ` weight ` int
(
    11
) NOT NULL DEFAULT 1 COMMENT '权重（用于加权选择）',
    ` enabled ` tinyint
(
    1
) NOT NULL DEFAULT 1 COMMENT '是否启用',
    ` created_at ` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    ` updated_at ` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY
(
    `
    id
    `
),
    KEY ` idx_enabled `
(
    `
    enabled
    `
),
    KEY ` idx_template `
(
    `
    template
    `
)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE =utf8mb4_unicode_ci COMMENT ='AI提供方配置表';

-- 修改轮询组提供方表，将 provider_id 改为关联 ai_providers 表
-- 注意：需要先迁移现有数据
ALTER TABLE ` ai_polling_group_providers `
    MODIFY COLUMN ` provider_id ` varchar (64) NOT NULL
COMMENT
'关联ai_providers.id';

-- 为现有的 provider_id 创建对应的 ai_providers 记录
INSERT IGNORE INTO ` ai_providers ` (` id `, ` name `, ` template `, ` type `, ` config `, ` weight `, ` enabled `)
SELECT DISTINCT ` provider_id `,
                CASE
                    WHEN ` provider_id ` = 'local.echo' THEN '本地占位提供者'
                    WHEN ` provider_id ` = 'openai' THEN 'OpenAI'
                    ELSE ` provider_id `
                    END,
                ` provider_id `,
                CASE
                    WHEN ` provider_id ` = 'local.echo' THEN 'local'
                    ELSE 'openai'
                    END,
                '{}',
                1,
                1
FROM ` ai_polling_group_providers `
WHERE ` provider_id ` IS NOT NULL;
