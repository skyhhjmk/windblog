-- AI轮询组表
CREATE TABLE IF NOT EXISTS ` ai_polling_groups `
(
    `
    id
    `
    INT
    UNSIGNED
    NOT
    NULL
    AUTO_INCREMENT
    COMMENT
    '主键ID',
    `
    name
    `
    VARCHAR
(
    100
) NOT NULL COMMENT '轮询组名称',
    ` description ` VARCHAR
(
    500
) DEFAULT NULL COMMENT '轮询组描述',
    ` strategy ` ENUM
(
    'polling',
    'failover'
) NOT NULL DEFAULT 'polling' COMMENT '调度策略：polling=轮询, failover=主备',
    ` enabled ` TINYINT
(
    1
) NOT NULL DEFAULT 1 COMMENT '是否启用',
    ` created_at ` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    ` updated_at ` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY
(
    `
    id
    `
),
    UNIQUE KEY ` uk_name `
(
    `
    name
    `
)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE =utf8mb4_unicode_ci COMMENT ='AI轮询组表';

-- AI轮询组提供方关系表
CREATE TABLE IF NOT EXISTS ` ai_polling_group_providers `
(
    `
    id
    `
    INT
    UNSIGNED
    NOT
    NULL
    AUTO_INCREMENT
    COMMENT
    '主键ID',
    `
    group_id
    `
    INT
    UNSIGNED
    NOT
    NULL
    COMMENT
    '轮询组ID',
    `
    provider_id
    `
    VARCHAR
(
    50
) NOT NULL COMMENT '提供方ID',
    ` weight ` INT NOT NULL DEFAULT 1 COMMENT '权重（用于轮询和优先级）',
    ` enabled ` TINYINT
(
    1
) NOT NULL DEFAULT 1 COMMENT '是否启用',
    ` created_at ` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    ` updated_at ` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY
(
    `
    id
    `
),
    KEY ` idx_group_id `
(
    `
    group_id
    `
),
    KEY ` idx_provider_id `
(
    `
    provider_id
    `
),
    UNIQUE KEY ` uk_group_provider `
(
    `
    group_id
    `,
    `
    provider_id
    `
),
    CONSTRAINT ` fk_polling_group_providers_group ` FOREIGN KEY
(
    `
    group_id
    `
) REFERENCES ` ai_polling_groups `
(
    `
    id
    `
)
                                                            ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE =utf8mb4_unicode_ci COMMENT ='AI轮询组提供方关系表';
