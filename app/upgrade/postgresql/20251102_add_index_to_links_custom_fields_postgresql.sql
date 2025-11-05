-- 为links表的custom_fields字段添加GIN索引（PostgreSQL）
-- GIN索引专门用于JSONB字段，可以大幅提高JSON查询性能

-- 为custom_fields字段创建GIN索引
CREATE INDEX IF NOT EXISTS idx_links_custom_fields_gin ON links USING GIN (custom_fields);

-- 为常用的AI审核状态字段创建表达式索引
CREATE INDEX IF NOT EXISTS idx_links_ai_audit_status ON links ((custom_fields ->> 'ai_audit_status'));

-- 为上次审核时间字段创建表达式索引
CREATE INDEX IF NOT EXISTS idx_links_last_audit_time ON links ((custom_fields ->> 'last_audit_time'));

-- 为上次监控时间字段创建表达式索引
CREATE INDEX IF NOT EXISTS idx_links_last_monitor_time ON links ((custom_fields ->> 'last_monitor_time'));
