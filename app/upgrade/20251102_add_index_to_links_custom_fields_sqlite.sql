-- 为links表的custom_fields字段添加索引（SQLite）
-- SQLite 3.9.0+ 支持JSON函数，但不支持GIN索引
-- 使用表达式索引来优化常用的JSON字段查询

-- 为AI审核状态字段创建表达式索引
CREATE INDEX IF NOT EXISTS idx_links_ai_audit_status ON links (json_extract(custom_fields, '$.ai_audit_status'));

-- 为上次审核时间字段创建表达式索引
CREATE INDEX IF NOT EXISTS idx_links_last_audit_time ON links (json_extract(custom_fields, '$.last_audit_time'));

-- 为上次监控时间字段创建表达式索引
CREATE INDEX IF NOT EXISTS idx_links_last_monitor_time ON links (json_extract(custom_fields, '$.last_monitor_time'));
