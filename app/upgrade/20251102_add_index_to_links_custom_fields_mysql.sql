-- 为links表的custom_fields字段添加索引（MySQL 8.0+支持JSON索引）
-- 这将提高基于custom_fields的查询性能

-- MySQL 8.0+ 支持为JSON字段创建函数索引
-- 为常用的AI审核状态字段创建索引
ALTER TABLE ` links ` ADD INDEX ` idx_custom_fields_ai_audit_status ` (
    (CAST (JSON_EXTRACT(` custom_fields `, '$.ai_audit_status') AS CHAR (20)))
    );

-- 为上次审核时间字段创建索引
ALTER TABLE ` links ` ADD INDEX ` idx_custom_fields_last_audit_time ` (
    (CAST (JSON_EXTRACT(` custom_fields `, '$.last_audit_time') AS CHAR (20)))
    );

-- 为上次监控时间字段创建索引
ALTER TABLE ` links ` ADD INDEX ` idx_custom_fields_last_monitor_time ` (
    (CAST (JSON_EXTRACT(` custom_fields `, '$.last_monitor_time') AS CHAR (20)))
    );
